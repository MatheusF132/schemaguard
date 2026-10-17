<?php
declare(strict_types=1);
namespace SchemaGuard\Analyzer;

use SchemaGuard\Model\Project;

/**
 * Analise de impacto, stack PHP (SQL escrito no codigo, PDO/mysqli).
 * Le os .php com o tokenizer nativo (sem dependencias), pega as strings e procura nelas as tabelas
 * e colunas do esquema. Consultas montadas dinamicamente ou via ORM podem nao aparecer.
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

        $filter = new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            fn($f, $k, $it) => $it->hasChildren() ? !in_array($f->getFilename(), self::SKIP, true) : strtolower($f->getExtension()) === 'php'
        );

        $files = 0;
        foreach (new \RecursiveIteratorIterator($filter) as $file) {
            if ($file->getSize() > self::MAX_BYTES) continue;
            if (++$files > self::MAX_FILES) break;
            $rel = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($root))), '/');
            $literals = iterator_to_array(self::strings((string) file_get_contents($file->getPathname())), false);
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
        }
        return ['files' => $files, 'usages' => $usages];
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
