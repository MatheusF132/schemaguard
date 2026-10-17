<?php
declare(strict_types=1);
namespace SchemaGuard\Analyzer;

use SchemaGuard\Model\Project;

/**
 * Analise de impacto, stack PHP: SQL escrito no codigo (PDO/mysqli) e Laravel (Eloquent e Query Builder).
 * Le os .php com o tokenizer nativo (sem dependencias): procura tabelas e colunas do esquema nas strings,
 * nos models (classe -> tabela) e nos acessos a propriedades ($paciente->email). E uma aproximacao:
 * consultas montadas dinamicamente podem nao aparecer.
 */
final class PhpAnalyzer
{
    private const SKIP = ['vendor', 'node_modules', '.git'];
    private const MAX_FILES = 3000;
    private const MAX_BYTES = 1000000;
    private const MAX_USAGES = 5000;

    /** @return array{files:int, usages:array<int,array<string,mixed>>} */
    public static function scan(string $path, Project $p): array
    {
        $root = realpath($path);
        if ($root === false || !is_dir($root)) throw new \RuntimeException('Pasta não encontrada: ' . $path);

        $tables = [];
        foreach ($p->tables as $t) $tables[strtolower($t->name)] = $t;

        $usages = [];
        $seen = [];
        $add = function (string $tid, ?string $cid, string $file, int $line, string $kind, string $op, string $text) use (&$usages, &$seen): void {
            $k = "$tid|$cid|$file|$line|$kind";
            if (isset($seen[$k]) || count($usages) >= self::MAX_USAGES) return;
            $seen[$k] = true;
            $norm = trim((string) preg_replace('/\s+/', ' ', $text));
            $snippet = preg_match('/^.{0,140}/su', $norm, $m) ? $m[0] : substr($norm, 0, 140);
            $usages[] = ['table' => $tid, 'column' => $cid, 'file' => $file, 'line' => $line, 'kind' => $kind, 'op' => $op, 'snippet' => $snippet];
        };

        $models = self::models($root, $tables);

        $files = 0;
        foreach (self::phpFiles($root) as $file) {
            if ($file->getSize() > self::MAX_BYTES) continue;
            if (++$files > self::MAX_FILES) break;
            $rel = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($root))), '/');
            if (str_contains('/' . $rel, '/database/migrations/')) continue; // migrations definem o esquema, nao o usam
            $code = (string) file_get_contents($file->getPathname());
            $literals = iterator_to_array(self::strings($code), false);
            $fileTables = [];

            foreach ($literals as [$text, $line]) {
                if (!preg_match('/\b(select|insert|update|delete|replace|from|join|into)\b/i', $text)) continue;
                $op = self::operation($text);
                foreach (self::referenced($text, $tables) as [$t, $verb]) {
                    $fileTables[$t->id] = $t;
                    $o = $op ?: ['into' => 'INSERT', 'update' => 'UPDATE'][$verb] ?? 'SELECT';
                    $add($t->id, null, $rel, $line, 'table', $o, $text);
                    if (preg_match('/select\s+(?:distinct\s+)?\*|[\w`"]+\.\*/i', $text)) {
                        $add($t->id, null, $rel, $line, 'star', $o, $text);
                    }
                    foreach ($t->columns as $c) {
                        if (preg_match('/(?<![\w$])' . preg_quote($c->name, '/') . '(?![\w$])/i', $text)) {
                            $add($t->id, $c->id, $rel, $line, 'sql', $o, $text);
                        }
                    }
                }
            }

            // Laravel: models citados no arquivo e DB::table('tabela')
            foreach ($models as $class => $t) {
                if (preg_match('/\b' . preg_quote($class, '/') . '\b/', $code, $m, PREG_OFFSET_CAPTURE)) {
                    $fileTables[$t->id] = $t;
                    $add($t->id, null, $rel, 1 + substr_count($code, "\n", 0, $m[0][1]), 'table', 'MODEL', $class);
                }
            }
            if (preg_match_all('/(?:DB::table|->table)\(\s*[\'"]([\w.]+)[\'"]/', $code, $dm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                foreach ($dm as $m) {
                    $t = $tables[strtolower(substr(strrchr('.' . $m[1][0], '.'), 1))] ?? null;
                    if (!$t) continue;
                    $fileTables[$t->id] = $t;
                    $add($t->id, null, $rel, 1 + substr_count($code, "\n", 0, $m[0][1]), 'table', 'QUERY', $m[0][0]);
                }
            }

            // chaves de array e parametros nomeados (['email'], :email) nos arquivos que falam dessas tabelas
            foreach ($literals as [$text, $line, $short]) {
                $name = ltrim($text, ':');
                if (!$short || !preg_match('/^[A-Za-z_]\w*$/', $name)) continue;
                foreach ($fileTables as $t) {
                    foreach ($t->columns as $c) {
                        if (strcasecmp($c->name, $name) === 0) $add($t->id, $c->id, $rel, $line, 'key', 'ARRAY', "'" . $text . "'");
                    }
                }
            }

            // acesso a propriedade ($paciente->email), tipico do Eloquent
            foreach (self::props($code) as [$name, $line]) {
                foreach ($fileTables as $t) {
                    foreach ($t->columns as $c) {
                        if (strcasecmp($c->name, $name) === 0) $add($t->id, $c->id, $rel, $line, 'prop', 'PROP', '->' . $name);
                    }
                }
            }
        }
        return ['files' => $files, 'usages' => $usages];
    }

    private static function phpFiles(string $root): \RecursiveIteratorIterator
    {
        $filter = new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            fn($f, $k, $it) => $it->hasChildren() ? !in_array($f->getFilename(), self::SKIP, true) : strtolower($f->getExtension()) === 'php'
        );
        return new \RecursiveIteratorIterator($filter);
    }

    /** @return array<string, \SchemaGuard\Model\Table> classe do model => tabela (pela propriedade $table ou pela convencao do Laravel) */
    private static function models(string $root, array $tables): array
    {
        $out = [];
        foreach (self::phpFiles($root) as $file) {
            if ($file->getSize() > self::MAX_BYTES) continue;
            $code = (string) file_get_contents($file->getPathname());
            if (!preg_match('/class\s+(\w+)\s+extends\s+\\\\?(?:[\w\\\\]*\\\\)?(?:Model|Authenticatable|Pivot|Eloquent)\b/', $code, $m)) continue;
            $name = preg_match('/\$table\s*=\s*[\'"]([\w.]+)[\'"]/', $code, $tm) ? $tm[1] : self::conventionTable($m[1]);
            $name = strtolower(substr(strrchr('.' . $name, '.'), 1));
            if (isset($tables[$name])) $out[$m[1]] = $tables[$name];
        }
        return $out;
    }

    /** Patient -> patients, HospitalRoom -> hospital_rooms, Category -> categories */
    private static function conventionTable(string $class): string
    {
        $snake = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $class));
        if (preg_match('/[^aeiou]y$/', $snake)) return substr($snake, 0, -1) . 'ies';
        if (preg_match('/(s|x|z|ch|sh)$/', $snake)) return $snake . 'es';
        return $snake . 's';
    }

    /** Gera [nome, linha] de cada ->propriedade (ignora chamadas de metodo). */
    private static function props(string $code): \Generator
    {
        $tokens = @token_get_all($code);
        $skip = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];
        for ($i = 0, $n = count($tokens); $i < $n; $i++) {
            $t = $tokens[$i];
            if (!is_array($t) || ($t[0] !== T_OBJECT_OPERATOR && $t[0] !== T_NULLSAFE_OBJECT_OPERATOR)) continue;
            $j = $i + 1;
            while ($j < $n && is_array($tokens[$j]) && in_array($tokens[$j][0], $skip, true)) $j++;
            if (!isset($tokens[$j]) || !is_array($tokens[$j]) || $tokens[$j][0] !== T_STRING) continue;
            $k = $j + 1;
            while ($k < $n && is_array($tokens[$k]) && in_array($tokens[$k][0], $skip, true)) $k++;
            if (($tokens[$k] ?? null) === '(') continue;
            yield [$tokens[$j][1], $tokens[$j][2]];
        }
    }

    private static function operation(string $text): ?string
    {
        return preg_match('/^\s*(select|insert|update|delete|replace|create|alter|drop|truncate)\b/i', $text, $m) ? strtoupper($m[1]) : null;
    }

    /** @return array<int, array{0:\SchemaGuard\Model\Table,1:string}> */
    private static function referenced(string $text, array $tables): array
    {
        $out = [];
        $re = '/\b(from|join|into|update|truncate|table)\s+(?:only\s+|if\s+(?:not\s+)?exists\s+)?[`"\[]?\??([A-Za-z_][\w$]*)[`"\]]?(?:\s*\.\s*[`"\[]?([A-Za-z_][\w$]*)[`"\]]?)?/i';
        if (preg_match_all($re, $text, $all, PREG_SET_ORDER)) {
            foreach ($all as $m) {
                $name = strtolower(($m[3] ?? '') !== '' ? $m[3] : $m[2]);
                if (isset($tables[$name])) $out[] = [$tables[$name], strtolower($m[1])];
            }
        }
        return $out;
    }

    /** Gera [texto, linha, eStringSimples]. Strings com variaveis e heredoc viram um texto so, com "?" no lugar da variavel. */
    private static function strings(string $code): \Generator
    {
        $buf = null;
        $bufLine = 1;
        $cur = 1;
        foreach (@token_get_all($code) as $tk) {
            if (is_array($tk)) {
                [$id, $txt, $line] = $tk;
                $cur = $line + substr_count($txt, "\n");
                if ($id === T_CONSTANT_ENCAPSED_STRING) yield [substr($txt, 1, -1), $line, true];
                elseif ($id === T_START_HEREDOC) { $buf = ''; $bufLine = $line; }
                elseif ($id === T_END_HEREDOC) { if ($buf !== null) yield [$buf, $bufLine, false]; $buf = null; }
                elseif ($buf !== null) $buf .= $id === T_ENCAPSED_AND_WHITESPACE ? $txt : '?';
            } elseif ($tk === '"') {
                if ($buf === null) { $buf = ''; $bufLine = $cur; }
                else { yield [$buf, $bufLine, false]; $buf = null; }
            }
        }
    }
}
