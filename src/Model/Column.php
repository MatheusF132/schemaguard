<?php
declare(strict_types=1);
namespace SchemaGuard\Model;

final class Column
{
    use Arrayable;

    public function __construct(
        public string $id,
        public string $name,
        public string $type,            // tipo normalizado: "varchar", "integer"...
        public ?int $length = null,
        public ?int $precision = null,
        public ?int $scale = null,
        public ?string $rawType = null, // texto original do dialeto
        public bool $nullable = true,
        public bool $primaryKey = false,
        public bool $unique = false,
        public bool $autoIncrement = false,
        public ?string $default = null,
        public ?string $enumId = null,
        public ?string $description = null,
        public bool $sensitive = false,
    ) {}

    public static function create(string $name, string $type, array $options = []): self
    {
        return new self(Id::generate('col'), $name, $type, ...$options);
    }

    public static function fromArray(array $d): self
    {
        return new self(...$d);
    }
}
