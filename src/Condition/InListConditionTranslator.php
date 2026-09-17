<?php

declare(strict_types=1);

namespace SqlParserTest\Condition;

use PhpMyAdmin\SqlParser\Components\Condition;
use SqlParserTest\IdentifierParser;
use SqlParserTest\ValueNormalizer;

class InListConditionTranslator implements ConditionTranslatorInterface
{
    public function supports(Condition $condition): bool
    {
        $expr = trim($condition->expr);
        return (bool) preg_match('/^(.+?)\s+(NOT\s+IN|IN)\s*\((.*)\)$/i', $expr);
    }

    public function translate(Condition $condition): array
    {
        $expr = trim($condition->expr);

        if (!preg_match('/^(.+?)\s+(NOT\s+IN|IN)\s*\((.*)\)$/i', $expr, $matches)) {
            throw new \InvalidArgumentException('Unsupported WHERE condition.');
        }

        $property = IdentifierParser::parse($matches[1]);
        $operator = strtolower(preg_replace('/\s+/', ' ', $matches[2]));

        $values = [];
        $rawValues = str_getcsv($matches[3], ',');
        foreach ($rawValues as $rawValue) {
            $values[] = ValueNormalizer::normalize($rawValue);
        }

        $translated = [
            'resource' => $property['resource'],
            'property' => $property['property'],
            'operator' => $operator,
            'value' => $values,
        ];

        return array_filter($translated, static fn ($v) => $v !== null && $v !== '');
    }
}
