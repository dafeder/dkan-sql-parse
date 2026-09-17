<?php

declare(strict_types=1);

namespace SqlParserTest\Condition;

use PhpMyAdmin\SqlParser\Components\Condition;

interface ConditionTranslatorInterface
{
    /**
     * Check if this translator can handle the condition.
     */
    public function supports(Condition $condition): bool;

    /**
     * Translate a leaf condition into Datastore condition array.
     *
     * @return array<string, mixed>
     */
    public function translate(Condition $condition): array;
}
