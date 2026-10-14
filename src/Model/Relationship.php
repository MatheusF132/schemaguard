<?php
declare(strict_types=1);
namespace SchemaGuard\Model;

final class Relationship
{
    use Arrayable;

    /** @param string[] $fromColumns @param string[] $toColumns  (IDs; varias = chave composta) */
    public function __construct(
        public string $id,
        public string $fromTable,
        public array $fromColumns,
        public string $toTable,
        public array $toColumns,
        public Cardinality $cardinality = Cardinality::ManyToOne,
        public RefAction $onDelete = RefAction::NoAction,
        public RefAction $onUpdate = RefAction::NoAction,
        public ?string $name = null,
        public ?string $description = null,
    ) {}

    public static function fromArray(array $d): self
    {
        $d['cardinality'] = Cardinality::from($d['cardinality']);
        $d['onDelete'] = RefAction::from($d['onDelete']);
        $d['onUpdate'] = RefAction::from($d['onUpdate']);
        return new self(...$d);
    }
}
