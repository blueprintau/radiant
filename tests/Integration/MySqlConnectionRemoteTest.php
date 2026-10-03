<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Integration;

use BlueprintAU\Radiant\Database\Connections\MySqlConnection;
use BlueprintAU\Radiant\Database\Connections\SqlConnection;
use BlueprintAU\Radiant\Database\Schema\Blueprint;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Tests\Support\Expectation;

/**
 * Live MySQL tests for the paths no in-memory engine can serve: nested
 * transaction savepoints, the `GET_LOCK` advisory lock and the
 * `information_schema` inspector.
 *
 * The dialect-agnostic CRUD/transaction/aggregate/alter cycle comes from
 * IntegrationTestCase; this class adds the MySQL connection config and
 * the MySQL-specific tests. Table lifecycle is handled by
 * DatabaseTestCase — tests declare tables with createTables() and
 * teardown drops them in reverse creation order.
 *
 * Each test connects *through the connector*, so the DSN construction,
 * option merging and the `SET NAMES` post-connect SQL are all exercised —
 * not just the PDO handshake. A second named connection ('probe') backs
 * the lock-exclusivity tests.
 *
 * Env overrides (with local defaults):
 *  - RADIANT_MYSQL_{HOST,PORT,USER,PASSWORD,DATABASE} (127.0.0.1:3306 root/"" radiant)
 */
#[\PHPUnit\Framework\Attributes\Group('integration-remote-sql')]
final class MySqlConnectionRemoteTest extends IntegrationTestCase
{
    /**
     * The concrete connection class the MySQL connector builds.
     *
     * @return class-string<SqlConnection>
     */
    #[\Override]
    protected function connectionClass(): string
    {
        return MySqlConnection::class;
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
     * The config for the per-test 'default' connection — the live MySQL
     * server, from env with local defaults.
     *
     * @return array<string, mixed>
     */
    #[\Override]
    protected function connectionConfig(): array
    {
        return [
            'driver' => 'mysql',
            'host' => getenv('RADIANT_MYSQL_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('RADIANT_MYSQL_PORT') ?: '3306'),
            'database' => getenv('RADIANT_MYSQL_DATABASE') ?: 'radiant',
            'username' => getenv('RADIANT_MYSQL_USER') ?: 'root',
            'password' => getenv('RADIANT_MYSQL_PASSWORD') ?: '',
        ];
    }

    /**
     * The (liveType, declaredType, length, expected) tuples for MySQL's
     * native type text.
     *
     * @return iterable<string, array{string, ColumnType, int|null, bool}>
     */
    #[\Override]
    public static function columnTypeMatchesProvider(): iterable
    {
        yield 'int matches Int' => ['int', ColumnType::Int, null, true];
        yield 'bigint matches BigInt' => ['bigint', ColumnType::BigInt, null, true];
        yield 'varchar(100) matches String(100)' => ['varchar(100)', ColumnType::String, 100, true];
        yield 'char(36) matches Uuid' => ['char(36)', ColumnType::Uuid, null, true];
        yield 'text matches Text' => ['text', ColumnType::Text, null, true];
        yield 'json matches Json' => ['json', ColumnType::Json, null, true];
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
     * withLock() holds the GET_LOCK exclusively while the callback runs —
     * a second session cannot acquire it.
     */
    public function testWithLockHoldsTheLockExclusively(): void
    {
        $result = $this->connection->withLock(function (): string {
            $probe = $this->probe();
            $row = $probe->selectSql("SELECT GET_LOCK('rmt:probe', 0) AS held")->first();
            self::assertNotNull($row);
            self::assertSame(
                0,
                (int) $row->held,
                'a second session must not acquire the lock while the callback runs',
            );

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
        $row = $probe->selectSql("SELECT GET_LOCK('rmt:release', 0) AS acquired")->first();
        self::assertNotNull($row);
        self::assertSame(1, (int) $row->acquired, 'the lock must be released after the callback throws');
        $probe->statement("DO RELEASE_LOCK('rmt:release')");
    }

    /**
     * MySQL has no DEFERRABLE — the inspector reports deferrable: false.
     */
    public function testInspectorForeignKeyNotDeferrable(): void
    {
        $this->createTables(
            (new Blueprint('rmt_fk_parent'))->id(),
            (new Blueprint('rmt_fk_child'))
                ->id()
                ->foreignId('parentId', 'rmt_fk_parent'),
        );

        $child = $this->connection->schemaInspector->table('rmt_fk_child');
        self::assertFalse($child->foreignKeys[0]['deferrable'], 'MySQL has no DEFERRABLE');
    }

    /**
     * A ModifyColumn change compiles ONLY the drifted subset — the
     * auto-increment primary key is NOT restated (a full-shape compile
     * would fail with "Multiple primary key defined"), and unchanged
     * columns are not needlessly re-ALTERed.
     */
    public function testModifyColumnCompilesOnlyTheDriftedSubset(): void
    {
        $this->createTables((new Blueprint('rmt_modify'))
            ->id()
            ->string('name', 50)
            ->string('email', 100));

        $differ = new \BlueprintAU\Radiant\Database\Schema\SchemaDiffer($this->connection->schemaInspector);

        // Widen ONLY the name column — id and email are unchanged.
        $desired = (new Blueprint('rmt_modify'))
            ->id()
            ->string('name', 120)
            ->string('email', 100);

        // Diff ONLY the table under test (dropTables off) so a leftover
        // table in the shared schema cannot add a drop_table change.
        $changes = $differ->diff([$desired], dropTables: false);

        self::assertCount(1, $changes);
        self::assertSame(\BlueprintAU\Radiant\Database\Schema\Enums\SchemaOperation::ModifyColumn, $changes[0]->operation);

        // The change carries the drifted column NAMES as its subject.
        self::assertSame(['name'], $changes[0]->subject);

        // The compiled SQL (the full blueprint filtered to the subject)
        // touches ONLY the name column — no PRIMARY KEY restatement, no
        // re-ALTER of the unchanged email column.
        $sql = $this->connection->schemaGrammar->compileModifyColumn(
            $changes[0]->blueprint->onlyColumns($changes[0]->subject ?? []),
        );
        self::assertSame(
            ['ALTER TABLE `rmt_modify` MODIFY `name` varchar(120) NOT NULL'],
            $sql,
        );

        // Applying the change must not trip "Multiple primary key defined".
        $this->connection->apply($changes[0]);

        $live = $this->connection->schemaInspector->table('rmt_modify');
        self::assertStringContainsString('120', $live->columns[1]['type']);
    }
}
