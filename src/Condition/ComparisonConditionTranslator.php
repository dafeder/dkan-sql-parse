<?php

declare(strict_types=1);

namespace SqlParserTest\Condition;

use PhpMyAdmin\SqlParser\Components\Condition;
use SqlParserTest\IdentifierParser;
use SqlParserTest\ValueNormalizer;

class ComparisonConditionTranslator implements ConditionTranslatorInterface
{
    public function supports(Condition $condition): bool
    {
        return trim($condition->operator) !== '';
    }

    public function translate(Condition $condition): array
    {
        $operator = strtolower(trim($condition->operator));
        $property = IdentifierParser::parse($condition->leftOperand);

        $translated = [
            'resource' => $property['resource'],
            'property' => $property['property'],
            'operator' => $operator,
            'value' => ValueNormalizer::normalize($condition->rightOperand),
        ];

        return array_filter($translated, static fn ($v) => $v !== null && $v !== '');
    }
}
