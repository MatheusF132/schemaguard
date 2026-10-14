<?php
declare(strict_types=1);
namespace SchemaGuard\Model;

/** Converte o objeto (e o que ele contem) em array pronto para JSON. */
trait Arrayable
{
    public function toArray(): array
    {
        $out = [];
        foreach (get_object_vars($this) as $key => $value) {
            $out[$key] = self::normalize($value);
        }
        return $out;
    }

    private static function normalize(mixed $v): mixed
    {
        if ($v instanceof \BackedEnum) return $v->value;
        if (is_object($v)) return $v->toArray();
        if (is_array($v)) return array_map(fn($i) => self::normalize($i), $v);
        return $v;
    }
}
