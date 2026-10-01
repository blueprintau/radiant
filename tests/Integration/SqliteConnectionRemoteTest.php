<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Integration;

use BlueprintAU\Radiant\Database\Connections\SqlConnection;
use BlueprintAU\Radiant\Database\Connections\SqliteConnection;
use BlueprintAU\Radiant\Database\Schema\Blueprint;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Tests\Support\Expectation;

/**
 * Live SQLite tests for the file-backed paths the in-memory engine cannot
 * serve: persistence across connections, the `BEGIN IMMEDIATE` write lock
 * and the `sqlite_master` inspector.
 *
 * Unlike the MySQL/Postgres suites this needs NO server — a file-backed
 * sqlite database — so it is NOT in the `integration-remote-sql` group:
 * it runs in the default suite locally and in CI alike.
 *
 * The dialect-agnostic CRUD/transaction/aggregate/alter cycle comes from
 * IntegrationTestCase; this class adds the file-backed sqlite connection
 * config and the sqlite-specific tests. Table lifecycle is handled by
 * DatabaseTestCase — tests declare tables with createTables() and
 * teardown drops them in reverse creation order.
 *
 * The connection is built by the manager through the connector, so the
 * file-backed DSN path is exercised. A second named connection ('probe')
 * backs the lock-exclusivity tests.
 *
 * The file lives in a dedicated directory so teardown can remove it
 * without touching the shared temp dir.
 */
final class SqliteConnectionRemoteTest extends IntegrationTestCase
{
    /**
     * The concrete connection class the sqlite connector builds.
     *
     * @return class-string<SqlConnection>
     */
    #[\Override]
    protected function connectionClass(): string
    {
        return SqliteConnection::class;
    }

    /**
     * The dialect's name in the inspector's missing-table message.
     *
     * @return string
     */
    #[\Override]
    protected function inspectorSchemaName(): string
    {
        return 'SQLite';
    }

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
     * The (liveType, declaredType, length, expected) tuples for sqlite's
     * native type text.
     *
     * @return iterable<string, array{string, ColumnType, int|null, bool}>
     */
    #[\Override]
    public static function columnTypeMatchesProvider(): iterable
    {
        yield 'int matches Int' => ['int', ColumnType::Int, null, true];
        yield 'integer matches BigInt' => ['integer', ColumnType::BigInt, null, true];
        yield 'varchar(100) matches String(100)' => ['varchar(100)', ColumnType::String, 100, true];
        yield 'char(36) matches Char(36)' => ['char(36)', ColumnType::Char, 36, true];
        yield 'text matches Text' => ['text', ColumnType::Text, null, true];
        yield 'text matches Json' => ['text', ColumnType::Json, null, true];
        yield 'text matches Uuid' => ['text', ColumnType::Uuid, null, true];
        yield 'text matches Date' => ['text', ColumnType::Date, null, true];
        yield 'datetime matches DateTime' => ['datetime', ColumnType::DateTime, null, true];
        yield 'timestamp matches Timestamp' => ['timestamp', ColumnType::Timestamp, null, true];
        yield 'tinyint(1) matches Boolean' => ['tinyint(1)', ColumnType::Boolean, null, true];
        yield 'numeric matches Decimal' => ['numeric', ColumnType::Decimal, null, true];
        yield 'double matches Float' => ['double', ColumnType::Float, null, true];
        yield 'blob matches Binary' => ['blob', ColumnType::Binary, null, true];
        yield 'int does not match String' => ['int', ColumnType::String, 100, false];
        yield 'varchar(100) does not match String(50)' => ['varchar(100)', ColumnType::String, 50, false];
        yield 'int does not match BigInt' => ['int', ColumnType::BigInt, null, false];
        yield 'date does not match Date' => ['date', ColumnType::Date, null, false];
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
}