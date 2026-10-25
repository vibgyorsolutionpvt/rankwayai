<?php

namespace App\Support;

class PublicId
{
    public static function normalize(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' || $value === '0' ? null : $value;
    }
}
