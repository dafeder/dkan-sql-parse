<?php

namespace SqlParserTest;

use Symfony\Component\VarDumper\VarDumper;

/**
 * Simple iterator to move through tree and translate to DatastoreQuery format.
 */
class TreeTranslator
{
    const DEFAULT_RESOURCE = 't';
    const CONDITION_PROCESSOR = 'conditionBracketExpression';
    const CONDITION_GROUP_PROCESSOR = 'conditionGroupBracketExpression';
    const EXPRESSION_PROCESSOR = 'expressionBracketExpression';

    public static function translate($tree)
    {
        if (!is_array($tree)) {
            throw new \InvalidArgumentException("Invalid parsed tree.");
        }
        $translateFunc = self::getTranslateFunc($tree);
        return self::$translateFunc($tree);
    }

    public static function getTranslateFunc(array $tree)
    {
        if (!isset($tree['expr_type'])) {
            throw new \InvalidArgumentException("Invalid parsed tree.");
        }
        $translateFunc = lcfirst(implode('', array_map('ucfirst', explode('_', $tree['expr_type']))));
        $translateFunc = lcfirst(implode('', array_map('ucfirst', explode('-', $translateFunc))));
        return $translateFunc;
    }

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


    public static function operator($tree)
    {
        return strtolower($tree['base_expr']);
    }

    public static function getBracketExpressionProcessor($tree)
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

    public static function inList($tree)
    {
        $list = [];
        foreach ($tree['sub_tree'] as $subTree) {
            $list[] = self::translate($subTree);
        }
        return $list;
    }

    public static function aggregateFunction($tree)
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
            throw new \InvalidArgumentException("You may not perform aggregate functions without specific property arguments.");
        }

        $expression['alias'] = $tree['alias']['name'] ?? null;
        if (empty($expression['alias'])) {
            throw new \InvalidArgumentException("Mathematical expressions must be aliased.");
        }

        VarDumper::dump($expression);
        return array_filter($expression);

    }

    public static function bracketExpression($tree)
    {
        $func = self::getBracketExpressionProcessor($tree);
        return self::$func($tree);
    }

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

    public static function expressionBracketExpression($tree)
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

    public static function conditionGroupBracketExpression($tree)
    {
        $operators = self::gatherExpressionOperators($tree);
        if (count(array_unique($operators)) != 1) {
            throw new \Exception("Condition groups must contain a single boolean operator.");
        }

        $operands = self::getExpressionOperands($tree);
        $conditionGroup = ['groupOperator' => current($operators)];

        foreach ($operands as $operand) {
            $conditionGroup['conditions'][] = self::translate($operand);
        }
        return $conditionGroup;
    }

    public static function gatherExpressionOperators($tree)
    {
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
