<?php
declare(strict_types=1);
namespace SchemaGuard\Model;

final class Project
{
    use Arrayable;

    /**
     * @param Table[] $tables @param Relationship[] $relationships
     * @param EnumType[] $enums @param TableGroup[] $groups @param Rule[] $rules
     */
    public function __construct(
        public string $name,
        public string $dialect = 'postgresql',
        public Level $mode = Level::Intermediate,
        public int $formatVersion = 1,
        public ?string $createdAt = null,
        public ?string $updatedAt = null,
        public array $tables = [],
        public array $relationships = [],
        public array $enums = [],
        public array $groups = [],
        public array $rules = [],
    ) {
        $this->createdAt ??= date(DATE_ATOM);
        $this->updatedAt ??= $this->createdAt;
    }

    public function addTable(Table $table): Table
    {
        return $this->tables[] = $table;
    }

    public function findTable(string $id): ?Table
    {
        foreach ($this->tables as $t) if ($t->id === $id) return $t;
        return null;
    }

    public static function fromArray(array $d): self
    {
        $d['mode'] = Level::from($d['mode']);
        $d['tables'] = array_map(Table::fromArray(...), $d['tables'] ?? []);
        $d['relationships'] = array_map(Relationship::fromArray(...), $d['relationships'] ?? []);
        $d['enums'] = array_map(EnumType::fromArray(...), $d['enums'] ?? []);
        $d['groups'] = array_map(TableGroup::fromArray(...), $d['groups'] ?? []);
        $d['rules'] = array_map(Rule::fromArray(...), $d['rules'] ?? []);
        return new self(...$d);
    }
}
