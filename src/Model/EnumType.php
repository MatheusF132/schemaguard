<?php
declare(strict_types=1);
namespace SchemaGuard\Model;

final class EnumType
{
    use Arrayable;

    /** @param array<int, array{id:string,name:string,note:?string}> $values */
    public function __construct(
        public string $id,
        public string $name,
        public ?string $schema = null,
        public array $values = [],
    ) {}

    public static function fromArray(array $d): self
    {
        return new self(...$d);
    }
}
