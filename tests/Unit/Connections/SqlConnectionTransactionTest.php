<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Connections;

use BlueprintAU\Radiant\Database\Connections\SqliteConnection;
use BlueprintAU\Radiant\Tests\Support\Expectation;
use PHPUnit\Framework\TestCase;

/**
 * Live SQLite tests for {@see \BlueprintAU\Radiant\Database\Connections\SqlConnection}
 * transaction handling — nesting via savepoints, and commit/rollback.
 */
final class SqlConnectionTransactionTest extends TestCase
{
    /**
     * A connection to an in-memory SQLite database.
     *
     * @var SqliteConnection
     */
    private SqliteConnection $connection;

    /**
     * Create the connection and a `users` table.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = new SqliteConnection(new \PDO('sqlite::memory:'));
        $this->connection->statement('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
    }

    /**
     * transaction() commits on success.
     */
    public function testTransactionCommits(): void
    {
        $result = $this->connection->transaction(function () {
            $this->connection->table('users')->insert(['name' => 'Alice']);
            return 'done';
        });

        self::assertSame('done', $result);
        self::assertSame(1, $this->connection->table('users')->count());
        self::assertSame(0, $this->connection->transactionLevel());
    }

    /**
     * transaction() rolls back on a thrown exception and rethrows it.
     */
    public function testTransactionRollsBackOnThrow(): void
    {
        $exception = Expectation::throws(
            fn () => $this->connection->transaction(function () {
                $this->connection->table('users')->insert(['name' => 'Alice']);
                throw new \RuntimeException('boom');
            }),
            \RuntimeException::class,
        );

        self::assertSame('boom', $exception->getMessage());

        self::assertSame(0, $this->connection->table('users')->count());
        self::assertSame(0, $this->connection->transactionLevel());
    }

    /**
     * Nested transaction() calls use savepoints and release on success.
     */
    public function testNestedTransactionsCommit(): void
    {
        $this->connection->transaction(function () {
            $this->connection->table('users')->insert(['name' => 'Alice']);

            $this->connection->transaction(function () {
                $this->connection->table('users')->insert(['name' => 'Bob']);
            });
        });

        self::assertSame(2, $this->connection->table('users')->count());
        self::assertSame(0, $this->connection->transactionLevel());
    }

    /**
     * Rolling back the inner savepoint only undoes the inner write.
     */
    public function testNestedRollbackKeepsOuterWrites(): void
    {
        $this->connection->transaction(function () {
            $this->connection->table('users')->insert(['name' => 'Alice']);

            try {
                $this->connection->transaction(function () {
                    $this->connection->table('users')->insert(['name' => 'Bob']);
                    throw new \RuntimeException('inner boom');
                });
            } catch (\RuntimeException) {
                // swallow — the inner rollback should only undo 'Bob'.
            }
        });

        self::assertSame(1, $this->connection->table('users')->count());
        $row = $this->connection->table('users')->first();
        self::assertNotNull($row);
        self::assertSame('Alice', $row->name);
    }

    /**
     * A manual beginTransaction/commit honors the depth counter.
     */
    public function testManualBeginCommit(): void
    {
        $this->connection->beginTransaction();
        $this->connection->table('users')->insert(['name' => 'Alice']);
        self::assertSame(1, $this->connection->transactionLevel());

        $this->connection->commit();
        self::assertSame(0, $this->connection->transactionLevel());
        self::assertSame(1, $this->connection->table('users')->count());
    }

    /**
     * A manual rollback undoes the writes.
     */
    public function testManualRollback(): void
    {
        $this->connection->beginTransaction();
        $this->connection->table('users')->insert(['name' => 'Alice']);

        $this->connection->rollBack();
        self::assertSame(0, $this->connection->transactionLevel());
        self::assertSame(0, $this->connection->table('users')->count());
    }

    /**
     * A transaction opened inside a Fiber cannot be committed from outside
     * it (regression lock: the level counter and savepoint
     * registry are shared connection state; interleaved coroutine frames
     * would cross-commit each other's work). The guard fires on commit()
     * from a different coroutine; the fiber's own commit succeeds.
     */
    public function testCrossCoroutineCommitGuard(): void
    {
        $fiber = new \Fiber(function (): void {
            $this->connection->beginTransaction();
            $this->connection->table('users')->insert(['name' => 'Fiber']);
            \Fiber::suspend();
            // Resumed after the main coroutine verified the guard.
            $this->connection->commit();
            \Fiber::suspend('committed');
        });

        $fiber->start();
        self::assertSame(1, $this->connection->transactionLevel());

        $exception = Expectation::throwsWithMessage(
            fn () => $this->connection->commit(),
            \LogicException::class,
            'different coroutine',
        );

        // rollBack() is guarded the same way.
        Expectation::throwsWithMessage(
            fn () => $this->connection->rollBack(),
            \LogicException::class,
            'different coroutine',
        );

        // beginTransaction() from another coroutine is guarded too — a
        // nested savepoint opened by the wrong frame would cross-commit.
        Expectation::throwsWithMessage(
            fn () => $this->connection->beginTransaction(),
            \LogicException::class,
            'different coroutine',
        );

        // The guarded attempts changed nothing: level 1, row present but
        // uncommitted.
        self::assertSame(1, $this->connection->transactionLevel());

        // Resuming past the fiber's second suspend() hands back the value
        // it passed to Fiber::suspend('committed'); one more resume lets the
        // fiber body return.
        self::assertSame('committed', $fiber->resume());
        $fiber->resume();
        self::assertTrue($fiber->isTerminated());
        self::assertSame(0, $this->connection->transactionLevel());
        self::assertSame(1, $this->connection->table('users')->count());
    }

    /**
     * Sequential fibers on the same connection are fine: once a transaction
     * is closed, the owner record no longer gates anything (level 0).
     */
    public function testSequentialFibersMayUseConnection(): void
    {
        $first = new \Fiber(function (): void {
            $this->connection->transaction(function (): void {
                $this->connection->table('users')->insert(['name' => 'One']);
            });
        });
        $first->start();
        self::assertTrue($first->isTerminated());

        $second = new \Fiber(function (): void {
            $this->connection->transaction(function (): void {
                $this->connection->table('users')->insert(['name' => 'Two']);
            });
        });
        $second->start();
        self::assertTrue($second->isTerminated());

        self::assertSame(2, $this->connection->table('users')->count());
    }

    /**
     * lockForUpdate() outside a transaction fails fast (regression lock:
     * a FOR UPDATE lock with no surrounding transaction is
     * released the instant the statement completes, i.e. it locks nothing).
     */
    public function testLockForUpdateOutsideTransactionThrows(): void
    {
        $this->connection->table('users')->insert(['name' => 'Alice']);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('transaction');
        $this->connection->select(
            $this->connection->table('users')->where('name', '=', 'Alice')->lockForUpdate(),
        );
    }
}