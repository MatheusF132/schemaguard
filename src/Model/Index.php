<?php
declare(strict_types=1);
namespace SchemaGuard\Model;

final class Index
{
    use Arrayable;

    /** @param string[] $columns IDs das colunas */
    public function __construct(
        public string $id,
        public ?string $name = null,
        public array $columns = [],
        public ?string $expression = null,
        public bool $unique = false,
        public ?string $method = null, // btree, hash...
    ) {}

    public static function fromArray(array $d): self
    {
        return new self(...$d);
    }
}
