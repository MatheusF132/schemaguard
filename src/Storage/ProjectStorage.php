<?php
declare(strict_types=1);
namespace SchemaGuard\Storage;

use SchemaGuard\Model\Project;

/**
 * Um projeto = uma pasta:
 *   project.json -> nome, dialeto, modo e regras
 *   model.json   -> so o esquema do banco (tabelas, relacionamentos, enums, grupos)
 * (layouts, versoes e cache de usos entram nas proximas etapas)
 */
final class ProjectStorage
{
    private const MODEL_KEYS = ['tables', 'relationships', 'enums', 'groups'];
    private const FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    public static function save(Project $project, string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0777, true)) {
            throw new \RuntimeException("Nao foi possivel criar a pasta: $dir");
        }
        $project->updatedAt = date(DATE_ATOM);
        $data = $project->toArray();
        $model = array_intersect_key($data, array_flip(self::MODEL_KEYS));
        $meta = array_diff_key($data, $model);
        file_put_contents("$dir/project.json", json_encode($meta, self::FLAGS));
        file_put_contents("$dir/model.json", json_encode($model, self::FLAGS));
    }

    public static function load(string $dir): Project
    {
        $meta = json_decode((string) file_get_contents("$dir/project.json"), true, 512, JSON_THROW_ON_ERROR);
        $model = json_decode((string) file_get_contents("$dir/model.json"), true, 512, JSON_THROW_ON_ERROR);
        return Project::fromArray($meta + $model);
    }
}
