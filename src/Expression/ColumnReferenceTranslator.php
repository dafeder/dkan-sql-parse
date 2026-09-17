<?php

declare(strict_types=1);

namespace SqlParserTest\Expression;

use PhpMyAdmin\SqlParser\Components\Expression;
use SqlParserTest\IdentifierParser;

class ColumnReferenceTranslator implements ExpressionTranslatorInterface
{
    public function supports(Expression $expr): bool
    {
        if (($expr->column === '*') || (trim((string) $expr->expr) === '*')) {
            return true;
        }

        return !empty($expr->column) && empty($expr->function);
    }

    public function translate(Expression $expr): ?array
    {
        if (($expr->column === '*') || (trim((string) $expr->expr) === '*')) {
            return null;
        }

        $property = [
            'resource' => !empty($expr->table) ? $expr->table : IdentifierParser::DEFAULT_RESOURCE,
            'property' => $expr->column,
            'alias' => $expr->alias ?: null,
        ];

        return array_filter($property);
    }
}
