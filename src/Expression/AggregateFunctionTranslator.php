<?php

declare(strict_types=1);

namespace SqlParserTest\Expression;

use PhpMyAdmin\SqlParser\Components\Expression;
use SqlParserTest\IdentifierParser;
use SqlParserTest\ValueNormalizer;

class AggregateFunctionTranslator implements ExpressionTranslatorInterface
{
    private const AGGREGATE_OPERATORS = ['sum', 'count', 'avg', 'max', 'min'];

    public function supports(Expression $expr): bool
    {
        return !empty($expr->function);
    }

    public function translate(Expression $expr): array
    {
        if (empty($expr->alias)) {
            throw new \InvalidArgumentException('Mathematical expressions must be aliased.');
        }

        $operator = strtolower((string) $expr->function);
        if (!in_array($operator, self::AGGREGATE_OPERATORS, true)) {
            throw new \InvalidArgumentException('Unsupported aggregate function.');
        }

        if (!preg_match('/^[A-Za-z_]+\s*\((.*)\)$/', trim((string) $expr->expr), $matches)) {
            throw new \InvalidArgumentException('Missing arguments for aggregate function.');
        }

        $argument = trim($matches[1]);
        if ($argument === '') {
            throw new \InvalidArgumentException('Missing arguments for aggregate function.');
        }

        $operand = $this->translateOperand($argument);
        if ($operand === null) {
            throw new \InvalidArgumentException('Mathmatical functions require property-specific arguments.');
        }

        return [
            'expression' => [
                'operator' => $operator,
                'operands' => [$operand],
            ],
            'alias' => $expr->alias,
        ];
    }

    private function translateOperand(string $raw)
    {
        $operand = trim($raw);
        if ($operand === '*') {
            return null;
        }

        if (preg_match('/^`?[A-Za-z_][A-Za-z0-9_]*`?(?:\.`?[A-Za-z_][A-Za-z0-9_]*`?)*$/', $operand)) {
            return IdentifierParser::parse($operand);
        }

        return ValueNormalizer::normalize($operand);
    }
}
