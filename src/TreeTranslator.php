<?php

namespace SqlParserTest;

use Symfony\Component\VarDumper\VarDumper;

/**
 * Recursive processor to move through tree and translate to DatastoreQuery format.
 */
class TreeTranslator
{
    const DEFAULT_RESOURCE = 't';
    const CONDITION_PROCESSOR = 'conditionBracketExpression';
    const CONDITION_GROUP_PROCESSOR = 'conditionGroupBracketExpression';
    const EXPRESSION_PROCESSOR = 'expressionBracketExpression';

    /**
     * Translate an arbitrary tree.
     *
     * @param array $tree
     *   A PHPSQLParser tree.
     *
     * @return array
     *   The appropriate data for use in DatastoreQuery.
     */
    public static function translate($tree)
    {
        if (!is_array($tree)) {
            throw new \InvalidArgumentException("Invalid parsed tree.");
        }
        $translateFunc = self::getTranslateFunc($tree);
        return self::$translateFunc($tree);
    }

    /**
     * Get the method name to perform the appropriate translation.
     * @param array $tree
     *   A PHPSQLParser tree.
     *
     * @return string
     *   An existing method name.
     */
    public static function getTranslateFunc(array $tree): string
    {
        if (!isset($tree['expr_type'])) {
            throw new \InvalidArgumentException("Invalid parsed tree.");
        }
        $translateFunc = lcfirst(implode('', array_map('ucfirst', explode('_', $tree['expr_type']))));
        $translateFunc = lcfirst(implode('', array_map('ucfirst', explode('-', $translateFunc))));
        
        if (!method_exists(self::class, $translateFunc)) {
            throw new \InvalidArgumentException("Unsupported tree type.");
        }
        
        return $translateFunc;
    }

    /**
     * Translate a table.
     *
     * @param array $tree
     *   A "table" PHPSQLParser tree.
     *
     * @return array
     *   Resource array for use in DatastoreQuery.
     */
    public static function table(array $tree)
    {
        $resource = [];
        $resource['id'] = $tree['no_quotes']['parts'][0] ?? null;
        $resource['alias'] = $tree['alias']['name'] ?? 't';
        return array_filter($resource);
    }

    /**
     * Translate a column reference.
     *
     * @param array $tree
     *   A "colref" PHPSQLParser tree.
     *
     * @return array
     *   Property array for use in DatastoreQuery.
     */
    public static function colref(array $tree)
    {
        // If we just passed "*", no property array needed.
        if (self::colrefAllColumns($tree)) {
            return null;
        }
        $property = [];
        $parts = $tree['no_quotes']['parts'];
        $property['resource'] = count($parts) > 1 ? $parts[0] : self::DEFAULT_RESOURCE;
        $property['property'] = end($parts);
        $property['alias'] = $tree['alias']['name'] ?? null;
        $property['order'] = strtolower($tree['direction'] ?? null);
        return array_filter($property);
    }

    /**
     * Detect a "*" colref, which is treated slightly differently.
     *
     * @param array $tree
     *   A "colref" PHPSQLParser tree.
     *
     * @return bool
     *   Whether or not it's a "*" ref.
     */
    private static function colrefAllColumns(array $tree): bool
    {
        if (!isset($tree['no_quotes']) && $tree['base_expr'] == "*") {
            return true;
        }
        if (isset($tree['no_quotes']['parts'][1]) && $tree['no_quotes']['parts'][1] == "*") {
            return true;
        }
        return false;
    }

    /**
     * Translate an alias.
     *
     * @param array $tree
     *   An "alias" PHPSQLParser tree.
     *
     * @return array
     *   Property array for use in DatastoreQuery.
     */
    public static function alias(array $tree)
    {
        $property = [];
        $property['property'] = $tree['base_expr'];
        $property['order'] = strtolower($tree['direction'] ?? null);
        return array_filter($property);
    }

    /**
     * Translate a reserved keyword.
     *
     * @param array $tree
     *   An "reserved" PHPSQLParser tree.
     *
     * @return mixed
     *   Correct expression value; current only true and false supported.
     */
    public static function reserved(array $tree)
    {
        if (strtolower($tree['base_expr']) == "true") {
            return true;
        }
        if (strtolower($tree['base_expr']) == "false") {
            return false;
        } else {
            throw new \InvalidArgumentException("Unknown reserved word used in expression.");
        }
    }

    /**
     * Translate a constant (an arbitrary number or string).
     *
     * @param array $tree
     *
     * @return string|int|float
     *   A correctly-typed string or numerical value.
     */
    public static function const(array $tree)
    {
        $const = $tree['base_expr'];
        if (is_numeric($const)) {
            $const = $const == (int) $const ? (int) $const : (float) $const;
        } else {
            $const = trim($const, '\'"');
        }
        return $const;
    }


    /**
     * Translate an operator.
     *
     * @param array $tree
     *   An "operator" PHPSQLParser tree.
     *
     * @return string
     *   Operator for use in DatastoreQuery.
     */
    public static function operator(array $tree): string
    {
        return strtolower($tree['base_expr']);
    }

    /**
     * Get the correct method name for a bracket_expression.
     *
     * Three different object types from DatastoreQuery are represented as
     * bracket_expression trees in PHPSQLParser. They can be distinguished
     * by the types of operators they use.
     *
     * @param array $tree
     *   A "bracket_expression" PHPSQLParser tree.
     *
     * @return string
     *   Valid public static method name from this class.
     */
    public static function getBracketExpressionProcessor(array $tree): string
    {
        $operators = self::gatherExpressionOperators($tree);

        // We should only get one match. If > 1, invalid mix. If 0, invalid tree.
        $matches = [];
        if (!empty(array_intersect($operators, DatastoreQuery::conditionOperators()))) {
            $matches[] = self::CONDITION_PROCESSOR;
        }
        if (!empty(array_intersect($operators, DatastoreQuery::conditionGroupOperators()))) {
            $matches[] = self::CONDITION_GROUP_PROCESSOR;
        }
        if (!empty(array_intersect($operators, DatastoreQuery::expressionOperators()))) {
            $matches[] = self::EXPRESSION_PROCESSOR;
        }

        if (count($matches) > 1) {
            throw new \DomainException("Invalid mix of expressions. Try separating expressions with parentheses.");
        } elseif (empty($matches)) {
            throw new \DomainException("No valid operators found in expression.");
        } else {
            return current($matches);
        }
    }

    /**
     * Translate a in-list.
     *
     * @param array $tree
     *   An "in-list" PHPSQLParser tree.
     *
     * @return array
     *   Array of possible values for use with "in" operator.
     */
    public static function inList(array $tree): array
    {
        $list = [];
        foreach ($tree['sub_tree'] as $subTree) {
            $list[] = self::translate($subTree);
        }
        return $list;
    }

    /**
     * Translate an aggregate function.
     *
     * @param array $tree
     *   An "aggregate_function" PHPSQLParser tree.
     *
     * @return array
     *   Aggregate expression for use in DatastoreQuery.
     */
    public static function aggregateFunction(array $tree): array
    {
        $expression = ['expression' => ['operator' => strtolower($tree['base_expr'])]];

        if (empty($tree['sub_tree'])) {
            throw new \InvalidArgumentException("Missing arguments for aggregate function.");
        }
        foreach ($tree['sub_tree'] as $operand) {
            $expression['expression']['operands'][] = self::translate($operand);
        }
    
        // Check for missing operands.
        $expression['expression']['operands'] = array_filter($expression['expression']['operands']);
        if (empty($expression['expression']['operands'])) {
            throw new \InvalidArgumentException("Mathmatical functions require property-specific arguments.");
        }

        $expression['alias'] = $tree['alias']['name'] ?? null;
        if (empty($expression['alias'])) {
            throw new \InvalidArgumentException("Mathematical expressions must be aliased.");
        }

        return array_filter($expression);
    }

    /**
     * Translate a bracketed expression.
     *
     * Routed to one of the more specific translator funcitons by
     * self::getBracketExpressionProcessor().
     *
     * @param array $tree
     *   A "bracket_expression" PHPSQLParser tree.
     *
     * @return array
     *   Operator for use in DatastoreQuery.
     */
    public static function bracketExpression(array $tree): array
    {
        $func = self::getBracketExpressionProcessor($tree);
        return self::$func($tree);
    }

    /**
     * Translate a bracketed expression that maps to a "condition".
     *
     * @param array $tree
     *   A "bracket_expression" PHPSQLParser tree.
     *
     * @return array
     *   Condition for use in DatastoreQuery.
     */
    public static function conditionBracketExpression($tree)
    {
        $property = self::translate($tree['sub_tree'][0]);
        $condition = [
            'resource' => $property['resource'] ?? null,
            'property' => $property['property'],
            'operator' => self::translate($tree['sub_tree'][1]),
            'value' => self::translate($tree['sub_tree'][2]),
        ];
        return array_filter($condition);
    }

    /**
     * Translate a bracketed expression that maps to an "expression".
     *
     * @param array $tree
     *   A "bracket_expression" PHPSQLParser tree.
     *
     * @return array
     *   Math expression for use as DatastoreQuery property.
     */
    public static function expressionBracketExpression(array $tree): array
    {
        $subTree = $tree['sub_tree'];
        $expression = ['expression' => ['operator' => self::translate($subTree[1])]];
        unset($subTree[1]);

        foreach ($subTree as $operand) {
            $expression['expression']['operands'][] = self::translate($operand);
        }
        $expression['alias'] = $tree['alias']['name'] ?? null;
        if (empty($expression['alias'])) {
            throw new \InvalidArgumentException("Mathematical expressions must be aliased.");
        }

        return array_filter($expression);
    }

    /**
     * Translate a bracketed expression that maps to a "conditionGroup".
     *
     * @param array $tree
     *   A "bracket_expression" PHPSQLParser tree.
     *
     * @return array
     *   Condition group for use in DatastoreQuery conditions array.
     */
    public static function conditionGroupBracketExpression(array $tree): array
    {
        $operators = self::gatherExpressionOperators($tree);
        if (count(array_unique($operators)) != 1) {
            throw new \Exception("Condition groups must not mix boolean operators.");
        }

        $operands = self::getExpressionOperands($tree);
        $conditionGroup = ['groupOperator' => current($operators)];

        foreach ($operands as $operand) {
            $conditionGroup['conditions'][] = self::translate($operand);
        }
        return $conditionGroup;
    }

    /**
     * Create an array of operator strings from a bracket expression.
     *
     * @param array $tree
     *   A "bracket_expression" PHPSQLParser tree.
     *
     * @return array
     *   An array of operators (strings) used in an expression.
     */
    public static function gatherExpressionOperators(array $tree): array
    {
        $operators = [];
        foreach ($tree['sub_tree'] as $part) {
            if ($part['expr_type'] == 'operator') {
                $operators[] = self::translate($part);
            }
        }
        return $operators;
    }

    /**
     * Extract an array of expression subtree elements, without operators.
     *
     * @param mixed $tree
     *   A bracket_expression PHPSQLParser tree.
     *
     * @return array
     *   An array of expression operands (NOT a full tree).
     */
    public static function getExpressionOperands(array $tree)
    {
        $operands = [];
        foreach ($tree['sub_tree'] as $part) {
            $func = self::getTranslateFunc($part);
            if ($func != 'operator') {
                $operands[] = $part;
            }
        }
        return $operands;
    }
}
