<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Locks;

use BlueprintAU\Radiant\Database\Connections\SqliteConnection;
use BlueprintAU\Radiant\Database\Exceptions\ConnectionException;
use BlueprintAU\Radiant\Database\Locks\NoopLock;
use BlueprintAU\Radiant\Database\Locks\SqliteLock;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the locking layer's observable contracts.
 *
 * The SQL-statement adapters (MySqlLock, PostgresLock) talk dialect SQL
 * that in-memory engines cannot serve, so they are covered only where the
 * contract is engine-independent; the SQLite adapter and the noop run for
 * real against in-memory SQLite.
 */
final class LocksTest extends TestCase
{
    /**
     * The in-memory SQLite connection under test.
     *
     * @var SqliteConnection
     */
    private SqliteConnection $connection;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->connection = new SqliteConnection(new \PDO('sqlite::memory:'));
    }

    /**
     * SqliteLock opens a write transaction around the callback.
     */
    public function testSqliteLockWrapsCallbackInTransaction(): void
    {
        $lock = new SqliteLock($this->connection);

        $depthInside = null;
        $result = $lock->withLock(function () use (&$depthInside): string {
            $depthInside = $this->connection->transactionLevel();
            $this->connection->statement('CREATE TABLE t (id INTEGER)');

            return 'done';
        }, 'test:domain');

        self::assertSame('done', $result);
        self::assertSame(1, $depthInside, 'the callback should run at transaction depth 1');
        self::assertSame(0, $this->connection->transactionLevel(), 'the transaction must be released after the callback');
        // The DDL committed, proving the transaction wrapped the work.
        self::assertCount(1, $this->connection->table('sqlite_master')->where('name', '=', 't')->get());
    }

    /**
     * SqliteLock rolls back the guarded work when the callback throws.
     */
    public function testSqliteLockRollsBackOnThrow(): void
    {
        $lock = new SqliteLock($this->connection);

        try {
            $lock->withLock(function (): void {
                $this->connection->statement('CREATE TABLE t (id INTEGER)');
                throw new \RuntimeException('boom');
            }, 'test:domain');
        } catch (\RuntimeException $e) {
            self::assertSame('boom', $e->getMessage(), 'the callback exception must propagate');
        }

        self::assertSame(0, $this->connection->transactionLevel());
        self::assertCount(
            0,
            $this->connection->table('sqlite_master')->where('name', '=', 't')->get(),
            'the guarded work must be rolled back',
        );
    }

    /**
     * SqliteLock refuses to run inside an open transaction: nesting would
     * degrade to a savepoint, which takes no RESERVED lock and serializes
     * nothing — a silent voiding of the lock's guarantee.
     */
    public function testSqliteLockRefusesOpenTransaction(): void
    {
        $lock = new SqliteLock($this->connection);
        $this->connection->beginTransaction();

        try {
            $lock->withLock(fn (): null => null, 'test:domain');
            self::fail('SqliteLock must refuse an already-open transaction.');
        } catch (ConnectionException $e) {
            self::assertStringContainsString('transaction-free connection', $e->getMessage());
        } finally {
            $this->connection->rollBack();
        }
    }

    /**
     * The noop lock runs the callback untouched — the documented default
     * when the host has not chosen a locking strategy.
     */
    public function testNoopLockRunsCallbackDirectly(): void
    {
        $ran = false;
        $result = (new NoopLock())->withLock(function () use (&$ran): int {
            $ran = true;

            return 42;
        }, 'test:domain');

        self::assertTrue($ran);
        self::assertSame(42, $result);
        self::assertSame(0, $this->connection->transactionLevel(), 'NoopLock must not open a transaction');
    }
}
