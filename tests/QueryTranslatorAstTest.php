<?php

declare(strict_types=1);

namespace SqlParserTest\Tests;

use PHPUnit\Framework\TestCase;
use SqlParserTest\DatastoreQuery;
use SqlParserTest\QueryTranslator;
use SqlParserTest\SqlStatementParser;

final class QueryTranslatorAstTest extends TestCase
{
    private const RESOURCE_ID = '909ab5c6-54b6-40ac-96bc-f7198c9c734d';

    public function testTranslatesSelectFromOrderLimitFromStatement(): void
    {
        $sql = sprintf(
            'SELECT record_number AS rn FROM `%s` t ORDER BY record_number DESC LIMIT 5',
            self::RESOURCE_ID
        );

        $payload = $this->translateSqlToPayloadFromStatement($sql);

        self::assertSame(
            [['resource' => 't', 'property' => 'record_number', 'alias' => 'rn']],
            $payload['properties']
        );
        self::assertSame(
            [['id' => self::RESOURCE_ID, 'alias' => 't']],
            $payload['resources']
        );
        self::assertSame(
            [['resource' => 't', 'property' => 'record_number', 'order' => 'desc']],
            $payload['sorts']
        );
        self::assertSame(5, $payload['limit']);
        self::assertSame(0, $payload['offset']);
    }

    public function testResourceOptionCreatesResourceWhenFromMissing(): void
    {
        $sql = 'SELECT record_number';

        $payload = $this->translateSqlToPayloadFromStatement($sql, self::RESOURCE_ID);

        self::assertSame(
            [['id' => self::RESOURCE_ID, 'alias' => 't']],
            $payload['resources']
        );
        self::assertSame('record_number', $payload['properties'][0]['property']);
    }

    public function testTranslatesUnparenthesizedAndWhere(): void
    {
        $sql = sprintf('SELECT record_number FROM `%s` t WHERE record_number = 1', self::RESOURCE_ID);
        $payload = $this->translateSqlToPayloadFromStatement($sql);

        self::assertSame(
            [[
                'resource' => 't',
                'property' => 'record_number',
                'operator' => '=',
                'value' => 1,
            ]],
            $payload['conditions']
        );
    }

    public function testTranslatesUnparenthesizedMixedBooleanWhereWithPrecedence(): void
    {
        $sql = sprintf(
            'SELECT record_number FROM `%s` t WHERE record_number = 1 OR record_number = 2 AND record_number > 0',
            self::RESOURCE_ID
        );
        $payload = $this->translateSqlToPayloadFromStatement($sql);

        self::assertSame('or', $payload['conditions'][0]['groupOperator']);
        self::assertSame('=', $payload['conditions'][0]['conditions'][0]['operator']);
        self::assertSame('and', $payload['conditions'][0]['conditions'][1]['groupOperator']);
    }

    public function testTranslatesInAndNotInWhere(): void
    {
        $sqlIn = sprintf(
            'SELECT record_number FROM `%s` t WHERE record_number IN (1,2,3)',
            self::RESOURCE_ID
        );
        $payloadIn = $this->translateSqlToPayloadFromStatement($sqlIn);
        self::assertSame('in', $payloadIn['conditions'][0]['operator']);
        self::assertSame([1, 2, 3], $payloadIn['conditions'][0]['value']);

        $sqlNotIn = sprintf(
            'SELECT record_number FROM `%s` t WHERE record_number NOT IN (1,2,3)',
            self::RESOURCE_ID
        );
        $payloadNotIn = $this->translateSqlToPayloadFromStatement($sqlNotIn);
        self::assertSame('not in', $payloadNotIn['conditions'][0]['operator']);
        self::assertSame([1, 2, 3], $payloadNotIn['conditions'][0]['value']);
    }

    private function translateSqlToPayloadFromStatement(string $sql, ?string $resource = null): array
    {
        $statement = (new SqlStatementParser())->parseSelect($sql);
        $query = QueryTranslator::translateStatement($statement, $resource);
        $payload = json_decode($query->pretty(), true, 512, JSON_THROW_ON_ERROR);
        $validated = new DatastoreQuery($payload);

        self::assertInstanceOf(DatastoreQuery::class, $validated);

        return $payload;
    }
}
