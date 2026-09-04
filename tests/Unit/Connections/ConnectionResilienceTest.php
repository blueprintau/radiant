<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Connections;

use BlueprintAU\Radiant\Database\Connections\SqlConnection;
use BlueprintAU\Radiant\Database\Connections\SqliteConnection;
use BlueprintAU\Radiant\Database\DatabaseManager;
use BlueprintAU\Radiant\Database\Exceptions\QueryException;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for connection resilience and transaction safety:
 *
 * - transaction() must surface the ORIGINAL exception when
 *   the rollback also fails, not the secondary rollback error.
 * - a failed commit()/rollBack() must not leave the level
 *   counter stuck (the level is decremented before the PDO call).
 * - the manager evicts a connection marked stale by a
 *   connection-loss error and rebuilds it on the next request.
 */
final class ConnectionResilienceTest extends TestCase
{
    /**
     * The temp SQLite database used by the manager tests.
     *
     * @var string
     */
    private string $dbPath = '';

    /**
     * Clean up the temp database after each test.
     */
    protected function tearDown(): void
    {
        if ($this->dbPath !== '' && is_file($this->dbPath)) {
            @unlink($this->dbPath);
        }
        parent::tearDown();
    }

    // ---- rollback failure preserves the original exception ----

    /**
     * transaction() rethrows the callback's exception even when the
     * rollback's own PDO call fails, and the level still lands at zero.
     */
    public function testTransactionPreservesOriginalExceptionWhenRollbackAlsoFails(): void
    {
        $connection = new SqliteConnection(new \PDO('sqlite::memory:'));

        // Simulate a dead connection at rollback time: break the PDO so the
        // savepoint ROLLBACK inside rollBack() throws a connection-loss
        // error, exactly as a dropped server would.
        $this->breakPdo($connection, 'server closed the connection unexpectedly');

        try {
            $connection->transaction(function (): void {
                throw new \DomainException('the real error');
            });
            self::fail('Expected the original exception to propagate.');
        } catch (\DomainException $e) {
            // The ORIGINAL exception, not the PDOException from rollBack().
            self::assertSame('the real error', $e->getMessage());
        } catch (\PDOException) {
            self::fail('Rollback failure must not replace the original exception.');
        }

        // Despite the failed rollback, the level is not stuck.
        self::assertSame(0, $connection->transactionLevel());
    }

    // ---- level decremented even when the PDO call throws ----

    /**
     * A commit whose PDO call throws must not leave the connection
     * believing it is still inside a transaction.
     */
    public function testFailedCommitDoesNotLeaveLevelStuck(): void
    {
        $connection = new SqliteConnection(new \PDO('sqlite::memory:'));

        $connection->beginTransaction();
        self::assertSame(1, $connection->transactionLevel());

        // Break the PDO so commit()'s $pdo->commit() throws.
        $this->breakPdo($connection, 'server closed the connection unexpectedly');

        try {
            $connection->commit();
            self::fail('Expected commit to throw.');
        } catch (\PDOException) {
            // expected — the underlying commit failed
        }

        self::assertSame(0, $connection->transactionLevel(), 'Failed commit must not leave the level stuck.');
    }

    // ---- stale connections are evicted by the manager ----

    /**
     * Once a connection is marked stale, the manager rebuilds it instead of
     * handing out the dead instance again.
     */
    public function testManagerEvictsStaleConnectionAndRebuilds(): void
    {
        $manager = $this->makeManager();
        $first = $this->sql($manager);
        self::assertFalse($first->isStale());

        // Simulate the run() path marking the connection dead after a
        // connection-loss error (server restart, network blip).
        $first->markStale();

        $second = $this->sql($manager);
        self::assertNotSame($first, $second, 'A stale connection must be evicted and rebuilt.');
        self::assertFalse($second->isStale());
    }

    /**
     * A connection-loss-shaped PDOException on the run path marks the
     * connection stale so the manager evicts it.
     */
    public function testConnectionLossMarksConnectionStale(): void
    {
        $manager = $this->makeManager();
        $connection = $this->sql($manager);

        // Force a real connection-loss failure: break the underlying PDO so
        // prepare() throws with an 08xxx SQLSTATE.
        $this->breakPdo($connection, 'server closed the connection unexpectedly');

        try {
            $connection->selectSql('SELECT 1');
            self::fail('Expected a QueryException.');
        } catch (QueryException) {
            // expected
        }

        self::assertTrue($connection->isStale(), 'A connection-loss PDOException must mark the connection stale.');

        // The manager's next connection() call must evict and rebuild.
        $rebuilt = $this->sql($manager);
        self::assertNotSame($connection, $rebuilt);
    }

    // ---- Helpers ----

    /**
     * A manager over a fresh SQLite file database.
     *
     * @return DatabaseManager The manager.
     */
    private function makeManager(): DatabaseManager
    {
        $this->dbPath = tempnam(sys_get_temp_dir(), 'radiant_evict_') ?: (sys_get_temp_dir() . '/radiant_evict');
        @unlink($this->dbPath);

        return new DatabaseManager([
            'default' => [
                'driver' => 'sqlite',
                'database' => $this->dbPath,
            ],
        ], 'default');
    }

    /**
     * The manager's active connection, narrowed for static analysis.
     *
     * @param DatabaseManager $manager The manager.
     * @return SqlConnection The active SQL connection.
     */
    private function sql(DatabaseManager $manager): SqlConnection
    {
        $connection = $manager->connection();
        \assert($connection instanceof SqlConnection);
        return $connection;
    }

    /**
     * Swap the connection's underlying PDO for one whose prepare() and
     * commit()/rollBack() always throw a connection-loss-shaped
     * PDOException — simulating a dropped server without a real network
     * failure.
     *
     * @param SqlConnection $connection The connection to break.
     * @param string $message The PDOException message fragment to raise.
     */
    private function breakPdo(SqlConnection $connection, string $message): void
    {
        $broken = new FailingPdo($message);

        $property = new \ReflectionProperty(SqlConnection::class, 'pdo');
        $property->setValue($connection, $broken);
    }
}

/**
 * A PDO stand-in whose statement methods always fail with a
 * connection-loss SQLSTATE — the "server died" simulation.
 */
final class FailingPdo extends \PDO
{
    /**
     * The message every failing call raises.
     *
     * @var string
     */
    private string $failureMessage;

    /**
     * @param string $failureMessage The failure message fragment.
     */
    public function __construct(string $failureMessage)
    {
        // Initialize PDO's internal state with a real (unused) connection so
        // method calls on this object do not hit "object uninitialized";
        // every statement method is overridden to fail anyway.
        parent::__construct('sqlite::memory:');
        $this->failureMessage = $failureMessage;
    }

    /**
     * Always throws a connection-loss-shaped PDOException.
     *
     * @param string $query The SQL (never executed).
     * @param array<int, mixed> $options Unused driver options.
     * @return \PDOStatement|False Never returns.
     * @throws \PDOException Always.
     */
    public function prepare(string $query, array $options = []): \PDOStatement|false
    {
        unset($query, $options);
        throw $this->failure();
    }

    /**
     * Always throws a connection-loss-shaped PDOException.
     *
     * @return bool Never returns.
     * @throws \PDOException Always.
     */
    public function commit(): bool
    {
        throw $this->failure();
    }

    /**
     * Always throws a connection-loss-shaped PDOException.
     *
     * @return bool Never returns.
     * @throws \PDOException Always.
     */
    public function rollBack(): bool
    {
        throw $this->failure();
    }

    /**
     * A PDOException with a connection-loss SQLSTATE and message.
     *
     * @return \PDOException The exception to throw.
     */
    private function failure(): \PDOException
    {
        $message = $this->failureMessage;
        $e = new \PDOException($message);
        $e->errorInfo = ['08006', 0, $message];
        return $e;
    }
}
