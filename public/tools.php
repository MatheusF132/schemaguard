<?php
declare(strict_types=1);
require __DIR__ . '/../src/autoload.php';

use SchemaGuard\Analyzer\PhpAnalyzer;
use SchemaGuard\Diff\SchemaDiffer;
use SchemaGuard\Export\DbmlExporter;
use SchemaGuard\Model\Project;
use SchemaGuard\Sql\SqlGenerator;
use SchemaGuard\Storage\ProjectStorage;
use SchemaGuard\Validation\Validator;

header('Content-Type: application/json; charset=utf-8');

$dir = __DIR__ . '/../projects/default';
$vdir = "$dir/versions";
$uf = "$dir/usages.json";
$in = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];

$list = function () use ($vdir): array {
    $out = [];
    foreach (glob("$vdir/*.json") ?: [] as $f) {
        $d = json_decode((string) file_get_contents($f), true);
        $out[] = ['id' => basename($f, '.json'), 'label' => $d['label'], 'createdAt' => $d['createdAt']];
    }
    return array_reverse($out); // ids sao datas: mais novas primeiro
};

try {
    if (!is_file("$dir/project.json")) throw new RuntimeException('Importe ou crie um esquema primeiro.');
    $current = ProjectStorage::load($dir);
    $dialect = ($in['dialect'] ?? '') === 'mysql' ? 'mysql' : 'postgresql';
    $flags = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

    switch ($_GET['action'] ?? '') {
        case 'snapshot':
            if (!is_dir($vdir)) mkdir($vdir, 0777, true);
            $label = trim((string) ($in['label'] ?? '')) ?: 'Versão ' . (count($list()) + 1);
            file_put_contents($vdir . '/' . date('Ymd-His') . '.json', json_encode(
                ['label' => $label, 'createdAt' => date(DATE_ATOM), 'project' => $current->toArray()], JSON_PRETTY_PRINT | $flags));
            echo json_encode(['versions' => $list()], $flags);
            break;
        case 'versions':
            echo json_encode(['versions' => $list()], $flags);
            break;
        case 'diff':
            $file = $vdir . '/' . basename((string) ($in['version'] ?? '')) . '.json';
            if (!is_file($file)) throw new RuntimeException('Versão não encontrada.');
            $old = Project::fromArray(json_decode((string) file_get_contents($file), true)['project']);
            $ops = SchemaDiffer::diff($old, $current);
            $usages = is_file($uf) ? (json_decode((string) file_get_contents($uf), true)['usages'] ?? []) : [];
            // onde, no codigo, a mudanca pode quebrar algo (usa os IDs, entao vale para renomear tambem)
            $impact = function (array $o) use ($usages): array {
                $tid = $o['table']->id;
                $cid = null;
                switch ($o['op']) {
                    case 'table_removed': case 'table_renamed': break;
                    case 'column_removed': case 'column_renamed': $cid = ($o['old'] ?? $o['column'])->id; break;
                    case 'column_changed': if (!in_array('type', $o['what'], true)) return []; $cid = $o['column']->id; break;
                    default: return [];
                }
                return array_values(array_filter($usages, fn($u) => $u['table'] === $tid && ($cid === null || $u['column'] === $cid || $u['kind'] === 'star')));
            };
            $changes = [];
            foreach ($ops as $o) {
                $imp = $impact($o);
                $n = count($imp);
                $changes[] = [
                    'risk' => $o['risk'],
                    'text' => $o['text'] . ($n ? " Usado em $n " . ($n === 1 ? 'lugar' : 'lugares') . ' do código.' : ''),
                    'impactCount' => $n,
                    'impact' => array_slice($imp, 0, 15),
                ];
            }
            echo json_encode(['changes' => $changes, 'sql' => SqlGenerator::migration($ops, $dialect)], $flags);
            break;
        case 'scan':
            $r = PhpAnalyzer::scan((string) ($in['path'] ?? ''), $current);
            $data = ['path' => (string) $in['path'], 'scannedAt' => date(DATE_ATOM), 'files' => $r['files'], 'usages' => $r['usages']];
            file_put_contents($uf, json_encode($data, $flags));
            echo json_encode($data, $flags);
            break;
        case 'usages':
            echo is_file($uf) ? (string) file_get_contents($uf) : json_encode(['path' => '', 'scannedAt' => null, 'files' => 0, 'usages' => []]);
            break;
        case 'sql':
            $sql = ($in['dialect'] ?? '') === 'dbml'
                ? DbmlExporter::export($current)
                : SqlGenerator::migration(SchemaDiffer::diff(new Project(name: ''), $current), $dialect);
            echo json_encode(['sql' => $sql], $flags);
            break;
        case 'validate':
            echo json_encode(['findings' => Validator::run($current)], $flags);
            break;
        default:
            throw new RuntimeException('Ação desconhecida.');
    }
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
