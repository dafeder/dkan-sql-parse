<?php

declare(strict_types=1);

namespace SqlParserTest\Tests;

use PHPUnit\Framework\TestCase;
use SqlParserTest\DatastoreQuery;
use SqlParserTest\QueryTranslator;
use SqlParserTest\SqlStatementParser;

final class QueryTranslatorAstSlice2Test extends TestCase
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

    public function testThrowsForWhereUntilSliceThree(): void
    {
        $sql = sprintf('SELECT record_number FROM `%s` t WHERE record_number = 1', self::RESOURCE_ID);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('WHERE translation for phpmyadmin parser path is not implemented yet.');

        $this->translateSqlToPayloadFromStatement($sql);
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
