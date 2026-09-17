<?php

declare(strict_types=1);

namespace SqlParserTest;

use PhpMyAdmin\SqlParser\Statements\SelectStatement;

class StatementGuard
{
    /**
     * Validate that the statement does not use unsupported SQL clauses.
     *
     * @throws \InvalidArgumentException
     */
    public static function validate(SelectStatement $statement): void
    {
        if (!empty($statement->join)) {
            throw new \InvalidArgumentException(
                'Joins are not permitted for this query; you have requested too many resources.'
            );
        }
        if (!empty($statement->group) || !empty($statement->having) || !empty($statement->union)) {
            throw new \InvalidArgumentException('Prohibited SQL clauses detected for current parser path.');
        }
    }
}
