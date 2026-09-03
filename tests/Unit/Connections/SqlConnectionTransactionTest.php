<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Connections;

use BlueprintAU\Radiant\Database\Connections\SqliteConnection;
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
        try {
            $this->connection->transaction(function () {
                $this->connection->table('users')->insert(['name' => 'Alice']);
                throw new \RuntimeException('boom');
            });
            self::fail('Expected an exception.');
        } catch (\RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }

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
}