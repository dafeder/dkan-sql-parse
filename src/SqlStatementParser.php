<?php

namespace SqlParserTest;

use PhpMyAdmin\SqlParser\Parser;
use PhpMyAdmin\SqlParser\Statements\SelectStatement;

class SqlStatementParser
{
    public function parseSelect(string $sql): SelectStatement
    {
        $parser = new Parser($sql);
        if (empty($parser->statements)) {
            throw new \InvalidArgumentException('No SQL statement detected.');
        }

        $statement = $parser->statements[0];
        if (!($statement instanceof SelectStatement)) {
            throw new \InvalidArgumentException('Only SELECT statements are supported.');
        }

        return $statement;
    }
}
