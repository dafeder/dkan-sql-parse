<?php

declare(strict_types=1);

namespace SqlParserTest\Condition;

use PhpMyAdmin\SqlParser\Components\Condition;
use SqlParserTest\IdentifierParser;
use SqlParserTest\ValueNormalizer;

class LikeConditionTranslator implements ConditionTranslatorInterface
{
    public function supports(Condition $condition): bool
    {
        $expr = trim($condition->expr);
        return (bool) preg_match('/^(\(*)\s*(.+?)\s+(NOT\s+LIKE|LIKE)\s+(.+?)(\)*)$/is', $expr);
    }

    public function translate(Condition $condition): array
    {
        $expr = trim($condition->expr);

        if (!preg_match('/^(\(*)\s*(.+?)\s+(NOT\s+LIKE|LIKE)\s+(.+?)(\)*)$/is', $expr, $matches)) {
            throw new \InvalidArgumentException('Unsupported WHERE condition.');
        }

        $property = IdentifierParser::parse($matches[2]);
        $operator = strtolower(preg_replace('/\s+/', ' ', $matches[3]));
        $value = ValueNormalizer::normalize($matches[4]);

        $translated = [
            'resource' => $property['resource'],
            'property' => $property['property'],
            'operator' => $operator,
            'value' => $value,
        ];

        return array_filter($translated, static fn ($v) => $v !== null && $v !== '');
    }
}
