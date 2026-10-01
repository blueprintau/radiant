<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Integration;

use BlueprintAU\Radiant\Database\Connections\MySqlConnection;
use BlueprintAU\Radiant\Database\Schema\Blueprint;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Tests\Support\CrudCycleTests;
use BlueprintAU\Radiant\Tests\Support\DatabaseTestCase;
use BlueprintAU\Radiant\Tests\Support\Expectation;

/**
 * Live MySQL tests for the paths no in-memory engine can serve: nested
 * transaction savepoints, the `GET_LOCK` advisory lock and the
 * `information_schema` inspector.
 *
 * **Policy: fail, never skip.** These tests require a reachable MySQL
 * server. When no server is up, they FAIL — the caller must exclude the
 * `integration-remote-sql` group explicitly (phpunit.xml excludes it for
 * the default local run; CI runs it where a failure is a real signal).
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
final class MySqlConnectionRemoteTest extends DatabaseTestCase
{
    use CrudCycleTests;

    /**
     * Tables created by the running test — dropped in reverse order on
     * teardown (children before parents, so FKs never block the drop).
     *
     * @var list<string>
     */
    private array $tables = [];

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
     * A second session on the same server — the probe that proves the
     * advisory lock is held exclusively.
     *
     * @return array<string, array<string, mixed>>
     */
    #[\Override]
    protected function additionalConnections(): array
    {
        return ['probe' => $this->connectionConfig()];
    }

    /**
     * Drop every table the test created, then reset the facade.
     */
    #[\Override]
    protected function tearDown(): void
    {
        foreach (array_reverse($this->tables) as $table) {
            $this->connection->statement("DROP TABLE IF EXISTS {$table}");
        }
        parent::tearDown();
    }

    /**
     * Track a table for teardown cleanup.
     *
     * @param  string  $table
     */
    private function track(string $table): void
    {
        $this->tables[] = $table;
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
     * A nested transaction that throws rolls back to its savepoint — the
     * outer transaction's writes survive and the inner ones do not.
     */
    public function testNestedTransactionRollsBackToSavepoint(): void
    {
        $this->connection->create((new Blueprint('rmt_savepoint'))->id()->string('name', 64));
        $this->track('rmt_savepoint');

        $this->connection->transaction(function (): void {
            $this->connection->table('rmt_savepoint')->insert(['name' => 'outer']);

            try {
                $this->connection->transaction(function (): void {
                    $this->connection->table('rmt_savepoint')->insert(['name' => 'inner']);
                    throw new \RuntimeException('boom');
                });
            } catch (\RuntimeException) {
                // The inner rollback is the subject; the outer continues.
            }
        });

        $names = $this->connection->table('rmt_savepoint')->pluck('name')->all();
        self::assertSame(['outer'], $names, 'the inner savepoint must roll back, the outer commit must hold');
    }

    /**
     * A nested transaction that succeeds releases its savepoint and the
     * outer commit persists both frames' writes.
     */
    public function testNestedTransactionCommitPersistsBothFrames(): void
    {
        $this->connection->create((new Blueprint('rmt_savepoint'))->id()->string('name', 64));
        $this->track('rmt_savepoint');

        $this->connection->transaction(function (): void {
            $this->connection->table('rmt_savepoint')->insert(['name' => 'outer']);
            $this->connection->transaction(function (): void {
                $this->connection->table('rmt_savepoint')->insert(['name' => 'inner']);
            });
        });

        self::assertSame(2, $this->connection->table('rmt_savepoint')->count());
    }

    /**
     * withLock() holds the GET_LOCK exclusively while the callback runs —
     * a second session cannot acquire it.
     */
    public function testWithLockHoldsTheLockExclusively(): void
    {
        $result = $this->connection->withLock(function (): string {
            $probe = $this->manager->sqlConnection('probe');
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

        $probe = $this->manager->sqlConnection('probe');
        $row = $probe->selectSql("SELECT GET_LOCK('rmt:release', 0) AS acquired")->first();
        self::assertNotNull($row);
        self::assertSame(1, (int) $row->acquired, 'the lock must be released after the callback throws');
        $probe->statement("DO RELEASE_LOCK('rmt:release')");
    }

    /**
     * The inspector reads tables, columns, primary keys and foreign keys
     * back from `information_schema`.
     */
    public function testInspectorReadsSchema(): void
    {
        $this->connection->create((new Blueprint('rmt_ins_parent'))->id()->string('name', 64));
        $this->connection->create(
            (new Blueprint('rmt_ins_child'))
                ->id()
                ->foreignId('parentId', 'rmt_ins_parent')
                ->string('label', 32),
        );
        $this->track('rmt_ins_child');
        $this->track('rmt_ins_parent');

        $inspector = $this->connection->schemaInspector;

        self::assertContains('rmt_ins_parent', $inspector->tables());
        self::assertTrue($inspector->hasTable('rmt_ins_parent'));

        $parent = $inspector->table('rmt_ins_parent');
        self::assertSame(['id', 'name'], array_column($parent->columns, 'name'));
        self::assertTrue($parent->columns[0]['primaryKey']);
        self::assertFalse($parent->columns[1]['nullable']);

        $child = $inspector->table('rmt_ins_child');
        self::assertCount(1, $child->foreignKeys);
        self::assertSame(['parentId'], $child->foreignKeys[0]['columns']);
        self::assertSame('rmt_ins_parent', $child->foreignKeys[0]['referencesTable']);
        self::assertSame(['id'], $child->foreignKeys[0]['referencesColumns']);
        self::assertFalse($child->foreignKeys[0]['deferrable'], 'MySQL has no DEFERRABLE');
    }

    /**
     * The inspector reads a declared unique index back.
     */
    public function testInspectorReadsUniqueIndex(): void
    {
        $this->connection->create(
            (new Blueprint('rmt_ins_idx'))
                ->id()
                ->column(ColumnType::String, 'email', length: 255, unique: true),
        );
        $this->track('rmt_ins_idx');

        $live = $this->connection->schemaInspector->table('rmt_ins_idx');
        $columns = array_map(
            fn (array $index) => $index['columns'],
            array_values(array_filter($live->indexes, fn (array $index) => $index['unique'])),
        );

        self::assertContains(['email'], $columns);
    }

    /**
     * The inspector lists the tables that declare a foreign key into a
     * given table.
     */
    public function testInspectorReferencingTables(): void
    {
        $this->connection->create((new Blueprint('rmt_ref_parent'))->id());
        $this->connection->create(
            (new Blueprint('rmt_ref_child'))
                ->id()
                ->foreignId('parentId', 'rmt_ref_parent'),
        );
        $this->track('rmt_ref_child');
        $this->track('rmt_ref_parent');

        self::assertSame(
            ['rmt_ref_child'],
            $this->connection->schemaInspector->referencingTables('rmt_ref_parent'),
        );
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
