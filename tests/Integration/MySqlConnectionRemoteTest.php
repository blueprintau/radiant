<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Integration;

use BlueprintAU\Radiant\Database\Connections\MySqlConnection;
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
     * The manager builds the default connection through the MySQL
     * connector — the concrete dialect class comes back.
     */
    public function testConnectorReturnsMySqlConnection(): void
    {
        self::assertInstanceOf(MySqlConnection::class, $this->connection);
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
     * The inspector's content-drift comparison maps MySQL's native type
     * text onto the declared logical types.
     */
    public function testInspectorColumnTypeMatches(): void
    {
        $inspector = $this->connection->schemaInspector;

        self::assertTrue($inspector->columnTypeMatches('int', ColumnType::Int, null));
        self::assertTrue($inspector->columnTypeMatches('varchar(100)', ColumnType::String, 100));
        self::assertFalse($inspector->columnTypeMatches('int', ColumnType::String, 100));
    }

    /**
     * Reading a missing table fails fast.
     */
    public function testInspectorMissingTableThrows(): void
    {
        Expectation::throwsWithMessage(
            fn () => $this->connection->schemaInspector->table('rmt_missing'),
            \RuntimeException::class,
            'does not exist in the MySQL schema',
        );
    }
}
