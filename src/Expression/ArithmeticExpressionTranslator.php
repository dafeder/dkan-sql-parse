<?php

declare(strict_types=1);

namespace SqlParserTest\Expression;

use PhpMyAdmin\SqlParser\Components\Expression;
use SqlParserTest\IdentifierParser;
use SqlParserTest\ValueNormalizer;

class ArithmeticExpressionTranslator implements ExpressionTranslatorInterface
{
    private const ARITHMETIC_OPERATORS = ['+', '-', '*', '/', '%'];

    public function supports(Expression $expr): bool
    {
        if (!empty($expr->function) || !empty($expr->column)) {
            return false;
        }

        return !empty($expr->expr) && trim((string) $expr->expr) !== '*';
    }

    public function translate(Expression $expr): array
    {
        if (empty($expr->alias)) {
            throw new \InvalidArgumentException('Mathematical expressions must be aliased.');
        }

        return [
            'expression' => $this->translateExpression($expr->expr),
            'alias' => $expr->alias,
        ];
    }

    /**
     * @return array{operator: string, operands: array<int, mixed>}
     */
    public function translateExpression(string $expr): array
    {
        $expression = $this->trimWrappingParentheses(trim($expr));
        [$operator, $left, $right] = $this->splitTopLevelArithmeticExpression($expression);

        return [
            'operator' => $operator,
            'operands' => [
                $this->translateExpressionOperand($left),
                $this->translateExpressionOperand($right),
            ],
        ];
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function splitTopLevelArithmeticExpression(string $expr): array
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

    private function trimWrappingParentheses(string $expr): string
    {
        while (
            strlen($expr) > 1
            && $expr[0] === '('
            && $expr[strlen($expr) - 1] === ')'
            && $this->isWrappedBySingleParenthesisPair($expr)
        ) {
            $expr = trim(substr($expr, 1, -1));
        }

        return $expr;
    }

    private function isWrappedBySingleParenthesisPair(string $expr): bool
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
    private function translateExpressionOperand(string $raw)
    {
        $operand = trim($raw);
        if ($operand === '*') {
            return null;
        }

        $operand = $this->trimWrappingParentheses($operand);
        if ($this->containsTopLevelArithmeticOperator($operand)) {
            return ['expression' => $this->translateExpression($operand)];
        }

        if ($this->looksLikeIdentifier($operand)) {
            return IdentifierParser::parse($operand);
        }

        return ValueNormalizer::normalize($operand);
    }

    private function containsTopLevelArithmeticOperator(string $expr): bool
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

    private function looksLikeIdentifier(string $value): bool
    {
        $value = trim($value);
        if ($value === '' || is_numeric($value)) {
            return false;
        }
        if ($value[0] === '\'' || $value[0] === '"') {
            return false;
        }

        return (bool) preg_match('/^`?[A-Za-z_][A-Za-z0-9_]*`?(?:\.`?[A-Za-z_][A-Za-z0-9_]*`?)*$/', $value);
    }
}
