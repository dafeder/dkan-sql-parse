<?php

namespace SqlParserTest;

/**
 * Normalize input meant for the legacy SQL endpoint.
 *
 * The old DKAN SQL endpoint expected input to be separated into segments
 * wrapped in square brackets. For instance:
 *
 * `[SELECT * FROM table][WHERE column = 'value'][LIMIT 10 OFFSET 20]`
 */
class LegacyInputNormalizer
{
    public function supports(string $sql): bool
    {
        return substr($sql, 0, 1) === '[';
    }

    public function normalize(string $sql): string
    {
        // Remove the leading and trailing square brackets.
        $sql = trim($sql, '[]');

        // Remove '][' sequences within the SQL string.
        $sql = str_replace('][', ' ', $sql);

        return $sql;
    }
}
