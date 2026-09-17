<?php

declare(strict_types=1);

namespace SqlParserTest;

class IdentifierParser
{
    public const DEFAULT_RESOURCE = 't';

    /**
     * Parse and normalize an SQL identifier into resource and property parts.
     *
     * @return array{resource: string, property: string}
     */
    public static function parse(string $identifier): array
    {
        $clean = trim($identifier);
        $clean = ltrim($clean, '(');
        $clean = rtrim($clean, ')');
        $clean = trim($clean, " \t\n\r\0\x0B`");

        $parts = array_values(array_filter(explode('.', $clean), static fn ($part) => $part !== ''));
        if (empty($parts)) {
            throw new \InvalidArgumentException('Invalid SQL identifier.');
        }

        return [
            'resource' => count($parts) > 1 ? trim($parts[0], '`') : self::DEFAULT_RESOURCE,
            'property' => trim($parts[count($parts) - 1], '`'),
        ];
    }
}
