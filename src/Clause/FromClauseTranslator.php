<?php

declare(strict_types=1);

namespace SqlParserTest\Clause;

use PhpMyAdmin\SqlParser\Components\Expression;
use PhpMyAdmin\SqlParser\Statements\SelectStatement;
use SqlParserTest\IdentifierParser;

class FromClauseTranslator implements ClauseTranslatorInterface
{
    public function canTranslate(SelectStatement $statement): bool
    {
        return true;
    }

    public function translate(SelectStatement $statement, array &$query, ?string $resource = null): void
    {
        if (!empty($statement->from)) {
            if (count($statement->from) > 1) {
                throw new \InvalidArgumentException('Joins are not permitted for this query; you have requested too many resources.');
            }

            $resources = [];
            foreach ($statement->from as $part) {
                if (!($part instanceof Expression) || empty($part->table)) {
                    throw new \InvalidArgumentException('Invalid FROM clause.');
                }
                $resources[] = [
                    'id' => $part->table,
                    'alias' => $part->alias ?: IdentifierParser::DEFAULT_RESOURCE,
                ];
            }
            $query['resources'] = $resources;
        }

        $this->incorporateResource($query, $resource, $statement);
    }

    private function incorporateResource(array &$query, ?string $resource, SelectStatement $statement): void
    {
        if ($resource && !empty($statement->from)) {
            throw new \InvalidArgumentException('You may not pass a FROM clause in a resource query.');
        }

        if ($resource) {
            $query['resources'] = [
                [
                    'id' => $resource,
                    'alias' => IdentifierParser::DEFAULT_RESOURCE,
                ],
            ];
        }
    }
}
