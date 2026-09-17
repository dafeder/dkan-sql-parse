<?php

declare(strict_types=1);

namespace SqlParserTest\Clause;

use PhpMyAdmin\SqlParser\Components\Limit;
use PhpMyAdmin\SqlParser\Statements\SelectStatement;

class LimitClauseTranslator implements ClauseTranslatorInterface
{
    public function canTranslate(SelectStatement $statement): bool
    {
        return $statement->limit instanceof Limit;
    }

    public function translate(SelectStatement $statement, array &$query, ?string $resource = null): void
    {
        if ($statement->limit instanceof Limit) {
            $query['limit'] = (int) $statement->limit->rowCount;
            $query['offset'] = (int) $statement->limit->offset;
        }
    }
}
