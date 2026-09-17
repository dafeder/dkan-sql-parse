<?php

declare(strict_types=1);

namespace SqlParserTest;

class ValueNormalizer
{
    /**
     * Normalize a raw SQL scalar value to appropriate PHP type.
     *
     * @return string|int|float|bool
     */
    public static function normalize(string $raw): string|int|float|bool
    {
        $value = trim($raw);
        $value = ltrim($value, '(');
        $value = rtrim($value, ')');
        $value = trim($value);

        $lower = strtolower($value);
        if ($lower === 'true') {
            return true;
        }
        if ($lower === 'false') {
            return false;
        }

        if (is_numeric($value)) {
            return ((string) (int) $value === $value) ? (int) $value : (float) $value;
        }

        return trim($value, "'\"");
    }
}
