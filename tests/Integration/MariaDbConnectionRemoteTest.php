<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Integration;

use BlueprintAU\Radiant\Database\Connections\MariaDbConnection;
use BlueprintAU\Radiant\Database\Connections\SqlConnection;
use BlueprintAU\Radiant\Database\Query\Expression;
use BlueprintAU\Radiant\Database\Schema\Blueprint;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;

/**
 * Live MariaDB tests for the paths no in-memory engine can serve: nested
 * transaction savepoints, the `GET_LOCK` advisory lock and the
 * `information_schema` inspector with MariaDB's JSON-as-LONGTEXT and
 * current-timestamp spellings.
 *
 * The dialect-agnostic CRUD/transaction/aggregate/alter cycle comes from
 * IntegrationTestCase; this class adds the MariaDB connection config and
 * the MariaDB-specific tests. Table lifecycle is handled by
 * DatabaseTestCase — tests declare tables with createTables() and
 * teardown drops them in reverse creation order.
 *
 * Each test connects *through the connector*, so the DSN construction,
 * option merging and the `SET NAMES` post-connect SQL are all exercised —
 * not just the PDO handshake. A second named connection ('probe') backs
 * the lock-exclusivity tests.
 *
 * Env overrides (with local defaults):
 *  - RADIANT_MARIADB_{HOST,PORT,USER,PASSWORD,DATABASE} (127.0.0.1:3306 root/"" radiant)
 */
#[\PHPUnit\Framework\Attributes\Group('integration-remote-sql')]
final class MariaDbConnectionRemoteTest extends IntegrationTestCase
{
    /**
     * The concrete connection class the MariaDB connector builds.
     *
     * @return class-string<SqlConnection>
     */
    #[\Override]
    protected function connectionClass(): string
    {
        return MariaDbConnection::class;
    }

    /**
     * The dialect's name in the inspector's missing-table message.
     *
     * @return string
     */
    #[\Override]
    protected function inspectorSchemaName(): string
    {
        return 'MySQL';
    }

    /**
     * The config for the per-test 'default' connection — the live MariaDB
     * server, from env with local defaults.
     *
     * @return array<string, mixed>
     */
    #[\Override]
    protected function connectionConfig(): array
    {
        return [
            'driver' => 'mariadb',
            'host' => getenv('RADIANT_MARIADB_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('RADIANT_MARIADB_PORT') ?: '3306'),
            'database' => getenv('RADIANT_MARIADB_DATABASE') ?: 'radiant',
            'username' => getenv('RADIANT_MARIADB_USER') ?: 'root',
            'password' => getenv('RADIANT_MARIADB_PASSWORD') ?: '',
        ];
    }

    /**
     * The (liveType, declaredType, length, expected) tuples for MariaDB's
     * native type text.
     *
     * @return iterable<string, array{string, ColumnType, int|null, bool}>
     */
    #[\Override]
    public static function columnTypeMatchesProvider(): iterable
    {
        yield 'longtext matches Json (the MariaDB alias)' => ['longtext', ColumnType::Json, null, true];
        yield 'json matches Json' => ['json', ColumnType::Json, null, true];
        yield 'int matches Int' => ['int', ColumnType::Int, null, true];
        yield 'bigint matches BigInt' => ['bigint', ColumnType::BigInt, null, true];
        yield 'varchar(100) matches String(100)' => ['varchar(100)', ColumnType::String, 100, true];
        yield 'char(36) matches Uuid' => ['char(36)', ColumnType::Uuid, null, true];
        yield 'text matches Text' => ['text', ColumnType::Text, null, true];
        yield 'date matches Date' => ['date', ColumnType::Date, null, true];
        yield 'datetime matches DateTime' => ['datetime', ColumnType::DateTime, null, true];
        yield 'timestamp matches Timestamp' => ['timestamp', ColumnType::Timestamp, null, true];
        yield 'tinyint(1) matches Boolean' => ['tinyint(1)', ColumnType::Boolean, null, true];
        yield 'decimal matches Decimal' => ['decimal', ColumnType::Decimal, null, true];
        yield 'double matches Float' => ['double', ColumnType::Float, null, true];
        yield 'blob matches Binary' => ['blob', ColumnType::Binary, null, true];
        yield 'varbinary(255) matches Binary(255)' => ['varbinary(255)', ColumnType::Binary, 255, true];
        yield 'int does not match String' => ['int', ColumnType::String, 100, false];
        yield 'varchar(100) does not match String(50)' => ['varchar(100)', ColumnType::String, 50, false];
        yield 'int does not match BigInt' => ['int', ColumnType::BigInt, null, false];
        yield 'text does not match Json' => ['text', ColumnType::Json, null, false];
    }

    /**
     * A Json-column table syncs and converges — the live longtext never
     * re-plans as a ModifyColumn.
     */
    public function testJsonColumnConvergesWithoutDriftLoop(): void
    {
        $desired = (new Blueprint('rmt_mdb_json'))
            ->id()
            ->column(ColumnType::Json, 'meta');

        $this->createTables($desired);

        $synchronizer = new \BlueprintAU\Radiant\Database\Schema\SchemaSynchronizer($this->connection);
        $live = $this->connection->schemaInspector->table('rmt_mdb_json');

        // MariaDB stores the column as longtext — the diverged spelling
        // the MariaDB inspector must map back onto Json.
        $liveTypes = array_column($live->columns, 'type');
        self::assertContains('longtext', $liveTypes, 'MariaDB stores JSON as LONGTEXT');

        self::assertSame([], $synchronizer->plan([$desired]), 'a Json column must converge, never re-plan as a modify');
    }

    /**
     * A current-timestamp default converges — MariaDB's
     * `current_timestamp()` spelling normalizes to CURRENT_TIMESTAMP.
     */
    public function testCurrentTimestampDefaultConverges(): void
    {
        $desired = (new Blueprint('rmt_mdb_ts'))
            ->id()
            ->column(ColumnType::DateTime, 'created_at', default: new Expression('CURRENT_TIMESTAMP'));

        $this->createTables($desired);

        $synchronizer = new \BlueprintAU\Radiant\Database\Schema\SchemaSynchronizer($this->connection);

        self::assertSame([], $synchronizer->plan([$desired]), 'a current-timestamp default must converge, never re-plan');
    }
}
