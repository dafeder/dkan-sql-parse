<?php

declare(strict_types=1);

namespace SqlParserTest\Condition;

use PhpMyAdmin\SqlParser\Components\Condition;

class WhereClauseParser
{
    /** @var ConditionTranslatorInterface[] */
    private array $conditionTranslators;

    /**
     * @param ConditionTranslatorInterface[] $conditionTranslators
     */
    public function __construct(array $conditionTranslators = [])
    {
        $this->conditionTranslators = !empty($conditionTranslators) ? $conditionTranslators : [
            new ComparisonConditionTranslator(),
            new InListConditionTranslator(),
        ];
    }

    /**
     * @param Condition[] $where
     *
     * @return array<int, mixed>
     */
    public function parse(array $where): array
    {
        if (empty($where)) {
            throw new \InvalidArgumentException('Empty WHERE clause.');
        }

        $tokens = $this->tokenizeWhere($where);
        $index = 0;
        $tree = $this->parseWhereOr($tokens, $index);

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
    private function tokenizeWhere(array $where): array
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

            [$openParens, $closeParens] = $this->extractConditionParens($part);

            for ($i = 0; $i < $openParens; $i++) {
                $tokens[] = ['type' => 'lparen'];
            }

            $tokens[] = ['type' => 'condition', 'value' => $this->translateLeafCondition($part)];

            for ($i = 0; $i < $closeParens; $i++) {
                $tokens[] = ['type' => 'rparen'];
            }
        }

        return $tokens;
    }

    /**
     * @return array<string, mixed>
     */
    private function translateLeafCondition(Condition $condition): array
    {
        foreach ($this->conditionTranslators as $translator) {
            if ($translator->supports($condition)) {
                return $translator->translate($condition);
            }
        }

        throw new \InvalidArgumentException('Unsupported WHERE condition.');
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function extractConditionParens(Condition $condition): array
    {
        if (trim($condition->operator) !== '') {
            $open = $this->countLeadingParentheses(trim($condition->leftOperand));
            $close = $this->countTrailingParentheses(trim($condition->rightOperand));
            return [$open, $close];
        }

        $expr = trim($condition->expr);
        $open = $this->countLeadingParentheses($expr);
        $close = $this->countTrailingParentheses($expr);

        // IN/NOT IN always has a list-closing parenthesis that is not grouping.
        if (preg_match('/\bNOT\s+IN\s*\(|\bIN\s*\(/i', $expr)) {
            $close = max(0, $close - 1);
        }

        return [$open, $close];
    }

    private function countLeadingParentheses(string $expr): int
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

    private function countTrailingParentheses(string $expr): int
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
     * @return array<string, mixed>
     */
    private function parseWhereOr(array $tokens, int &$index): array
    {
        $left = $this->parseWhereAnd($tokens, $index);
        while ($this->matchWhereOperator($tokens, $index, 'or')) {
            $right = $this->parseWhereAnd($tokens, $index);
            $left = $this->mergeConditionNodes('or', $left, $right);
        }
        return $left;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseWhereAnd(array $tokens, int &$index): array
    {
        $left = $this->parseWhereFactor($tokens, $index);
        while ($this->matchWhereOperator($tokens, $index, 'and')) {
            $right = $this->parseWhereFactor($tokens, $index);
            $left = $this->mergeConditionNodes('and', $left, $right);
        }
        return $left;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseWhereFactor(array $tokens, int &$index): array
    {
        if (!isset($tokens[$index])) {
            throw new \InvalidArgumentException('Invalid WHERE clause.');
        }

        if ($tokens[$index]['type'] === 'lparen') {
            $index++;
            $node = $this->parseWhereOr($tokens, $index);
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

    private function matchWhereOperator(array $tokens, int &$index, string $operator): bool
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
    private function mergeConditionNodes(string $operator, array $left, array $right): array
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
}
