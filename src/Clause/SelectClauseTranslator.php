<?php

declare(strict_types=1);

namespace SqlParserTest\Clause;

use PhpMyAdmin\SqlParser\Components\Expression;
use PhpMyAdmin\SqlParser\Statements\SelectStatement;
use SqlParserTest\Expression\AggregateFunctionTranslator;
use SqlParserTest\Expression\ArithmeticExpressionTranslator;
use SqlParserTest\Expression\ColumnReferenceTranslator;
use SqlParserTest\Expression\ExpressionTranslatorInterface;

class SelectClauseTranslator implements ClauseTranslatorInterface
{
    /** @var ExpressionTranslatorInterface[] */
    private array $expressionTranslators;

    /**
     * @param ExpressionTranslatorInterface[] $expressionTranslators
     */
    public function __construct(array $expressionTranslators = [])
    {
        $this->expressionTranslators = !empty($expressionTranslators) ? $expressionTranslators : [
            new ColumnReferenceTranslator(),
            new AggregateFunctionTranslator(),
            new ArithmeticExpressionTranslator(),
        ];
    }

    public function canTranslate(SelectStatement $statement): bool
    {
        return !empty($statement->expr);
    }

    public function translate(SelectStatement $statement, array &$query, ?string $resource = null): void
    {
        $properties = [];
        foreach ($statement->expr as $part) {
            if (!($part instanceof Expression)) {
                throw new \InvalidArgumentException('Unsupported SELECT expression type.');
            }

            $properties[] = $this->translateExpression($part);
        }

        $query['properties'] = array_filter($properties);
    }

    private function translateExpression(Expression $expr): ?array
    {
        foreach ($this->expressionTranslators as $translator) {
            if ($translator->supports($expr)) {
                return $translator->translate($expr);
            }
        }

        throw new \InvalidArgumentException('Invalid SELECT expression.');
    }
}
