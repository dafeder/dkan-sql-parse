<?php

declare(strict_types=1);

namespace SqlParserTest\Expression;

use PhpMyAdmin\SqlParser\Components\Expression;

interface ExpressionTranslatorInterface
{
    /**
     * Check if this translator can handle the expression.
     */
    public function supports(Expression $expr): bool;

    /**
     * Translate expression into Datastore property or expression definition.
     *
     * @return array<string, mixed>|null Returns null if expression translates to nothing (e.g. wildcard *)
     */
    public function translate(Expression $expr): ?array;
}
