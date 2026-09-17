<?php

declare(strict_types=1);

namespace SqlParserTest\Clause;

use PhpMyAdmin\SqlParser\Statements\SelectStatement;
use SqlParserTest\Condition\WhereClauseParser;

class WhereClauseTranslator implements ClauseTranslatorInterface
{
    private WhereClauseParser $parser;

    public function __construct(?WhereClauseParser $parser = null)
    {
        $this->parser = $parser ?? new WhereClauseParser();
    }

    public function canTranslate(SelectStatement $statement): bool
    {
        return !empty($statement->where);
    }

    public function translate(SelectStatement $statement, array &$query, ?string $resource = null): void
    {
        if (!empty($statement->where)) {
            $query['conditions'] = $this->parser->parse($statement->where);
        }
    }
}
