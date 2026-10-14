<?php
declare(strict_types=1);
namespace SchemaGuard\Model;

/** Gera IDs estaveis. Um ID nunca muda, nem quando o nome muda (rename). */
final class Id
{
    public static function generate(string $prefix): string
    {
        return $prefix . '_' . bin2hex(random_bytes(6));
    }
}
