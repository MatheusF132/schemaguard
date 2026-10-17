<?php
declare(strict_types=1);
namespace SchemaGuard\Export;

use SchemaGuard\Diff\SchemaDiffer;
use SchemaGuard\Model\{Project, RefAction, Relationship, Table};

/** Exporta o modelo como DBML (a linguagem do dbdiagram.io), para abrir la ou guardar como texto. */
final class DbmlExporter
{
    public static function export(Project $p): string
    {
        $out = [];
        foreach ($p->enums as $e) {
            $vals = array_map(fn($v) => '  ' . self::id((string) (is_array($v) ? $v['name'] : $v)), $e->values);
            $out[] = 'Enum ' . self::qname($e->schema, $e->name) . " {\n" . implode("\n", $vals) . "\n}";
        }
        foreach ($p->tables as $t) $out[] = self::table($t);
        foreach ($p->relationships as $r) {
            if ($line = self::ref($p, $r)) $out[] = $line;
        }
        foreach ($p->groups as $g) {
            $names = [];
            foreach ($g->tables as $id) {
                if ($t = $p->findTable($id)) $names[] = '  ' . self::qname($t->schema, $t->name);
            }
            if ($names) $out[] = 'TableGroup ' . self::id($g->name) . " {\n" . implode("\n", $names) . "\n}";
        }
        return $out ? implode("\n\n", $out) . "\n" : '';
    }

    private static function table(Table $t): string
    {
        $lines = [];
        foreach ($t->columns as $c) {
            $type = $c->type . ($c->length ? "({$c->length})" : ($c->precision ? "({$c->precision}," . ($c->scale ?? 0) . ')' : ''));
            $s = [];
            if ($c->primaryKey) $s[] = 'pk';
            if ($c->autoIncrement) $s[] = 'increment';
            if (!$c->nullable && !$c->primaryKey) $s[] = 'not null';
            if ($c->unique) $s[] = 'unique';
            if ($c->default !== null) $s[] = 'default: ' . self::dflt($c->default);
            if ($c->description) $s[] = 'note: ' . self::str($c->description);
            $lines[] = '  ' . self::id($c->name) . ' ' . $type . ($s ? ' [' . implode(', ', $s) . ']' : '');
        }

        $idx = [];
        foreach ($t->indexes as $i) {
            $names = array_map(fn($id) => self::id(SchemaDiffer::colName($t, $id)), $i->columns);
            if ($i->expression) $cols = '`' . $i->expression . '`';
            elseif (count($names) > 1) $cols = '(' . implode(', ', $names) . ')';
            elseif ($names) $cols = $names[0];
            else continue;
            $set = [];
            if ($i->unique) $set[] = 'unique';
            if ($i->name) $set[] = 'name: ' . self::str($i->name);
            if ($i->method) $set[] = 'type: ' . $i->method;
            $idx[] = '    ' . $cols . ($set ? ' [' . implode(', ', $set) . ']' : '');
        }

        $body = implode("\n", $lines);
        if ($idx) $body .= "\n\n  indexes {\n" . implode("\n", $idx) . "\n  }";
        if ($t->description) $body .= "\n\n  Note: " . self::str($t->description);
        return 'Table ' . self::qname($t->schema, $t->name) . " {\n$body\n}";
    }

    private static function ref(Project $p, Relationship $r): ?string
    {
        $f = $p->findTable($r->fromTable);
        $t = $p->findTable($r->toTable);
        if (!$f || !$t || !$r->fromColumns || !$r->toColumns) return null;
        $side = function (Table $x, array $ids): string {
            $names = array_map(fn($id) => self::id(SchemaDiffer::colName($x, $id)), $ids);
            return self::qname($x->schema, $x->name) . '.' . (count($names) > 1 ? '(' . implode(', ', $names) . ')' : $names[0]);
        };
        $op = ['many_to_one' => '>', 'one_to_many' => '<', 'one_to_one' => '-', 'many_to_many' => '<>'][$r->cardinality->value];
        $set = [];
        foreach (['delete' => $r->onDelete, 'update' => $r->onUpdate] as $k => $a) {
            if ($a !== RefAction::NoAction) $set[] = "$k: " . str_replace('_', ' ', $a->value);
        }
        return 'Ref' . ($r->name ? ' ' . self::id($r->name) : '') . ': ' . $side($f, $r->fromColumns) . " $op " . $side($t, $r->toColumns)
            . ($set ? ' [' . implode(', ', $set) . ']' : '');
    }

    private static function id(string $s): string
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $s) ? $s : '"' . str_replace('"', '\\"', $s) . '"';
    }

    private static function qname(?string $schema, string $name): string
    {
        return ($schema ? self::id($schema) . '.' : '') . self::id($name);
    }

    private static function str(string $s): string
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $s) . "'";
    }

    private static function dflt(string $v): string
    {
        $v = trim($v);
        if (preg_match("/^'.*'$/s", $v) || is_numeric($v) || in_array(strtolower($v), ['null', 'true', 'false'], true)) return $v;
        return '`' . $v . '`';
    }
}
