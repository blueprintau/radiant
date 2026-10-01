<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Integration;

use BlueprintAU\Radiant\Database\Connections\PostgresConnection;
use BlueprintAU\Radiant\Database\Connections\SqlConnection;
use BlueprintAU\Radiant\Database\Schema\Blueprint;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Tests\Support\Expectation;

/**
 * Live Postgres tests for the paths no in-memory engine can serve:
 * transactional DDL, nested transaction savepoints, the session advisory
 * lock and the `pg_catalog`/`information_schema` inspector.
 *
 * The dialect-agnostic CRUD/transaction/aggregate/alter cycle comes from
 * IntegrationTestCase; this class adds the Postgres connection config
 * and the Postgres-specific tests. Table lifecycle is handled by
 * DatabaseTestCase — tests declare tables with createTables() and
 * teardown drops them in reverse creation order.
 *
 * The connection is built by the manager through the connector — with
 * `sslmode`, so the DSN append is exercised — and a second named
 * connection ('probe') backs the lock-exclusivity tests.
 *
 * Env overrides (with local defaults):
 *  - RADIANT_PGSQL_{HOST,PORT,USER,PASSWORD,DATABASE} (127.0.0.1:5432 postgres/postgres radiant)
 */
#[\PHPUnit\Framework\Attributes\Group('integration-remote-sql')]
final class PostgresConnectionRemoteTest extends IntegrationTestCase
{
    /**
     * The concrete connection class the Postgres connector builds.
     *
     * @return class-string<SqlConnection>
     */
    #[\Override]
    protected function connectionClass(): string
    {
        return PostgresConnection::class;
    }

    /**
     * The dialect's name in the inspector's missing-table message.
     *
     * @return string
     */
    #[\Override]
    protected function inspectorSchemaName(): string
    {
        return 'Postgres';
    }

    /**
     * The config for the per-test 'default' connection — the live
     * Postgres server, from env with local defaults. `sslmode` rides
     * along so the connector's DSN append is exercised.
     *
     * @return array<string, mixed>
     */
    #[\Override]
    protected function connectionConfig(): array
    {
        return [
            'driver' => 'pgsql',
            'host' => getenv('RADIANT_PGSQL_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('RADIANT_PGSQL_PORT') ?: '5432'),
            'database' => getenv('RADIANT_PGSQL_DATABASE') ?: 'radiant',
            'username' => getenv('RADIANT_PGSQL_USER') ?: 'postgres',
            'password' => getenv('RADIANT_PGSQL_PASSWORD') ?: 'postgres',
            'sslmode' => 'prefer',
        ];
    }

    /**
     * The (liveType, declaredType, length, expected) tuples for Postgres'
     * udt_name short forms.
     *
     * @return iterable<string, array{string, ColumnType, int|null, bool}>
     */
    #[\Override]
    public static function columnTypeMatchesProvider(): iterable
    {
        yield 'int4 matches Int' => ['int4', ColumnType::Int, null, true];
        yield 'int8 matches BigInt' => ['int8', ColumnType::BigInt, null, true];
        yield 'varchar(100) matches String(100)' => ['varchar(100)', ColumnType::String, 100, true];
        yield 'char(36) matches Char(36)' => ['char(36)', ColumnType::Char, 36, true];
        yield 'text matches Text' => ['text', ColumnType::Text, null, true];
        yield 'jsonb matches Json' => ['jsonb', ColumnType::Json, null, true];
        yield 'json matches Json' => ['json', ColumnType::Json, null, true];
        yield 'date matches Date' => ['date', ColumnType::Date, null, true];
        yield 'timestamptz matches Timestamp' => ['timestamptz', ColumnType::Timestamp, null, true];
        yield 'timestamp matches DateTime' => ['timestamp', ColumnType::DateTime, null, true];
        yield 'bool matches Boolean' => ['bool', ColumnType::Boolean, null, true];
        yield 'numeric matches Decimal' => ['numeric', ColumnType::Decimal, null, true];
        yield 'float8 matches Float' => ['float8', ColumnType::Float, null, true];
        yield 'bytea matches Binary' => ['bytea', ColumnType::Binary, null, true];
        yield 'uuid matches Uuid' => ['uuid', ColumnType::Uuid, null, true];
        yield 'int4 does not match String' => ['int4', ColumnType::String, 100, false];
        yield 'varchar(100) does not match String(50)' => ['varchar(100)', ColumnType::String, 50, false];
        yield 'bare varchar does not match sized String' => ['varchar', ColumnType::String, 100, false];
        yield 'int4 does not match BigInt' => ['int4', ColumnType::BigInt, null, false];
        yield 'text does not match Json' => ['text', ColumnType::Json, null, false];
    }

    /**
     * Postgres DDL is transactional — a rolled-back transaction undoes a
     * CREATE TABLE.
     */
    public function testTransactionalDdlRollsBack(): void
    {
        self::assertTrue($this->connection->supportsTransactionalDdl());

        Expectation::throws(function (): void {
            $this->connection->transaction(function (): void {
                $this->connection->create((new Blueprint('rmt_tx_ddl'))->id());
                throw new \RuntimeException('boom');
            });
        }, \RuntimeException::class);

        self::assertFalse(
            $this->connection->schemaInspector->hasTable('rmt_tx_ddl'),
            'the CREATE TABLE must roll back with the transaction',
        );
    }

    /**
     * withLock() holds the session advisory lock exclusively while the
     * callback runs — a second session cannot acquire it.
     */
    public function testWithLockHoldsTheLockExclusively(): void
    {
        $result = $this->connection->withLock(function (): string {
            $probe = $this->probe();
            $row = $probe->selectSql(
                "SELECT (pg_try_advisory_lock(hashtext('rmt:probe'))) AS held",
            )->first();
            self::assertNotNull($row);
            self::assertFalse(
                (bool) $row->held,
                'a second session must not acquire the lock while the callback runs',
            );
            $probe->statement("SELECT pg_advisory_unlock(hashtext('rmt:probe'))");

            return 'value';
        }, 'rmt:probe');

        self::assertSame('value', $result, 'the callback\'s return value must pass through');
    }

    /**
     * withLock() releases the lock when the callback throws — a second
     * session can acquire it afterwards.
     */
    public function testWithLockReleasesOnThrow(): void
    {
        Expectation::throws(function (): void {
            $this->connection->withLock(function (): void {
                throw new \RuntimeException('boom');
            }, 'rmt:release');
        }, \RuntimeException::class);

        $probe = $this->probe();
        $row = $probe->selectSql(
            "SELECT (pg_try_advisory_lock(hashtext('rmt:release'))) AS acquired",
        )->first();
        self::assertNotNull($row);
        self::assertTrue((bool) $row->acquired, 'the lock must be released after the callback throws');
        $probe->statement("SELECT pg_advisory_unlock(hashtext('rmt:release'))");
    }
}
