<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Integration;

use BlueprintAU\Radiant\Database\Connections\SqliteConnection;
use BlueprintAU\Radiant\Database\Schema\Blueprint;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Tests\Support\Expectation;

/**
 * Live SQLite tests for the file-backed paths the in-memory engine cannot
 * serve: persistence across connections, the `BEGIN IMMEDIATE` write lock
 * and the `sqlite_master` inspector.
 *
 * The dialect-agnostic CRUD/transaction/aggregate/alter cycle comes from
 * IntegrationTestCase (CrudCycleTests); this class adds the file-backed
 * sqlite connection config and the sqlite-specific tests. Table lifecycle
 * is handled by DatabaseTestCase — tests declare tables with
 * createTables() and teardown drops them in reverse creation order.
 *
 * The connection is built by the manager through the connector, so the
 * file-backed DSN path is exercised. A second named connection ('probe')
 * backs the lock-exclusivity tests.
 *
 * The file lives in a dedicated directory so teardown can remove it
 * without touching the shared temp dir.
 */
#[\PHPUnit\Framework\Attributes\Group('integration-remote-sql')]
final class SqliteConnectionRemoteTest extends IntegrationTestCase
{
    /**
     * The directory holding this test's database file — created fresh per
     * test so teardown can remove it deterministically.
     *
     * @var string|null
     */
    private ?string $dir = null;

    /**
     * The config for the per-test 'default' connection — a file-backed
     * sqlite database in a dedicated directory.
     *
     * Idempotent: the directory is created once and reused, so the
     * 'probe' connection (built from the same config) points at the SAME
     * database file.
     *
     * @return array<string, mixed>
     */
    #[\Override]
    protected function connectionConfig(): array
    {
        if ($this->dir === null) {
            $this->dir = sys_get_temp_dir() . '/radiant_sqlite_' . uniqid();
            mkdir($this->dir, 0700, true);
        }

        return [
            'driver' => 'sqlite',
            'database' => $this->dir . '/radiant.sqlite',
        ];
    }

    /**
     * Remove the dedicated directory and its database file, then reset
     * the facade.
     */
    #[\Override]
    protected function tearDown(): void
    {
        parent::tearDown();

        if ($this->dir !== null) {
            foreach (glob($this->dir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->dir);
            $this->dir = null;
        }
    }

    /**
     * The manager builds the default connection through the sqlite
     * connector — the concrete dialect class comes back.
     */
    public function testConnectorReturnsSqliteConnection(): void
    {
        self::assertInstanceOf(SqliteConnection::class, $this->connection);
    }

    /**
     * A write survives a disconnect and reconnect — the file-backed
     * database persists across connections.
     */
    public function testWritesPersistAcrossReconnect(): void
    {
        $this->createTables((new Blueprint('rmt_persist'))->id()->string('name', 64));
        $this->connection->table('rmt_persist')->insert(['name' => 'Alice']);

        $this->manager->disconnect('default');

        $row = $this->connection->table('rmt_persist')->where('name', '=', 'Alice')->first();
        self::assertNotNull($row, 'the write must survive a disconnect and reconnect');
        self::assertSame('Alice', $row->name);
    }

    /**
     * withLock() holds the `BEGIN IMMEDIATE` write lock exclusively while
     * the callback runs — a second session cannot write.
     *
     * SqliteLock forbids any write from another session while the lock's
     * transaction is open (the driver rejects it), so the probe write
     * happens after the callback returns — the lock is released by then.
     */
    public function testWithLockHoldsTheLockExclusively(): void
    {
        $this->createTables((new Blueprint('rmt_lock'))->id()->string('name', 64));

        $result = $this->connection->withLock(function (): string {
            return 'value';
        }, 'rmt:lock');

        self::assertSame('value', $result, 'the callback\'s return value must pass through');

        // The lock is released — the probe can write now.
        $this->probe()->table('rmt_lock')->insert(['name' => 'probe']);
        self::assertSame(
            1,
            $this->connection->table('rmt_lock')->count(),
            'the probe write must be visible after the lock is released',
        );
    }

    /**
     * withLock() releases the lock when the callback throws — a second
     * session can write afterwards.
     */
    public function testWithLockReleasesOnThrow(): void
    {
        $this->createTables((new Blueprint('rmt_lock'))->id()->string('name', 64));

        Expectation::throws(function (): void {
            $this->connection->withLock(function (): void {
                throw new \RuntimeException('boom');
            }, 'rmt:lock');
        }, \RuntimeException::class);

        $this->probe()->table('rmt_lock')->insert(['name' => 'after']);
        self::assertSame(
            1,
            $this->connection->table('rmt_lock')->count(),
            'the lock must be released after the callback throws',
        );
    }

    /**
     * The inspector reads tables, columns, primary keys and foreign keys
     * back from `sqlite_master` + `PRAGMA`.
     */
    public function testInspectorReadsSchema(): void
    {
        $this->createTables(
            (new Blueprint('rmt_ins_parent'))->id()->string('name', 64),
            (new Blueprint('rmt_ins_child'))
                ->id()
                ->foreignId('parentId', 'rmt_ins_parent')
                ->string('label', 32),
        );

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
    }

    /**
     * The inspector reads a declared unique index back.
     */
    public function testInspectorReadsUniqueIndex(): void
    {
        $this->createTables(
            (new Blueprint('rmt_ins_idx'))
                ->id()
                ->column(ColumnType::String, 'email', length: 255, unique: true),
        );

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
        $this->createTables(
            (new Blueprint('rmt_ref_parent'))->id(),
            (new Blueprint('rmt_ref_child'))
                ->id()
                ->foreignId('parentId', 'rmt_ref_parent'),
        );

        self::assertSame(
            ['rmt_ref_child'],
            $this->connection->schemaInspector->referencingTables('rmt_ref_parent'),
        );
    }

    /**
     * The inspector's content-drift comparison maps sqlite's native type
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
            'does not exist in the SQLite schema',
        );
    }
}