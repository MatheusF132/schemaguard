<?php
declare(strict_types=1);
namespace SchemaGuard\Sql;

use SchemaGuard\Diff\SchemaDiffer;
use SchemaGuard\Model\{Column, Index, Project, RefAction, Relationship, Table};

/** Transforma as operacoes do SchemaDiffer em SQL (PostgreSQL ou MySQL). So gera texto: nunca executa nada. */
final class SqlGenerator
{
    public static function migration(array $ops, string $d = 'postgresql'): string
    {
        $out = [];
        foreach ($ops as $o) {
            $out[] = match ($o['op']) {
                'table_added' => self::create($o['table'], $d),
                'table_removed' => 'DROP TABLE ' . self::tn($o['table'], $d) . ';',
                'table_renamed' => 'ALTER TABLE ' . self::tn($o['old'], $d) . ' RENAME TO ' . self::q($o['table']->name, $d) . ';',
                'column_added' => 'ALTER TABLE ' . self::tn($o['table'], $d) . ' ADD COLUMN ' . self::colDef($o['column'], $d) . ';',
                'column_removed' => 'ALTER TABLE ' . self::tn($o['table'], $d) . ' DROP COLUMN ' . self::q($o['old']->name, $d) . ';',
                'column_renamed' => 'ALTER TABLE ' . self::tn($o['table'], $d) . ' RENAME COLUMN ' . self::q($o['old']->name, $d) . ' TO ' . self::q($o['column']->name, $d) . ';',
                'column_changed' => self::changed($o, $d),
                'pk_changed' => self::pk($o, $d),
                'unique_changed' => self::unique($o, $d),
                'index_added' => self::indexAdd($o['table'], $o['index'], $d),
                'index_removed' => self::indexDrop($o['table'], $o['index'], $d),
                'fk_removed' => self::fkDrop($o['project'], $o['rel'], $d),
                'fk_added' => self::fkAdd($o['project'], $o['rel'], $d),
            };
        }
        return $out ? "-- Gerado pelo SchemaGuard. Revise antes de executar.\n\n" . implode("\n\n", $out) . "\n" : '';
    }

    private static function create(Table $t, string $d): string
    {
        $lines = array_map(fn($c) => '  ' . self::colDef($c, $d), $t->columns);
        $pk = array_map(fn($c) => self::q($c->name, $d), array_values(array_filter($t->columns, fn($c) => $c->primaryKey)));
        if ($pk) $lines[] = '  PRIMARY KEY (' . implode(', ', $pk) . ')';
        return 'CREATE TABLE ' . self::tn($t, $d) . " (\n" . implode(",\n", $lines) . "\n);";
    }

    private static function changed(array $o, string $d): string
    {
        $t = self::tn($o['table'], $d);
        $c = $o['column'];
        $q = self::q($c->name, $d);
        if ($d === 'mysql') { // MySQL pede a definicao completa da coluna
            return "ALTER TABLE $t MODIFY COLUMN " . self::colDef($c, $d, false) . ';';
        }
        $sql = [];
        foreach ($o['what'] as $w) {
            $sql[] = match ($w) {
                'type' => "ALTER TABLE $t ALTER COLUMN $q TYPE " . self::type($c, $d, false) . ';',
                'nullable' => "ALTER TABLE $t ALTER COLUMN $q " . ($c->nullable ? 'DROP' : 'SET') . ' NOT NULL;',
                'default' => "ALTER TABLE $t ALTER COLUMN $q " . ($c->default === null ? 'DROP DEFAULT' : "SET DEFAULT {$c->default}") . ';',
            };
        }
        return implode("\n", $sql);
    }

    private static function pk(array $o, string $d): string
    {
        $t = self::tn($o['table'], $d);
        $sql = [];
        if ($o['hadPk']) {
            $sql[] = '-- Atenção: relacionamentos que usam a chave primária antiga precisam ser recriados antes.';
            $sql[] = $d === 'mysql' ? "ALTER TABLE $t DROP PRIMARY KEY;" : "ALTER TABLE $t DROP CONSTRAINT " . self::q($o['old']->name . '_pkey', $d) . ';';
        }
        $cols = array_map(fn($c) => self::q($c->name, $d), array_values(array_filter($o['table']->columns, fn($c) => $c->primaryKey)));
        if ($cols) $sql[] = "ALTER TABLE $t ADD PRIMARY KEY (" . implode(', ', $cols) . ');';
        return implode("\n", $sql);
    }

    private static function unique(array $o, string $d): string
    {
        $t = self::tn($o['table'], $d);
        $c = $o['column'];
        $name = self::q($d === 'mysql' ? $c->name : $o['table']->name . '_' . $c->name . '_key', $d); // nome padrao do banco
        if ($c->unique) return "ALTER TABLE $t ADD CONSTRAINT $name UNIQUE (" . self::q($c->name, $d) . ');';
        return $d === 'mysql' ? "ALTER TABLE $t DROP INDEX $name;" : "ALTER TABLE $t DROP CONSTRAINT $name;";
    }

    private static function idxName(Table $t, Index $i): string
    {
        return $i->name ?: 'idx_' . $t->name . '_' . implode('_', array_map(fn($id) => SchemaDiffer::colName($t, $id), $i->columns));
    }

    private static function indexAdd(Table $t, Index $i, string $d): string
    {
        $cols = $i->expression
            ? '(' . $i->expression . ')'
            : implode(', ', array_map(fn($id) => self::q(SchemaDiffer::colName($t, $id), $d), $i->columns));
        $using = $d !== 'mysql' && $i->method ? ' USING ' . $i->method : '';
        return 'CREATE ' . ($i->unique ? 'UNIQUE ' : '') . 'INDEX ' . self::q(self::idxName($t, $i), $d) . ' ON ' . self::tn($t, $d) . "$using ($cols);";
    }

    private static function indexDrop(Table $t, Index $i, string $d): string
    {
        $n = self::q(self::idxName($t, $i), $d);
        return $d === 'mysql' ? "DROP INDEX $n ON " . self::tn($t, $d) . ';' : "DROP INDEX $n;";
    }

    private static function colDef(Column $c, string $d, bool $unique = true): string
    {
        $s = self::q($c->name, $d) . ' ' . self::type($c, $d);
        if ($d === 'mysql' && $c->autoIncrement) $s .= ' AUTO_INCREMENT';
        if (!$c->nullable) $s .= ' NOT NULL';
        if ($c->default !== null) $s .= ' DEFAULT ' . $c->default;
        if ($unique && $c->unique) $s .= ' UNIQUE';
        return $s;
    }

    private static function type(Column $c, string $d, bool $serial = true): string
    {
        if ($serial && $d !== 'mysql' && $c->autoIncrement) {
            $s = ['integer' => 'serial', 'bigint' => 'bigserial', 'smallint' => 'smallserial'][$c->type] ?? null;
            if ($s) return $s;
        }
        $base = ($d === 'mysql' ? ['uuid' => 'char(36)'] : ['double' => 'double precision'])[$c->type] ?? $c->type;
        if ($c->length) return "$base({$c->length})";
        if ($c->precision) return "$base({$c->precision}," . ($c->scale ?? 0) . ')';
        return $base;
    }

    private static function fkName(Project $p, Relationship $r): string
    {
        if ($r->name) return $r->name;
        $f = $p->findTable($r->fromTable);
        return 'fk_' . $f->name . '_' . SchemaDiffer::colName($f, $r->fromColumns[0]);
    }

    private static function fkDrop(Project $p, Relationship $r, string $d): string
    {
        $f = $p->findTable($r->fromTable);
        $what = $d === 'mysql' ? 'DROP FOREIGN KEY' : 'DROP CONSTRAINT';
        return 'ALTER TABLE ' . self::tn($f, $d) . " $what " . self::q(self::fkName($p, $r), $d) . ';';
    }

    private static function fkAdd(Project $p, Relationship $r, string $d): string
    {
        $f = $p->findTable($r->fromTable);
        $t = $p->findTable($r->toTable);
        $cols = fn(Table $x, array $ids) => implode(', ', array_map(fn($id) => self::q(SchemaDiffer::colName($x, $id), $d), $ids));
        $sql = sprintf('ALTER TABLE %s ADD CONSTRAINT %s FOREIGN KEY (%s) REFERENCES %s (%s)',
            self::tn($f, $d), self::q(self::fkName($p, $r), $d), $cols($f, $r->fromColumns), self::tn($t, $d), $cols($t, $r->toColumns));
        foreach (['DELETE' => $r->onDelete, 'UPDATE' => $r->onUpdate] as $k => $a) {
            if ($a !== RefAction::NoAction) $sql .= " ON $k " . strtoupper(str_replace('_', ' ', $a->value));
        }
        return $sql . ';';
    }

    private static function q(string $s, string $d): string
    {
        return $d === 'mysql' ? '`' . $s . '`' : '"' . $s . '"';
    }

    private static function tn(Table $t, string $d): string
    {
        return ($t->schema ? self::q($t->schema, $d) . '.' : '') . self::q($t->name, $d);
    }
}
