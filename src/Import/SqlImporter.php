<?php
declare(strict_types=1);
namespace SchemaGuard\Import;

use SchemaGuard\Model\{Cardinality, Column, Id, Index, Project, RefAction, Relationship, Table};

/** Le SQL (CREATE TABLE, ALTER TABLE ... ADD, CREATE INDEX) e monta o modelo. */
final class SqlImporter
{
    private const TYPES = [
        'int' => 'integer', 'int4' => 'integer', 'integer' => 'integer', 'serial' => 'integer',
        'int8' => 'bigint', 'bigint' => 'bigint', 'bigserial' => 'bigint',
        'int2' => 'smallint', 'smallint' => 'smallint', 'smallserial' => 'smallint',
        'bool' => 'boolean', 'boolean' => 'boolean',
        'character varying' => 'varchar', 'character' => 'char',
        'double precision' => 'double', 'float8' => 'double', 'float4' => 'real',
        'numeric' => 'decimal', 'decimal' => 'decimal',
    ];
    private const SERIAL = ['serial', 'bigserial', 'smallserial'];
    private const STOP = 'NOT\s+NULL|NULL|PRIMARY\s+KEY|UNIQUE|DEFAULT|REFERENCES|AUTO_INCREMENT|AUTOINCREMENT|GENERATED|CHECK|COLLATE|COMMENT|CONSTRAINT';

    /** @var array<string, Table> */
    private array $tables = [];
    private array $refs = [];

    public function import(string $sql, string $name = 'Imported', string $dialect = 'postgresql'): Project
    {
        $this->tables = [];
        $this->refs = [];
        $project = new Project(name: $name, dialect: $dialect);
        $sql = preg_replace('~--[^\n]*|/\*.*?\*/~s', '', $sql);

        foreach ($this->split($sql, ';') as $stmt) {
            $stmt = trim($stmt);
            if (preg_match('/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?/i', $stmt, $m)) {
                $this->createTable($project, substr($stmt, strlen($m[0])));
            } elseif (preg_match('/^ALTER\s+TABLE\s+(?:ONLY\s+)?(?:IF\s+EXISTS\s+)?(\S+)\s+ADD\s+(.*)$/is', $stmt, $m)) {
                if ($t = $this->lookup($m[1])) $this->constraint($t, trim($m[2]));
            } elseif (preg_match('/^CREATE\s+(UNIQUE\s+)?INDEX\s+(?:CONCURRENTLY\s+)?(?:IF\s+NOT\s+EXISTS\s+)?(\S+)\s+ON\s+(?:ONLY\s+)?(\S+?)\s*(?:USING\s+(\w+)\s*)?\((.*)\)/is', $stmt, $m)) {
                if ($t = $this->lookup($m[3])) {
                    $this->addIndex($t, $this->unquote($m[2]), $m[5], ($m[1] ?? '') !== '', ($m[4] ?? '') !== '' ? $m[4] : null);
                }
            }
        }
        $this->resolveRefs($project);
        return $project;
    }

    private function createTable(Project $project, string $rest): void
    {
        $open = strpos($rest, '(');
        if ($open === false) return;
        $close = $this->matchingParen($rest, $open);
        [$schema, $name] = $this->parseName(substr($rest, 0, $open));
        $table = $project->addTable(Table::create($name, $schema));
        $this->tables[strtolower(($schema ? "$schema." : '') . $name)] = $table;
        $this->tables[strtolower($name)] = $table;

        foreach ($this->split(substr($rest, $open + 1, $close - $open - 1), ',') as $def) {
            $def = trim($def);
            if ($def === '') continue;
            if (preg_match('/^(CONSTRAINT\s+\S+\s+)?(PRIMARY\s+KEY|UNIQUE|FOREIGN\s+KEY|CHECK|KEY|INDEX)\b/i', $def)) {
                $this->constraint($table, $def);
            } else {
                $this->column($table, $def);
            }
        }
    }

    private function column(Table $t, string $def): void
    {
        if (!preg_match('/^("[^"]+"|`[^`]+`|\[[^\]]+\]|\w+)\s+(.*)$/s', $def, $m)) return;
        $name = $this->unquote($m[1]);
        $rest = $m[2];
        preg_match('/^(.*?)(?=\s+(?:' . self::STOP . ')\b|$)/is', $rest, $tm);
        $typeText = trim($tm[1] ?? '');
        $options = ['rawType' => $typeText];
        $serial = false;
        $type = strtolower($typeText);

        if (preg_match('/^(character\s+varying|double\s+precision|[A-Za-z_]\w*)\s*(?:\(([^)]*)\))?/i', $typeText, $tp)) {
            $key = strtolower(preg_replace('/\s+/', ' ', $tp[1]));
            $type = self::TYPES[$key] ?? $key;
            $serial = in_array($key, self::SERIAL, true);
            $args = $tp[2] ?? '';
            if (preg_match('/^\s*\d+(\s*,\s*\d+)?\s*$/', $args)) {
                $n = array_map('intval', explode(',', $args));
                if (count($n) > 1 || $type === 'decimal') {
                    $options['precision'] = $n[0];
                    $options['scale'] = $n[1] ?? null;
                } else {
                    $options['length'] = $n[0];
                }
            }
        }

        $pk = preg_match('/\bPRIMARY\s+KEY\b/i', $rest) === 1;
        $options += [
            'nullable' => !($pk || preg_match('/\bNOT\s+NULL\b/i', $rest) === 1),
            'primaryKey' => $pk,
            'unique' => preg_match('/\bUNIQUE\b/i', $rest) === 1,
            'autoIncrement' => $serial || preg_match('/\bAUTO_?INCREMENT\b|\bGENERATED\b.*\bIDENTITY\b/i', $rest) === 1,
        ];
        if (preg_match('/\bDEFAULT\s+(\'(?:[^\']|\'\')*\'|\((?:[^()]|\([^()]*\))*\)|[^\s,]+(?:\([^)]*\))?)/i', $rest, $d)) {
            $options['default'] = $d[1];
        }
        $t->addColumn(Column::create($name, $type, $options));

        if (preg_match('/\bREFERENCES\s+(\S+?)\s*\(([^)]*)\)(.*)$/is', $rest, $r)) {
            $this->refs[] = [$t, [$name], $r[1], $this->names($r[2]), $r[3], null];
        }
    }

    private function constraint(Table $t, string $def): void
    {
        $cname = null;
        if (preg_match('/^CONSTRAINT\s+(\S+)\s+/i', $def, $m)) {
            $cname = $this->unquote($m[1]);
            $def = substr($def, strlen($m[0]));
        }
        if (preg_match('/^PRIMARY\s+KEY\s*\(([^)]*)\)/i', $def, $m)) {
            foreach ($this->names($m[1]) as $n) {
                if ($c = $this->findColumn($t, $n)) { $c->primaryKey = true; $c->nullable = false; }
            }
        } elseif (preg_match('/^UNIQUE(?:\s+(?:KEY|INDEX))?\s*([^\s(]+)?\s*\(([^)]*)\)/i', $def, $m)) {
            $names = $this->names($m[2]);
            $c = count($names) === 1 ? $this->findColumn($t, $names[0]) : null;
            if ($c) $c->unique = true;
            else $this->addIndex($t, ($m[1] ?? '') !== '' ? $this->unquote($m[1]) : $cname, $m[2], true, null);
        } elseif (preg_match('/^FOREIGN\s+KEY\s*\(([^)]*)\)\s*REFERENCES\s+(\S+?)\s*\(([^)]*)\)(.*)$/is', $def, $m)) {
            $this->refs[] = [$t, $this->names($m[1]), $m[2], $this->names($m[3]), $m[4], $cname];
        } elseif (preg_match('/^(?:KEY|INDEX)\s*([^\s(]+)?\s*\(([^)]*)\)/i', $def, $m)) {
            $this->addIndex($t, ($m[1] ?? '') !== '' ? $this->unquote($m[1]) : null, $m[2], false, null);
        }
    }

    private function addIndex(Table $t, ?string $name, string $cols, bool $unique, ?string $method): void
    {
        $ids = [];
        $expr = null;
        foreach ($this->split($cols, ',') as $part) {
            $part = trim($part);
            $c = preg_match('/^("[^"]+"|`[^`]+`|\w+)(?:\s*\(\d+\))?(?:\s+(?:ASC|DESC))?$/i', $part, $m)
                ? $this->findColumn($t, $m[1]) : null;
            if ($c) { $ids[] = $c->id; continue; }
            $ids = [];
            $expr = trim($cols);
            break;
        }
        $t->indexes[] = new Index(Id::generate('idx'), $name, $ids, $expr, $unique, $method);
    }

    private function resolveRefs(Project $project): void
    {
        foreach ($this->refs as [$from, $fromNames, $toRaw, $toNames, $tail, $name]) {
            $to = $this->lookup($toRaw);
            if (!$to) continue;
            $fromCols = array_map(fn($n) => $this->findColumn($from, $n), $fromNames);
            $toCols = array_map(fn($n) => $this->findColumn($to, $n), $toNames);
            if (!$fromCols || in_array(null, $fromCols, true) || in_array(null, $toCols, true)) continue;

            $pkCount = count(array_filter($from->columns, fn($c) => $c->primaryKey));
            $one = count($fromCols) === 1 && ($fromCols[0]->unique || ($fromCols[0]->primaryKey && $pkCount === 1));

            $project->relationships[] = new Relationship(
                Id::generate('ref'), $from->id, array_map(fn($c) => $c->id, $fromCols),
                $to->id, array_map(fn($c) => $c->id, $toCols),
                $one ? Cardinality::OneToOne : Cardinality::ManyToOne,
                $this->action($tail, 'DELETE'), $this->action($tail, 'UPDATE'), $name,
            );
        }
    }

    private function action(string $tail, string $on): RefAction
    {
        if (!preg_match('/ON\s+' . $on . '\s+(CASCADE|RESTRICT|SET\s+NULL|SET\s+DEFAULT|NO\s+ACTION)/i', $tail, $m)) {
            return RefAction::NoAction;
        }
        return match (strtoupper(preg_replace('/\s+/', ' ', $m[1]))) {
            'CASCADE' => RefAction::Cascade,
            'RESTRICT' => RefAction::Restrict,
            'SET NULL' => RefAction::SetNull,
            'SET DEFAULT' => RefAction::SetDefault,
            default => RefAction::NoAction,
        };
    }

    // ---- utilitarios ----

    /** @return array{0:?string,1:string} [schema, nome] */
    private function parseName(string $raw): array
    {
        preg_match_all('/"[^"]+"|`[^`]+`|\[[^\]]+\]|[^.\s]+/', trim($raw), $m);
        $parts = array_map([$this, 'unquote'], $m[0]);
        $name = array_pop($parts) ?? '';
        return [array_pop($parts), $name];
    }

    private function unquote(string $s): string
    {
        return trim($s, "\"`[] \t\n");
    }

    /** @return string[] */
    private function names(string $csv): array
    {
        return array_map(
            fn($p) => preg_replace('/\(.*$/', '', $this->unquote(preg_split('/\s+/', trim($p))[0])),
            $this->split($csv, ',')
        );
    }

    private function lookup(string $raw): ?Table
    {
        [$schema, $name] = $this->parseName($raw);
        return $this->tables[strtolower(($schema ? "$schema." : '') . $name)] ?? $this->tables[strtolower($name)] ?? null;
    }

    private function findColumn(Table $t, string $name): ?Column
    {
        $name = $this->unquote($name);
        foreach ($t->columns as $c) {
            if (strcasecmp($c->name, $name) === 0) return $c;
        }
        return null;
    }

    /** Divide por $delim ignorando o que estiver dentro de parenteses ou aspas. */
    private function split(string $s, string $delim): array
    {
        $parts = [];
        $buf = '';
        $depth = 0;
        $quote = null;
        for ($i = 0, $n = strlen($s); $i < $n; $i++) {
            $ch = $s[$i];
            if ($quote !== null) { $buf .= $ch; if ($ch === $quote) $quote = null; continue; }
            if ($ch === "'" || $ch === '"' || $ch === '`') { $quote = $ch; $buf .= $ch; continue; }
            if ($ch === '(') $depth++;
            elseif ($ch === ')') $depth--;
            if ($ch === $delim && $depth === 0) { $parts[] = $buf; $buf = ''; continue; }
            $buf .= $ch;
        }
        if (trim($buf) !== '') $parts[] = $buf;
        return $parts;
    }

    private function matchingParen(string $s, int $open): int
    {
        $depth = 0;
        $quote = null;
        for ($i = $open, $n = strlen($s); $i < $n; $i++) {
            $ch = $s[$i];
            if ($quote !== null) { if ($ch === $quote) $quote = null; continue; }
            if ($ch === "'" || $ch === '"' || $ch === '`') { $quote = $ch; continue; }
            if ($ch === '(') $depth++;
            elseif ($ch === ')' && --$depth === 0) return $i;
        }
        return $n;
    }
}
