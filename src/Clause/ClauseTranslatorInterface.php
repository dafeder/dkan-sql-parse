<?php

declare(strict_types=1);

namespace SqlParserTest\Clause;

use PhpMyAdmin\SqlParser\Statements\SelectStatement;

interface ClauseTranslatorInterface
{
    /**
     * Check if this translator should handle the given statement.
     */
    public function canTranslate(SelectStatement $statement): bool;

    /**
     * Translate the clause and populate the query array.
     *
     * @param array<string, mixed> $query
     */
    public function translate(SelectStatement $statement, array &$query, ?string $resource = null): void;
}
