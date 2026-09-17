<?php

declare(strict_types=1);

namespace SqlParserTest\Clause;

use PhpMyAdmin\SqlParser\Components\Expression;
use PhpMyAdmin\SqlParser\Components\OrderKeyword;
use PhpMyAdmin\SqlParser\Statements\SelectStatement;
use SqlParserTest\IdentifierParser;

class OrderClauseTranslator implements ClauseTranslatorInterface
{
    public function canTranslate(SelectStatement $statement): bool
    {
        return !empty($statement->order);
    }

    public function translate(SelectStatement $statement, array &$query, ?string $resource = null): void
    {
        $sorts = [];
        foreach ($statement->order as $part) {
            if (!($part instanceof OrderKeyword) || !($part->expr instanceof Expression)) {
                throw new \InvalidArgumentException('Invalid ORDER clause.');
            }

            $property = $part->expr->column ?: trim((string) $part->expr->expr);
            if (empty($property)) {
                throw new \InvalidArgumentException('Invalid ORDER clause.');
            }

            $sorts[] = [
                'resource' => !empty($part->expr->table) ? $part->expr->table : IdentifierParser::DEFAULT_RESOURCE,
                'property' => $property,
                'order' => strtolower($part->type->value),
            ];
        }

        $query['sorts'] = $sorts;
    }
}
