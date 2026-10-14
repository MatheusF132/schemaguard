<?php
declare(strict_types=1);
namespace SchemaGuard\Model;

final class Table
{
    use Arrayable;

    /** @param Column[] $columns @param Index[] $indexes */
    public function __construct(
        public string $id,
        public string $name,
        public ?string $schema = null,
        public ?string $alias = null,
        public ?string $description = null,
        public bool $sensitive = false,
        public array $columns = [],
        public array $indexes = [],
    ) {}

    public static function create(string $name, ?string $schema = null): self
    {
        return new self(Id::generate('tbl'), $name, $schema);
    }

    public function addColumn(Column $column): Column
    {
        return $this->columns[] = $column;
    }

    public function column(string $name): ?Column
    {
        foreach ($this->columns as $c) if ($c->name === $name) return $c;
        return null;
    }

    public static function fromArray(array $d): self
    {
        $d['columns'] = array_map(Column::fromArray(...), $d['columns'] ?? []);
        $d['indexes'] = array_map(Index::fromArray(...), $d['indexes'] ?? []);
        return new self(...$d);
    }
}
