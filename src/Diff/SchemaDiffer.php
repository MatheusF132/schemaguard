<?php
declare(strict_types=1);
namespace SchemaGuard\Diff;

use SchemaGuard\Model\{Project, Relationship, Table};

/**
 * Compara duas versoes do esquema usando os IDs estaveis (por isso rename nao vira apagar + criar).
 * Devolve operacoes na ordem em que devem rodar: 1) soltar FKs, 2) apagar, 3) criar/alterar, 4) criar FKs.
 */
final class SchemaDiffer
{
    /** @return array<int, array<string, mixed>> */
    public static function diff(Project $old, Project $new): array
    {
        $ops = [];
        $oldT = self::byId($old->tables);
        $newT = self::byId($new->tables);

        foreach ($oldT as $id => $t) {
            if (!isset($newT[$id])) {
                $ops[] = self::op(2, 'table_removed', 'destructive', "Tabela {$t->name} será apagada, com todos os dados dela.", ['table' => $t]);
            }
        }
        foreach ($newT as $id => $t) {
            if (!isset($oldT[$id])) {
                $ops[] = self::op(3, 'table_added', 'safe', "Nova tabela {$t->name}.", ['table' => $t]);
                continue;
            }
            $o = $oldT[$id];
            if ($o->name !== $t->name || $o->schema !== $t->schema) {
                $ops[] = self::op(3, 'table_renamed', 'attention', "Tabela {$o->name} renomeada para {$t->name}: o código que usa o nome antigo vai quebrar.", ['old' => $o, 'table' => $t]);
            }
            self::columns($ops, $o, $t);
        }

        $oldR = self::byId($old->relationships);
        $newR = self::byId($new->relationships);
        foreach ($oldR as $id => $r) {
            if (!isset($newR[$id]) || $newR[$id]->toArray() != $r->toArray()) {
                $ops[] = self::op(1, 'fk_removed', 'attention', 'Relacionamento removido: ' . self::relText($old, $r) . '.', ['rel' => $r, 'project' => $old]);
            }
        }
        foreach ($newR as $id => $r) {
            if (!isset($oldR[$id]) || $oldR[$id]->toArray() != $r->toArray()) {
                $ops[] = self::op(4, 'fk_added', 'attention', 'Novo relacionamento: ' . self::relText($new, $r) . ' (falha se já houver dados que o violem).', ['rel' => $r, 'project' => $new]);
            }
        }

        usort($ops, fn($a, $b) => $a['phase'] <=> $b['phase']); // estavel no PHP 8
        return $ops;
    }

    private static function columns(array &$ops, Table $o, Table $n): void
    {
        $oc = self::byId($o->columns);
        $nc = self::byId($n->columns);

        foreach ($oc as $id => $c) {
            if (!isset($nc[$id])) {
                // roda antes de um eventual rename da tabela, entao usa o nome antigo
                $ops[] = self::op(2, 'column_removed', 'destructive', "Coluna {$o->name}.{$c->name} será apagada, com todos os dados dela.", ['table' => $o, 'old' => $c]);
            }
        }
        foreach ($nc as $id => $c) {
            if (!isset($oc[$id])) {
                $hard = !$c->nullable && $c->default === null;
                $ops[] = self::op(3, 'column_added', $hard ? 'attention' : 'safe',
                    "Nova coluna {$n->name}.{$c->name}" . ($hard ? ': é obrigatória e sem valor padrão, então falha se a tabela já tiver linhas.' : '.'),
                    ['table' => $n, 'column' => $c]);
                continue;
            }
            $b = $oc[$id];
            if ($b->name !== $c->name) {
                $ops[] = self::op(3, 'column_renamed', 'attention', "Coluna {$n->name}.{$b->name} renomeada para {$c->name}: o código que usa o nome antigo vai quebrar.", ['table' => $n, 'old' => $b, 'column' => $c]);
            }

            $what = [];
            $msgs = [];
            $risk = 'safe';
            if ([$b->type, $b->length, $b->precision, $b->scale] != [$c->type, $c->length, $c->precision, $c->scale]) {
                $what[] = 'type';
                if ($b->type !== $c->type) {
                    $risk = self::worse($risk, 'attention');
                    $msgs[] = "tipo muda de {$b->type} para {$c->type} (pode falhar ou converter dados)";
                } elseif ($b->length !== null && $c->length !== null && $c->length < $b->length) {
                    $risk = self::worse($risk, 'destructive');
                    $msgs[] = "tamanho cai de {$b->length} para {$c->length} (pode cortar dados)";
                } else {
                    $msgs[] = 'tamanho ou precisão alterado';
                }
            }
            if ($b->nullable !== $c->nullable) {
                $what[] = 'nullable';
                if (!$c->nullable) {
                    $risk = self::worse($risk, 'attention');
                    $msgs[] = 'passa a ser obrigatória (falha se houver valores vazios)';
                } else {
                    $msgs[] = 'deixa de ser obrigatória';
                }
            }
            if ($b->default !== $c->default) {
                $what[] = 'default';
                $msgs[] = 'valor padrão alterado';
            }
            if ($what) {
                $ops[] = self::op(3, 'column_changed', $risk, "Coluna {$n->name}.{$c->name}: " . implode('; ', $msgs) . '.', ['table' => $n, 'column' => $c, 'what' => $what]);
            }
        }
    }

    public static function colName(Table $t, string $id): string
    {
        foreach ($t->columns as $c) {
            if ($c->id === $id) return $c->name;
        }
        return '?';
    }

    private static function relText(Project $p, Relationship $r): string
    {
        $f = $p->findTable($r->fromTable);
        $t = $p->findTable($r->toTable);
        $cols = fn(?Table $x, array $ids) => $x ? implode(', ', array_map(fn($id) => self::colName($x, $id), $ids)) : '?';
        return ($f->name ?? '?') . '.' . $cols($f, $r->fromColumns) . ' → ' . ($t->name ?? '?') . '.' . $cols($t, $r->toColumns);
    }

    private static function op(int $phase, string $op, string $risk, string $text, array $data = []): array
    {
        return ['phase' => $phase, 'op' => $op, 'risk' => $risk, 'text' => $text] + $data;
    }

    private static function byId(array $items): array
    {
        $out = [];
        foreach ($items as $i) $out[$i->id] = $i;
        return $out;
    }

    private static function worse(string $a, string $b): string
    {
        $rank = ['safe' => 0, 'attention' => 1, 'destructive' => 2];
        return $rank[$b] > $rank[$a] ? $b : $a;
    }
}
