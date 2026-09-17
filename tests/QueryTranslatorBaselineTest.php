<?php

declare(strict_types=1);

namespace SqlParserTest\Tests;

use PHPUnit\Framework\TestCase;
use SqlParserTest\DatastoreQuery;
use SqlParserTest\QueryTranslator;
use SqlParserTest\SqlStatementParser;

final class QueryTranslatorBaselineTest extends TestCase
{
    private const RESOURCE_ID = '909ab5c6-54b6-40ac-96bc-f7198c9c734d';

    public function testTranslatesSimpleSelectFromWhereOrderLimit(): void
    {
        $sql = sprintf(
            'SELECT record_number FROM `%s` t WHERE (record_number = 1) ORDER BY record_number DESC LIMIT 5',
            self::RESOURCE_ID
        );

        $payload = $this->translateSqlToPayload($sql);

        self::assertSame(
            [['resource' => 't', 'property' => 'record_number']],
            $payload['properties']
        );
        self::assertSame(
            [['id' => self::RESOURCE_ID, 'alias' => 't']],
            $payload['resources']
        );
        self::assertSame(
            [[
                'resource' => 't',
                'property' => 'record_number',
                'operator' => '=',
                'value' => 1,
            ]],
            $payload['conditions']
        );
        self::assertSame(
            [['resource' => 't', 'property' => 'record_number', 'order' => 'desc']],
            $payload['sorts']
        );
        self::assertSame(5, $payload['limit']);
        self::assertSame(0, $payload['offset']);
    }

    public function testTranslatesAggregateCountWithAlias(): void
    {
        $sql = sprintf(
            'SELECT COUNT(record_number) AS c FROM `%s` t',
            self::RESOURCE_ID
        );

        $payload = $this->translateSqlToPayload($sql);

        self::assertSame('count', $payload['properties'][0]['expression']['operator']);
        self::assertSame('c', $payload['properties'][0]['alias']);
        self::assertSame(
            [['resource' => 't', 'property' => 'record_number']],
            $payload['properties'][0]['expression']['operands']
        );
    }

    public function testTranslatesQualifiedAggregateWithAlias(): void
    {
        $sql = sprintf(
            'SELECT SUM(t.amount) AS total FROM `%s` t',
            self::RESOURCE_ID
        );

        $payload = $this->translateSqlToPayload($sql);

        self::assertSame('sum', $payload['properties'][0]['expression']['operator']);
        self::assertSame('total', $payload['properties'][0]['alias']);
        self::assertSame(
            [['resource' => 't', 'property' => 'amount']],
            $payload['properties'][0]['expression']['operands']
        );
    }

    public function testTranslatesArithmeticExpressionWithAlias(): void
    {
        $sql = sprintf(
            'SELECT (record_number + 4) AS n FROM `%s` t',
            self::RESOURCE_ID
        );

        $payload = $this->translateSqlToPayload($sql);

        self::assertSame('+', $payload['properties'][0]['expression']['operator']);
        self::assertSame('n', $payload['properties'][0]['alias']);
        self::assertSame(
            [
                ['resource' => 't', 'property' => 'record_number'],
                4,
            ],
            $payload['properties'][0]['expression']['operands']
        );
    }

    public function testTranslatesInCondition(): void
    {
        $sql = sprintf(
            'SELECT record_number FROM `%s` t WHERE record_number IN (1,2,3)',
            self::RESOURCE_ID
        );

        $payload = $this->translateSqlToPayload($sql);

        self::assertSame('in', $payload['conditions'][0]['operator']);
        self::assertSame([1, 2, 3], $payload['conditions'][0]['value']);
    }

    public function testResourceOptionCreatesResourceWhenFromMissing(): void
    {
        $sql = 'SELECT record_number WHERE (record_number = 1)';

        $payload = $this->translateSqlToPayload($sql, self::RESOURCE_ID);

        self::assertSame(
            [['id' => self::RESOURCE_ID, 'alias' => 't']],
            $payload['resources']
        );
        self::assertSame('record_number', $payload['properties'][0]['property']);
    }

    public function testTranslatesUnparenthesizedBooleanWhere(): void
    {
        $sql = sprintf(
            'SELECT record_number FROM `%s` t WHERE record_number = 1 AND record_number > 0',
            self::RESOURCE_ID
        );

        $payload = $this->translateSqlToPayload($sql);
        self::assertCount(2, $payload['conditions']);
        self::assertSame('=', $payload['conditions'][0]['operator']);
        self::assertSame('>', $payload['conditions'][1]['operator']);
    }

    public function testTranslatesLikeCondition(): void
    {
        $sql = sprintf(
            'SELECT record_number FROM `%s` t WHERE record_number LIKE "%%value"',
            self::RESOURCE_ID
        );

        $payload = $this->translateSqlToPayload($sql);

        self::assertSame('like', $payload['conditions'][0]['operator']);
        self::assertSame('%value', $payload['conditions'][0]['value']);
    }


    public function testThrowsForProhibitedClause(): void
    {
        $sql = sprintf(
            'SELECT record_number FROM `%s` t GROUP BY record_number',
            self::RESOURCE_ID
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Prohibited SQL clauses detected');

        $this->translateSqlToPayload($sql);
    }

    public function testThrowsForComputedExpressionWithoutAlias(): void
    {
        $sql = sprintf(
            'SELECT (record_number + 4) FROM `%s` t',
            self::RESOURCE_ID
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Mathematical expressions must be aliased.');

        $this->translateSqlToPayload($sql);
    }

    private function translateSqlToPayload(string $sql, ?string $resource = null): array
    {
        $statement = (new SqlStatementParser())->parseSelect($sql);
        $query = QueryTranslator::translateStatement($statement, $resource);
        $payload = json_decode($query->pretty(), true, 512, JSON_THROW_ON_ERROR);
        $validated = new DatastoreQuery($payload);
        self::assertInstanceOf(DatastoreQuery::class, $validated);

        return $payload;
    }
}
