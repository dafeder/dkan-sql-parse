<?php

namespace SqlParserTest;

use PhpMyAdmin\SqlParser\Components\Condition;
use PhpMyAdmin\SqlParser\Components\Expression;
use PhpMyAdmin\SqlParser\Components\Limit;
use PhpMyAdmin\SqlParser\Components\OrderKeyword;
use PhpMyAdmin\SqlParser\Statements\SelectStatement;

/**
 * Translate phpmyadmin SQL parser statements into DatastoreQuery objects.
 */
class QueryTranslator
{
    private const DEFAULT_RESOURCE = 't';
    private const ARITHMETIC_OPERATORS = ['+', '-', '*', '/', '%'];
    private const AGGREGATE_OPERATORS = ['sum', 'count', 'avg', 'max', 'min'];

    /**
     * Translate a phpmyadmin SelectStatement query.
     */
    public static function translateStatement(SelectStatement $statement, ?string $resource = null): DatastoreQuery
    {
        self::validateStatementClauses($statement);
        $query = [];

        if (!empty($statement->expr)) {
            $query['properties'] = self::translateStatementSelect($statement->expr);
        }
        if (!empty($statement->from)) {
            $query['resources'] = self::translateStatementFrom($statement->from);
        }
        self::incorporateStatementResource($query, $resource, $statement);
        if (!empty($statement->where)) {
            $query['conditions'] = self::translateStatementWhere($statement->where);
        }
        if (!empty($statement->order)) {
            $query['sorts'] = self::translateStatementOrder($statement->order);
        }
        if ($statement->limit instanceof Limit) {
            $query['limit'] = (int) $statement->limit->rowCount;
            $query['offset'] = (int) $statement->limit->offset;
        }

        $query = array_filter($query);
        return new DatastoreQuery($query);
    }

    /**
     * @param Expression[] $select
     */
    private static function translateStatementSelect(array $select): array
    {
        $properties = [];
        foreach ($select as $part) {
            if (!($part instanceof Expression)) {
                throw new \InvalidArgumentException('Unsupported SELECT expression type.');
            }
            $properties[] = self::translateStatementSelectExpression($part);
        }
        return array_filter($properties);
    }

    private static function translateStatementSelectExpression(Expression $expr)
    {
        if (($expr->column === '*') || (trim((string) $expr->expr) === '*')) {
            return null;
        }

        if (!empty($expr->column)) {
            $property = [
                'resource' => !empty($expr->table) ? $expr->table : self::DEFAULT_RESOURCE,
                'property' => $expr->column,
                'alias' => $expr->alias ?: null,
            ];
            return array_filter($property);
        }

        if (!empty($expr->expr)) {
            return self::translateComputedStatementSelectExpression($expr);
        }

        throw new \InvalidArgumentException('Invalid SELECT expression.');
    }

    private static function translateComputedStatementSelectExpression(Expression $expr): array
    {
        if (empty($expr->alias)) {
            throw new \InvalidArgumentException('Mathematical expressions must be aliased.');
        }

        if (!empty($expr->function)) {
            $expression = self::translateAggregateExpression($expr);
            return [
                'expression' => $expression,
                'alias' => $expr->alias,
            ];
        }

        $expression = self::translateArithmeticExpression($expr->expr);
        return [
            'expression' => $expression,
            'alias' => $expr->alias,
        ];
    }

    /**
     * @return array{operator: string, operands: array<int, mixed>}
     */
    private static function translateAggregateExpression(Expression $expr): array
    {
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

        $operand = self::translateExpressionOperand($argument);
        if ($operand === null) {
            throw new \InvalidArgumentException('Mathmatical functions require property-specific arguments.');
        }

        return [
            'operator' => $operator,
            'operands' => [$operand],
        ];
    }

    /**
     * @return array{operator: string, operands: array<int, mixed>}
     */
    private static function translateArithmeticExpression(string $expr): array
    {
        $expression = self::trimWrappingParentheses(trim($expr));
        [$operator, $left, $right] = self::splitTopLevelArithmeticExpression($expression);

        return [
            'operator' => $operator,
            'operands' => [
                self::translateExpressionOperand($left),
                self::translateExpressionOperand($right),
            ],
        ];
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private static function splitTopLevelArithmeticExpression(string $expr): array
    {
        foreach ([['+', '-'], ['*', '/', '%']] as $ops) {
            $depth = 0;
            $length = strlen($expr);
            for ($i = 0; $i < $length; $i++) {
                $char = $expr[$i];
                if ($char === '(') {
                    $depth++;
                    continue;
                }
                if ($char === ')') {
                    $depth--;
                    continue;
                }
                if ($depth !== 0 || !in_array($char, $ops, true)) {
                    continue;
                }
                $left = trim(substr($expr, 0, $i));
                $right = trim(substr($expr, $i + 1));
                if ($left === '' || $right === '') {
                    throw new \InvalidArgumentException('Invalid arithmetic expression.');
                }
                return [$char, $left, $right];
            }
        }

        throw new \InvalidArgumentException('Invalid arithmetic expression.');
    }

    private static function trimWrappingParentheses(string $expr): string
    {
        while (
            strlen($expr) > 1
            && $expr[0] === '('
            && $expr[strlen($expr) - 1] === ')'
            && self::isWrappedBySingleParenthesisPair($expr)
        ) {
            $expr = trim(substr($expr, 1, -1));
        }

        return $expr;
    }

    private static function isWrappedBySingleParenthesisPair(string $expr): bool
    {
        $depth = 0;
        $length = strlen($expr);
        for ($i = 0; $i < $length; $i++) {
            $char = $expr[$i];
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
                if ($depth === 0 && $i < $length - 1) {
                    return false;
                }
            }
        }

        return $depth === 0;
    }

    /**
     * @return array<string, mixed>|string|int|float|bool|null
     */
    private static function translateExpressionOperand(string $raw)
    {
        $operand = trim($raw);
        if ($operand === '*') {
            return null;
        }

        $operand = self::trimWrappingParentheses($operand);
        if (self::containsTopLevelArithmeticOperator($operand)) {
            return ['expression' => self::translateArithmeticExpression($operand)];
        }

        if (self::looksLikeIdentifier($operand)) {
            return self::translateStatementIdentifier($operand);
        }

        return self::normalizeStatementValue($operand);
    }

    private static function containsTopLevelArithmeticOperator(string $expr): bool
    {
        $depth = 0;
        $length = strlen($expr);
        for ($i = 0; $i < $length; $i++) {
            $char = $expr[$i];
            if ($char === '(') {
                $depth++;
                continue;
            }
            if ($char === ')') {
                $depth--;
                continue;
            }
            if ($depth === 0 && in_array($char, self::ARITHMETIC_OPERATORS, true)) {
                return true;
            }
        }

        return false;
    }

    private static function looksLikeIdentifier(string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }
        if (is_numeric($value)) {
            return false;
        }
        if ($value[0] === '\'' || $value[0] === '"') {
            return false;
        }

        return (bool) preg_match('/^`?[A-Za-z_][A-Za-z0-9_]*`?(?:\.`?[A-Za-z_][A-Za-z0-9_]*`?)*$/', $value);
    }

    /**
     * @param Expression[] $from
     */
    private static function translateStatementFrom(array $from): array
    {
        if (count($from) > 1) {
            throw new \Exception('Joins are not permitted for this query; you have requested too many resources.');
        }

        $resources = [];
        foreach ($from as $part) {
            if (!($part instanceof Expression) || empty($part->table)) {
                throw new \InvalidArgumentException('Invalid FROM clause.');
            }
            $resources[] = [
                'id' => $part->table,
                'alias' => $part->alias
                    ?: self::DEFAULT_RESOURCE,
            ];
        }
        return $resources;
    }

    private static function incorporateStatementResource(
        array &$query,
        ?string $resource,
        SelectStatement $statement
    ): void {
        if ($resource && !empty($statement->from)) {
            throw new \InvalidArgumentException('You may not pass a FROM clause in a resource query.');
        }
        if ($resource) {
            $query['resources'] = [
                [
                    'id' => $resource,
                    'alias' => self::DEFAULT_RESOURCE,
                ],
            ];
        }
    }

    /**
     * @param OrderKeyword[] $order
     */
    private static function translateStatementOrder(array $order): array
    {
        $sorts = [];
        foreach ($order as $part) {
            if (!($part instanceof OrderKeyword) || !($part->expr instanceof Expression)) {
                throw new \InvalidArgumentException('Invalid ORDER clause.');
            }

            $property = $part->expr->column ?: trim((string) $part->expr->expr);
            if (empty($property)) {
                throw new \InvalidArgumentException('Invalid ORDER clause.');
            }

            $sorts[] = [
                'resource' => !empty($part->expr->table)
                    ? $part->expr->table
                    : self::DEFAULT_RESOURCE,
                'property' => $property,
                'order' => strtolower($part->type->value),
            ];
        }
        return $sorts;
    }

    private static function validateStatementClauses(
        SelectStatement $statement
    ): void {
        if (!empty($statement->join)) {
            throw new \InvalidArgumentException(
                'Joins are not permitted for this query; you have requested too many resources.'
            );
        }
        if (!empty($statement->group) || !empty($statement->having) || !empty($statement->union)) {
            throw new \InvalidArgumentException('Prohibited SQL clauses detected for current parser path.');
        }
    }

    /**
     * @param Condition[] $where
     */
    private static function translateStatementWhere(array $where): array
    {
        if (empty($where)) {
            throw new \InvalidArgumentException('Empty WHERE clause.');
        }

        $tokens = self::tokenizeWhere($where);
        $index = 0;
        $tree = self::parseWhereOr($tokens, $index);

        if ($index !== count($tokens)) {
            throw new \InvalidArgumentException('Invalid WHERE clause.');
        }

        if (isset($tree['groupOperator']) && $tree['groupOperator'] === 'and') {
            return $tree['conditions'];
        }

        return [$tree];
    }

    /**
     * @param Condition[] $where
     *
     * @return array<int, array<string, mixed>>
     */
    private static function tokenizeWhere(array $where): array
    {
        $tokens = [];
        foreach ($where as $part) {
            if (!($part instanceof Condition)) {
                throw new \InvalidArgumentException('Invalid WHERE clause.');
            }

            if ($part->isOperator) {
                $operator = strtolower(trim($part->expr));
                if ($operator !== 'and' && $operator !== 'or') {
                    throw new \InvalidArgumentException('Invalid WHERE clause.');
                }
                $tokens[] = ['type' => 'operator', 'value' => $operator];
                continue;
            }

            [$openParens, $closeParens] = self::extractConditionParens($part);

            for ($i = 0; $i < $openParens; $i++) {
                $tokens[] = ['type' => 'lparen'];
            }

            $tokens[] = ['type' => 'condition', 'value' => self::translateStatementCondition($part)];

            for ($i = 0; $i < $closeParens; $i++) {
                $tokens[] = ['type' => 'rparen'];
            }
        }

        return $tokens;
    }

    private static function countLeadingParentheses(string $expr): int
    {
        $count = 0;
        $length = strlen($expr);
        for ($i = 0; $i < $length; $i++) {
            if ($expr[$i] !== '(') {
                break;
            }
            $count++;
        }
        return $count;
    }

    private static function countTrailingParentheses(string $expr): int
    {
        $count = 0;
        for ($i = strlen($expr) - 1; $i >= 0; $i--) {
            if ($expr[$i] !== ')') {
                break;
            }
            $count++;
        }
        return $count;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private static function extractConditionParens(Condition $condition): array
    {
        if (trim($condition->operator) !== '') {
            $open = self::countLeadingParentheses(trim($condition->leftOperand));
            $close = self::countTrailingParentheses(trim($condition->rightOperand));
            return [$open, $close];
        }

        $expr = trim($condition->expr);
        $open = self::countLeadingParentheses($expr);
        $close = self::countTrailingParentheses($expr);

        // IN/NOT IN always has a list-closing parenthesis that is not grouping.
        if (preg_match('/\bNOT\s+IN\s*\(|\bIN\s*\(/i', $expr)) {
            $close = max(0, $close - 1);
        }

        return [$open, $close];
    }

    /**
     * @return array<string, mixed>
     */
    private static function parseWhereOr(array $tokens, int &$index): array
    {
        $left = self::parseWhereAnd($tokens, $index);
        while (self::matchWhereOperator($tokens, $index, 'or')) {
            $right = self::parseWhereAnd($tokens, $index);
            $left = self::mergeConditionNodes('or', $left, $right);
        }
        return $left;
    }

    /**
     * @return array<string, mixed>
     */
    private static function parseWhereAnd(array $tokens, int &$index): array
    {
        $left = self::parseWhereFactor($tokens, $index);
        while (self::matchWhereOperator($tokens, $index, 'and')) {
            $right = self::parseWhereFactor($tokens, $index);
            $left = self::mergeConditionNodes('and', $left, $right);
        }
        return $left;
    }

    /**
     * @return array<string, mixed>
     */
    private static function parseWhereFactor(array $tokens, int &$index): array
    {
        if (!isset($tokens[$index])) {
            throw new \InvalidArgumentException('Invalid WHERE clause.');
        }

        if ($tokens[$index]['type'] === 'lparen') {
            $index++;
            $node = self::parseWhereOr($tokens, $index);
            if (!isset($tokens[$index]) || $tokens[$index]['type'] !== 'rparen') {
                throw new \InvalidArgumentException('Invalid WHERE clause.');
            }
            $index++;
            return $node;
        }

        if ($tokens[$index]['type'] !== 'condition') {
            throw new \InvalidArgumentException('Invalid WHERE clause.');
        }

        $node = $tokens[$index]['value'];
        $index++;
        return $node;
    }

    private static function matchWhereOperator(array $tokens, int &$index, string $operator): bool
    {
        if (!isset($tokens[$index])) {
            return false;
        }
        if ($tokens[$index]['type'] !== 'operator') {
            return false;
        }
        if ($tokens[$index]['value'] !== $operator) {
            return false;
        }
        $index++;
        return true;
    }

    /**
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     *
     * @return array<string, mixed>
     */
    private static function mergeConditionNodes(string $operator, array $left, array $right): array
    {
        $conditions = [];
        if (isset($left['groupOperator']) && $left['groupOperator'] === $operator) {
            $conditions = array_merge($conditions, $left['conditions']);
        } else {
            $conditions[] = $left;
        }

        if (isset($right['groupOperator']) && $right['groupOperator'] === $operator) {
            $conditions = array_merge($conditions, $right['conditions']);
        } else {
            $conditions[] = $right;
        }

        return [
            'groupOperator' => $operator,
            'conditions' => $conditions,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function translateStatementCondition(Condition $condition): array
    {
        $operator = strtolower(trim($condition->operator));
        if ($operator === '') {
            return self::translateStatementInCondition($condition);
        }

        $property = self::translateStatementIdentifier($condition->leftOperand);
        $translated = [
            'resource' => $property['resource'],
            'property' => $property['property'],
            'operator' => $operator,
            'value' => self::normalizeStatementValue($condition->rightOperand),
        ];
        return array_filter($translated);
    }

    /**
     * @return array<string, mixed>
     */
    private static function translateStatementInCondition(Condition $condition): array
    {
        $expr = trim($condition->expr);

        if (!preg_match('/^(.+?)\s+(NOT\s+IN|IN)\s*\((.*)\)$/i', (string) $expr, $matches)) {
            throw new \InvalidArgumentException('Unsupported WHERE condition.');
        }

        $property = self::translateStatementIdentifier($matches[1]);
        $operator = strtolower(preg_replace('/\s+/', ' ', $matches[2]));

        $values = [];
        $rawValues = str_getcsv($matches[3], ',');
        foreach ($rawValues as $rawValue) {
            $values[] = self::normalizeStatementValue($rawValue);
        }

        $translated = [
            'resource' => $property['resource'],
            'property' => $property['property'],
            'operator' => $operator,
            'value' => $values,
        ];
        return array_filter($translated);
    }

    /**
     * @return array{resource: string, property: string}
     */
    private static function translateStatementIdentifier(string $identifier): array
    {
        $clean = trim($identifier);
        $clean = ltrim($clean, '(');
        $clean = rtrim($clean, ')');
        $clean = trim($clean, " \t\n\r\0\x0B`");

        $parts = array_values(array_filter(explode('.', $clean), static fn ($part) => $part !== ''));
        if (empty($parts)) {
            throw new \InvalidArgumentException('Invalid WHERE identifier.');
        }

        return [
            'resource' => count($parts) > 1 ? trim($parts[0], '`') : self::DEFAULT_RESOURCE,
            'property' => trim($parts[count($parts) - 1], '`'),
        ];
    }

    /**
     * @return string|int|float|bool
     */
    private static function normalizeStatementValue(string $raw)
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
