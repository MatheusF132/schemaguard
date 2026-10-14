<?php
declare(strict_types=1);
namespace SchemaGuard\Model;

final class TableGroup
{
    use Arrayable;

    /** @param string[] $tables IDs das tabelas */
    public function __construct(
        public string $id,
        public string $name,
        public array $tables = [],
        public ?string $note = null,
    ) {}

    public static function fromArray(array $d): self
    {
        return new self(...$d);
    }
}
