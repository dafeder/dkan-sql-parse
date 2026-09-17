<?php

namespace SqlParserTest;

use PhpMyAdmin\SqlParser\Components\Expression;
use PhpMyAdmin\SqlParser\Components\Condition;
use PhpMyAdmin\SqlParser\Components\Limit;
use PhpMyAdmin\SqlParser\Components\OrderKeyword;
use PhpMyAdmin\SqlParser\Statements\SelectStatement;

/**
 * Translate a full parsed query.
 */
class QueryTranslator
{
    private const DEFAULT_RESOURCE = 't';

    private ?string $resource;
    private array $parsed;
    private bool $allowJoins;

    /**
     * Translate a parsed SQL query.
     *
     * @param array $parsed
     *   The result of a PHPSQLParser operation on a SQL string.
     * @param string|null $resource
     *   A resource ID for the Datastore.
     * @param bool $allowJoins
     *   Whether joins are allowed or not in this query; defaults to false.
     *
     * @return DatastoreQuery
     *   A valid DatastoreQuery object.
     */
    public static function translate(array $parsed, $resource = null, bool $allowJoins = false)
    {
        $translator = new static($parsed, $resource, $allowJoins);
        return $translator->translateParsed();
    }

    /**
     * Translate a phpmyadmin SelectStatement query.
     *
     * Slice 2 intentionally supports SELECT/FROM/ORDER/LIMIT.
     * WHERE and richer clauses are implemented in the next slice.
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
            throw new \InvalidArgumentException(
                'Unsupported SELECT expression for current parser path.'
            );
        }
        throw new \InvalidArgumentException('Invalid SELECT expression.');
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
    ): void
    {
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
    ): void
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

    /**
     * Constructor.
     *
     * @param array $parsed
     *   The result of a PHPSQLParser operation on a SQL string.
     * @param string|null $resource
     *   A resource ID for the Datastore.
     * @param bool $allowJoins
     *   Whether joins are allowed or not in this query; defaults to false.
     */
    public function __construct(array $parsed, $resource = null, bool $allowJoins = false)
    {
        $this->resource = $resource;
        $this->parsed = $parsed;
        $this->allowJoins = $allowJoins;
    }

    /**
     * Translate the loaded parsed query to a DatastoreQuery obejct.
     *
     * @return DatastoreQuery
     *   Valid DatastoreQuery object.
     */
    public function translateParsed(): DatastoreQuery
    {
        $query = [];

        $this->validateParsedClauses();

        if (isset($this->parsed['SELECT'])) {
            $query['properties'] = $this->translateSelect($this->parsed['SELECT']);
        }
        if (isset($this->parsed['FROM'])) {
            $query['resources'] = $this->translateFrom($this->parsed['FROM']);
            $query['joins'] = $this->translateFromJoins($this->parsed['FROM']);
        }
        $this->incorporateResource($query);
        if (isset($this->parsed['WHERE'])) {
            $query['conditions'] = $this->translateWhere($this->parsed['WHERE']);
        }
        if (isset($this->parsed['LIMIT'])) {
            $query['limit'] = $this->translateLimit($this->parsed['LIMIT']);
            $query['offset'] = $this->translateLimitOffset($this->parsed['LIMIT']);
        }
        if (isset($this->parsed['ORDER'])) {
            $query['sorts'] = $this->translateOrder($this->parsed['ORDER']);
        }

        $query = array_filter($query);
        return new DatastoreQuery($query);
    }

    /**
     * Throws an exception if unallowed clauses detected.
     *
     * @return true
     *   True if clauses all passed.
     *
     * @throws \InvalidArgumentException
     */
    private function validateParsedClauses()
    {
        $clauses = array_keys($this->parsed);
        $allowed = ['SELECT', 'FROM', 'WHERE', 'LIMIT', 'ORDER'];
        $diff = array_diff($clauses, $allowed);
        if (count($diff)) {
            $bad = implode(", ", $diff);
            throw new \InvalidArgumentException("Prohibited SQL clauses detected: $bad");
        }
        return true;
    }

    /**
     * Translate the FROM clause.
     *
     * @param array $from
     *   FROM array from a full PHPSQLParser array.
     *
     * @return array
     *   Array of DatastoreQuery resources.
     */
    private function translateFrom(array $from): array
    {
        $this->incorporateResource($from);
        $this->validateJoins($from);
        $resources = [];
        foreach ($from as $resource) {
            $resources[] = TreeTranslator::translate($resource);
        }
        return array_filter($resources);
    }

    private function incorporateResource(array &$query)
    {
        if ($this->resource && isset($this->parsed['FROM'])) {
            throw new \InvalidArgumentException("You may not pass a FROM clause in a resource query.");
        } elseif ($this->resource) {
            $query['resources'] = [
                [
                    'id' => $this->resource,
                    'alias' => 't',
                ],
            ];
        }
    }

    /**
     * Translate the FROM clause to joins on another pass.
     *
     * @param array $from
     *   FROM array from a full PHPSQLParser array.
     *
     * @return array
     *   Array of DatastoreQuery joins.
     *
     * @todo Add actual JOIN support.
     */
    private function translateFromJoins(array $from)
    {
        if ($this->addJoins()) {
            throw new \Exception("Joins not yet supported in SQL queries.");
        } else {
            return null;
        }
    }

    /**
     * Check whether or not to add a joins array to the query.
     *
     * @return bool
     *   True if we should attempt to add joins.
     */
    private function addJoins(): bool
    {
        return (
            !empty($this->parsed['FROM'])
            && $this->allowJoins
            && count($this->parsed['FROM']) > 1
        );
    }

    /**
     * Ensure the FROM clause is valid given the allowJoins argument.
     *
     * @param array $from
     *   FROM array from a full PHPSQLParser array.
     *
     * @return bool
     *   Returns true if the FROM array is valid for this query.
     *
     * @throws \Exception
     *   This method will throw an exception if the FROM array violates the rules.
     */
    private function validateJoins(array $from)
    {
        if ($this->allowJoins) {
            return true;
        }
        if (count($from) > 1) {
            throw new \Exception("Joins are not permitted for this query; you have requested too many resources.");
        }
        return true;
    }

    /**
     * Translate the SELECT clause.
     *
     * @param array $select
     *   FROM array from a full PHPSQLParser array.
     *
     * @return array
     *   Array of DatastoreQuery properties.
     */
    private function translateSelect(array $select): array
    {
        $properties = [];
        try {
            foreach ($select as $property) {
                $properties[] = TreeTranslator::translate($property);
            }
        } catch (\Exception $e) {
            throw new \InvalidArgumentException("Invalid SELECT clause. " . $e->getMessage());
        }
        return array_filter($properties);
    }


    /**
     * WHERE clause requires more complex logic to break up.
     *
     * @param array $where
     *   FROM array from a full PHPSQLParser array.
     *
     * @return array
     *   Conditions array for DatastoreQuery.
     */
    private function translateWhere(array $where)
    {
        if (!is_array($where) || empty($where)) {
            throw new \InvalidArgumentException("Invalid WHERE clause.");
        }

        // If there's only one item in the where array, it must be
        // a single bracket expression.
        if (count($where) == 1) {
            return [TreeTranslator::translate($where[0])];
        }

        // Otherwise, it's some kind of expression.
        $whereGroup = TreeTranslator::translate([
            'expr_type' => 'bracket_expression',
            'sub_tree' => $where
        ]);
        // If it's a group with "and" operator, can be a simple array.
        if (isset($whereGroup['groupOperator']) && $whereGroup['groupOperator'] == 'and') {
            return $whereGroup['conditions'];
        }
        // Otherwise, it's either a single condition or a valid condition group.
        return [$whereGroup];
    }

    /**
     * Translate the LIMIT clause to a value for DatastoreQuery "limit".
     *
     * @param array $limit
     *   LIMIT array from a full PHPSQLParser array.
     *
     * @return int|null
     *   A limit value, if present.
     */
    private function translateLimit($limit)
    {
        return ((int) $limit['rowcount']) ?? null;
    }

    /**
     * Translate the LIMIT clause to a value for DatastoreQuery "offset".
     *
     * @param array $limit
     *   LIMIT array from a full PHPSQLParser array.
     *
     * @return int|null
     *   An offset value, if present.
     */
    private function translateLimitOffset($limit)
    {
        return ((int) $limit['offset']) ?? null;
    }

    /**
     * Translate the ORDER clause to DatastoreQuery "sorts".
     *
     * @param array $order
     *   ORDER array from a full PHPSQLParser array.
     *
     * @return array
     *   An array of sort arrays for DatastoreQuery.
     */
    private function translateOrder(array $order): array
    {
        $sorts = [];
        try {
            foreach ($order as $sort) {
                $sorts[] = TreeTranslator::translate($sort);
            }
        } catch (\Exception $e) {
            throw new \InvalidArgumentException("Invalid ORDER clause. " . $e->getMessage());
        }
        return array_filter($sorts);
    }
}
