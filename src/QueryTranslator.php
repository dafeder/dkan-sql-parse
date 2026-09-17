<?php

declare(strict_types=1);

namespace SqlParserTest;

use PhpMyAdmin\SqlParser\Statements\SelectStatement;
use SqlParserTest\Clause\ClauseTranslatorInterface;
use SqlParserTest\Clause\FromClauseTranslator;
use SqlParserTest\Clause\LimitClauseTranslator;
use SqlParserTest\Clause\OrderClauseTranslator;
use SqlParserTest\Clause\SelectClauseTranslator;
use SqlParserTest\Clause\WhereClauseTranslator;

/**
 * Orchestrates translation of phpmyadmin SQL parser statements into DatastoreQuery objects.
 */
class QueryTranslator
{
    /** @var ClauseTranslatorInterface[] */
    private array $clauseTranslators;

    /**
     * @param ClauseTranslatorInterface[] $clauseTranslators
     */
    public function __construct(array $clauseTranslators = [])
    {
        $this->clauseTranslators = !empty($clauseTranslators) ? $clauseTranslators : [
            new SelectClauseTranslator(),
            new FromClauseTranslator(),
            new WhereClauseTranslator(),
            new OrderClauseTranslator(),
            new LimitClauseTranslator(),
        ];
    }

    /**
     * Translate a phpmyadmin SelectStatement query.
     */
    public static function translateStatement(SelectStatement $statement, ?string $resource = null): DatastoreQuery
    {
        $translator = new self();
        return $translator->translate($statement, $resource);
    }

    /**
     * Translate a phpmyadmin SelectStatement into a DatastoreQuery.
     */
    public function translate(SelectStatement $statement, ?string $resource = null): DatastoreQuery
    {
        StatementGuard::validate($statement);

        $query = [];
        foreach ($this->clauseTranslators as $clauseTranslator) {
            if ($clauseTranslator->canTranslate($statement)) {
                $clauseTranslator->translate($statement, $query, $resource);
            }
        }

        $query = array_filter($query);
        return new DatastoreQuery($query);
    }
}
