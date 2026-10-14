<?php
declare(strict_types=1);
require __DIR__ . '/../src/autoload.php';

use SchemaGuard\Import\SqlImporter;
use SchemaGuard\Model\{Level, Project};
use SchemaGuard\Storage\ProjectStorage;

header('Content-Type: application/json; charset=utf-8');

$dir = __DIR__ . '/../projects/default';
if (!is_dir($dir)) mkdir($dir, 0777, true);

$in = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
$load = fn() => is_file("$dir/project.json") ? ProjectStorage::load($dir) : new Project(name: 'Meu projeto');

try {
    switch ($_GET['action'] ?? 'load') {
        case 'import':
            $project = (new SqlImporter())->import((string) ($in['sql'] ?? ''), 'Meu projeto');
            ProjectStorage::save($project, $dir);
            @unlink("$dir/layout.json"); // tabelas novas: posicoes novas
            break;
        case 'mode':
            $project = $load();
            $project->mode = Level::from((string) ($in['mode'] ?? 'intermediate'));
            ProjectStorage::save($project, $dir);
            break;
        case 'layout': // layout fica fora do modelo, em arquivo proprio
            file_put_contents("$dir/layout.json", json_encode((object) ($in['layout'] ?? []), JSON_PRETTY_PRINT));
            $project = $load();
            break;
        default:
            $project = $load();
    }
    $layoutFile = "$dir/layout.json";
    $layout = is_file($layoutFile) ? (json_decode((string) file_get_contents($layoutFile), true) ?? []) : [];
    echo json_encode(['project' => $project->toArray(), 'layout' => (object) $layout], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
