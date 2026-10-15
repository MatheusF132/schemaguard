<?php
declare(strict_types=1);
namespace SchemaGuard\Validation;

use SchemaGuard\Model\{Column, Project, Table};

/**
 * Regras simples (sem IA) que olham o modelo e apontam problemas.
 * Cada achado tem um nivel (beginner, intermediate, advanced): o modo escolhido so filtra e decide quanto explicar.
 */
final class Validator
{
    /** @return array<int, array<string, mixed>> */
    public static function run(Project $p): array
    {
        $found = [];
        $add = function (string $rule, string $level, Table $t, ?Column $c, string $msg, string $why) use (&$found): void {
            $found[] = ['rule' => $rule, 'level' => $level, 'table' => $t->id, 'column' => $c?->id, 'message' => $msg, 'explanation' => $why];
        };

        $fkCols = [];
        $linked = [];
        foreach ($p->relationships as $r) {
            foreach ($r->fromColumns as $id) $fkCols[$id] = true;
            $linked[$r->fromTable] = $linked[$r->toTable] = true;
        }

        foreach ($p->tables as $t) {
            if (!$t->columns) {
                $add('EMPTY_TABLE', 'beginner', $t, null, "A tabela {$t->name} não tem nenhuma coluna.", 'Uma tabela precisa de colunas para guardar informações. Comece adicionando um identificador (id).');
                continue;
            }
            if (!array_filter($t->columns, fn($c) => $c->primaryKey)) {
                $add('NO_PK', 'beginner', $t, null, "A tabela {$t->name} não tem identificador único (chave primária).", 'Sem um identificador, não dá para distinguir uma linha da outra nem apontar para ela a partir de outra tabela. Marque uma coluna (normalmente id) como identificador único.');
            }
            if (count($p->tables) > 1 && !isset($linked[$t->id])) {
                $add('ISOLATED_TABLE', 'beginner', $t, null, "A tabela {$t->name} não está ligada a nenhuma outra.", 'Normalmente as tabelas de um sistema se relacionam. Se ela deveria apontar para outra (ou receber ligações), crie o relacionamento; se for mesmo independente, pode ignorar este aviso.');
            }
            if (count($t->columns) > 20) {
                $add('WIDE_TABLE', 'advanced', $t, null, "A tabela {$t->name} tem " . count($t->columns) . ' colunas.', 'Tabelas muito largas costumam misturar assuntos diferentes. Veja se parte das colunas não pertence a outra tabela ligada a esta.');
            }

            $groups = [];
            foreach ($t->columns as $c) {
                if (preg_match('/^(.+?)_?\d+$/', $c->name, $m)) $groups[strtolower($m[1])][] = $c;
            }
            foreach ($groups as $cols) {
                if (count($cols) >= 2) {
                    $add('REPEATED_COLUMNS', 'advanced', $t, $cols[0], "Colunas repetidas em {$t->name}: " . implode(', ', array_map(fn($c) => $c->name, $cols)) . '.', 'Colunas numeradas (como telefone1, telefone2) costumam indicar dados repetidos. O mais comum é mover esses dados para outra tabela ligada a esta (1ª forma normal).');
                }
            }

            foreach ($t->columns as $c) {
                if (in_array($c->type, ['varchar', 'char'], true) && !$c->length) {
                    $add('VARCHAR_NO_LENGTH', 'intermediate', $t, $c, "{$t->name}.{$c->name} é texto sem tamanho definido.", 'Defina um tamanho máximo (por exemplo, 255) ou use o tipo text. Alguns bancos, como o MySQL, exigem o tamanho em varchar.');
                }
                if (!isset($fkCols[$c->id]) && !$c->primaryKey && preg_match('/^(.+)_id$/i', $c->name, $m) && ($g = self::guessTable($p, $m[1], $t))) {
                    $add('MISSING_FK', 'intermediate', $t, $c, "{$t->name}.{$c->name} parece apontar para {$g->name}, mas não há relacionamento.", "O nome termina com _id e existe uma tabela {$g->name}. Sem o relacionamento, o banco não impede valores que não existem em {$g->name}.");
                }
            }
        }

        foreach ($p->relationships as $r) {
            $from = $p->findTable($r->fromTable);
            $to = $p->findTable($r->toTable);
            if (!$from || !$to) continue;
            foreach ($r->fromColumns as $i => $fid) {
                $fc = self::col($from, $fid);
                $tc = self::col($to, $r->toColumns[$i] ?? '');
                if (!$fc || !$tc) continue;
                if ($fc->type !== $tc->type) {
                    $add('FK_TYPE_MISMATCH', 'intermediate', $from, $fc, "{$from->name}.{$fc->name} é {$fc->type}, mas aponta para {$to->name}.{$tc->name}, que é {$tc->type}.", 'A chave estrangeira e a coluna que ela referencia devem ter o mesmo tipo; senão o banco pode recusar a ligação ou fazer conversões lentas.');
                }
                if (count($r->toColumns) === 1 && !$tc->primaryKey && !$tc->unique) {
                    $add('FK_TARGET_NOT_KEY', 'intermediate', $from, $fc, "{$from->name}.{$fc->name} aponta para {$to->name}.{$tc->name}, que não é identificador único nem valor único.", 'Para apontar para uma coluna, ela precisa ser única (chave primária ou UNIQUE); senão o banco não sabe a qual linha você se refere.');
                }
            }
            $first = self::col($from, $r->fromColumns[0] ?? '');
            if ($first && !self::indexed($from, $first)) {
                $add('FK_NO_INDEX', 'advanced', $from, $first, "{$from->name}.{$first->name} é chave estrangeira sem índice.", 'Consultas que juntam as duas tabelas, e apagar linhas na tabela de destino, ficam lentas conforme os dados crescem. Considere criar um índice nesta coluna.');
            }
        }
        return $found;
    }

    private static function col(Table $t, string $id): ?Column
    {
        foreach ($t->columns as $c) if ($c->id === $id) return $c;
        return null;
    }

    private static function indexed(Table $t, Column $c): bool
    {
        if ($c->primaryKey || $c->unique) return true;
        foreach ($t->indexes as $i) if (($i->columns[0] ?? null) === $c->id) return true;
        return false;
    }

    private static function guessTable(Project $p, string $base, Table $self): ?Table
    {
        $names = array_map('strtolower', [$base, $base . 's', $base . 'es', preg_replace('/y$/i', 'ies', $base)]);
        foreach ($p->tables as $t) {
            if ($t->id !== $self->id && in_array(strtolower($t->name), $names, true)) return $t;
        }
        return null;
    }
}
