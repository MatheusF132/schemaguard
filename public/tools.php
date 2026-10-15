<?php
declare(strict_types=1);
require __DIR__ . '/../src/autoload.php';

use SchemaGuard\Diff\SchemaDiffer;
use SchemaGuard\Model\Project;
use SchemaGuard\Sql\SqlGenerator;
use SchemaGuard\Storage\ProjectStorage;

header('Content-Type: application/json; charset=utf-8');

$dir = __DIR__ . '/../projects/default';
$vdir = "$dir/versions";
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
    $flags = JSON_UNESCAPED_UNICODE;

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
            echo json_encode([
                'changes' => array_map(fn($o) => ['risk' => $o['risk'], 'text' => $o['text']], $ops),
                'sql' => SqlGenerator::migration($ops, $dialect),
            ], $flags);
            break;
        case 'sql':
            echo json_encode(['sql' => SqlGenerator::migration(SchemaDiffer::diff(new Project(name: ''), $current), $dialect)], $flags);
            break;
        default:
            throw new RuntimeException('Ação desconhecida.');
    }
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
