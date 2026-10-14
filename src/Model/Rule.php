<?php
declare(strict_types=1);
namespace SchemaGuard\Model;

/** Regra de validacao: o nivel decide em qual modo ela aparece. */
final class Rule
{
    use Arrayable;

    public function __construct(
        public string $code,
        public Level $level,
        public string $message,      // curta e simples
        public string $explanation,  // detalhada
        public ?string $check = null, // o que ela verifica
    ) {}

    public static function fromArray(array $d): self
    {
        $d['level'] = Level::from($d['level']);
        return new self(...$d);
    }
}
