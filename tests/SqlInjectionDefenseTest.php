<?php

declare(strict_types=1);

namespace SqlParserTest\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SqlParserTest\DatastoreQuery;
use SqlParserTest\QueryTranslator;
use SqlParserTest\SqlStatementParser;

/**
 * Demonstrates expected behavior when faced with standard SQL injection techniques.
 *
 * This parsing and translation layer ensures that:
 * 1. Only SELECT statements are accepted (DDL/DML rejected).
 * 2. Multi-statement / stacked query injection is neutralized.
 * 3. Prohibited clauses (UNION, JOIN, subqueries) are blocked.
 * 4. Dangerous system functions (SLEEP, BENCHMARK, LOAD_FILE) are rejected.
 * 5. Input is never executed directly as SQL string; it is parsed into structured AST
 *    objects and DatastoreQuery JSON payloads, bound safely downstream.
 */
final class SqlInjectionDefenseTest extends TestCase
{
    private const RESOURCE_ID = '909ab5c6-54b6-40ac-96bc-f7198c9c734d';

    /**
     * DML / DDL statement injection (DROP, UPDATE, INSERT, DELETE) is rejected.
     */
    #[DataProvider('nonSelectStatementsProvider')]
    public function testRejectsNonSelectStatements(string $sql): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Only SELECT statements are supported.');

        $parser = new SqlStatementParser();
        $parser->parseSelect($sql);
    }

    public static function nonSelectStatementsProvider(): array
    {
        return [
            'DROP TABLE' => ['DROP TABLE users'],
            'DELETE' => ['DELETE FROM users WHERE 1=1'],
            'UPDATE' => ['UPDATE users SET is_admin = 1 WHERE id = 1'],
            'INSERT' => ['INSERT INTO users (name) VALUES ("admin")'],
            'ALTER TABLE' => ['ALTER TABLE users ADD COLUMN backdoor VARCHAR(255)'],
            'TRUNCATE' => ['TRUNCATE TABLE users'],
        ];
    }

    /**
     * Stacked queries (e.g. `SELECT ...; DROP TABLE ...;`) are neutralized:
     * only the first SELECT statement is parsed; subsequent statements are discarded.
     */
    public function testStackedStatementsAreNeutralized(): void
    {
        $sql = sprintf(
            'SELECT record_number FROM `%s` t WHERE record_number = 1; DROP TABLE users; --',
            self::RESOURCE_ID
        );

        $payload = $this->translateSqlToPayload($sql);

        // The query translates the SELECT only; DROP TABLE was discarded.
        self::assertSame(
            [['resource' => 't', 'property' => 'record_number']],
            $payload['properties']
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
    }

    /**
     * UNION and UNION ALL injections are rejected by StatementGuard.
     */
    #[DataProvider('unionQueriesProvider')]
    public function testRejectsUnionBasedInjection(string $sql): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Prohibited SQL clauses detected for current parser path.');

        $this->translateSqlToPayload($sql);
    }

    public static function unionQueriesProvider(): array
    {
        return [
            'UNION' => [
                sprintf('SELECT record_number FROM `%s` t UNION SELECT password FROM users', self::RESOURCE_ID),
            ],
            'UNION ALL' => [
                sprintf('SELECT record_number FROM `%s` t UNION ALL SELECT password FROM users', self::RESOURCE_ID),
            ],
            'UNION with NULLs' => [
                sprintf('SELECT record_number FROM `%s` t UNION SELECT 1, 2, 3', self::RESOURCE_ID),
            ],
        ];
    }

    /**
     * JOIN-based unauthorized data access is rejected by StatementGuard.
     */
    #[DataProvider('joinQueriesProvider')]
    public function testRejectsJoinBasedInjection(string $sql): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Joins are not permitted for this query');

        $this->translateSqlToPayload($sql);
    }

    public static function joinQueriesProvider(): array
    {
        return [
            'INNER JOIN' => [
                sprintf('SELECT record_number FROM `%s` t JOIN users u ON 1=1', self::RESOURCE_ID),
            ],
            'LEFT JOIN' => [
                sprintf('SELECT record_number FROM `%s` t LEFT JOIN secret_data s ON 1=1', self::RESOURCE_ID),
            ],
            'CROSS JOIN' => [
                sprintf('SELECT record_number FROM `%s` t, users u', self::RESOURCE_ID),
            ],
        ];
    }

    /**
     * Dangerous system functions (time-based sleep, benchmarking, filesystem read)
     * are rejected because only whitelisted aggregate functions (sum, count, avg, max, min) are allowed.
     */
    #[DataProvider('dangerousFunctionsProvider')]
    public function testRejectsDangerousSystemFunctions(string $sql): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported aggregate function.');

        $this->translateSqlToPayload($sql);
    }

    public static function dangerousFunctionsProvider(): array
    {
        return [
            'SLEEP' => [
                sprintf('SELECT SLEEP(5) AS s FROM `%s` t', self::RESOURCE_ID),
            ],
            'BENCHMARK' => [
                sprintf('SELECT BENCHMARK(10000000, MD5(1)) AS b FROM `%s` t', self::RESOURCE_ID),
            ],
            'LOAD_FILE' => [
                sprintf('SELECT LOAD_FILE("/etc/passwd") AS f FROM `%s` t', self::RESOURCE_ID),
            ],
            'VERSION' => [
                sprintf('SELECT VERSION() AS v FROM `%s` t', self::RESOURCE_ID),
            ],
            'USER' => [
                sprintf('SELECT USER() AS u FROM `%s` t', self::RESOURCE_ID),
            ],
        ];
    }

    /**
     * Subqueries in the SELECT projection list are rejected.
     */
    public function testRejectsSubqueryInSelect(): void
    {
        $sql = sprintf(
            'SELECT (SELECT password FROM users) AS p FROM `%s` t',
            self::RESOURCE_ID
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->translateSqlToPayload($sql);
    }

    /**
     * SQL comment injection (line `--` or block `/* *\/`) is safely parsed by the lexer
     * without bypassing the structured translation layer.
     */
    public function testCommentInjectionDoesNotBypassParser(): void
    {
        $sql = sprintf(
            'SELECT record_number FROM `%s` t WHERE record_number = 1 -- injected comment AND is_admin = 0',
            self::RESOURCE_ID
        );

        $payload = $this->translateSqlToPayload($sql);

        // Lexer strips the line comment; only the valid condition before the comment is retained.
        self::assertCount(1, $payload['conditions']);
        self::assertSame(1, $payload['conditions'][0]['value']);
    }

    /**
     * Tautology injection (e.g. `WHERE id = 1 OR '1'='1'`) does not break query structure.
     * It is decomposed into structured DatastoreQuery AST condition objects rather than
     * executing raw unescaped SQL against the storage backend.
     */
    public function testTautologyConditionDecomposedIntoStructuredAst(): void
    {
        $sql = sprintf(
            'SELECT record_number FROM `%s` t WHERE record_number = 1 OR 1=1',
            self::RESOURCE_ID
        );

        $payload = $this->translateSqlToPayload($sql);

        self::assertSame('or', $payload['conditions'][0]['groupOperator']);
        self::assertCount(2, $payload['conditions'][0]['conditions']);
        self::assertSame('record_number', $payload['conditions'][0]['conditions'][0]['property']);
        self::assertSame('1', $payload['conditions'][0]['conditions'][1]['property']);
        self::assertSame(1, $payload['conditions'][0]['conditions'][1]['value']);
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
