<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Locks;

use BlueprintAU\Radiant\Database\Connections\SqlConnection;
use BlueprintAU\Radiant\Database\Connections\SqliteConnection;
use BlueprintAU\Radiant\Database\Exceptions\ConnectionException;

/**
 * SQLite lock: a write transaction (`BEGIN IMMEDIATE`).
 *
 * SQLite serializes writers database-wide; an `IMMEDIATE` transaction
 * takes the RESERVED lock up front, so concurrent workers block instead
 * of racing. The nested-transaction machinery already knows how to
 * participate (savepoints), so the adapter opens the immediate transaction
 * through the connection's public API — no raw PDO poking.
 *
 * Unlike the SQL-statement adapters, this is NOT an advisory lock — it is
 * a write transaction, so it serializes *all* database mutation for its
 * duration. It also cannot nest: entering with a transaction already open
 * degrades to a savepoint, which takes no RESERVED lock and voids the
 * cross-process guarantee — so that case fails loudly instead.
 */
final class SqliteLock implements Lock
{
    /**
     * @param SqliteConnection $connection The connection to lock on. The
     *        guarded work must run on the SAME connection — SQLite locks
     *        are database-file-wide, but the immediate transaction must
     *        cover the work for correctness.
     */
    public function __construct(protected readonly SqliteConnection $connection) {}

    /**
     * Run the callback inside a `BEGIN IMMEDIATE` transaction.
     *
     * At transaction depth 0 the connection issues `BEGIN IMMEDIATE`,
     * taking the RESERVED lock — the actual cross-process gate. If a
     * transaction is ALREADY open, there is no way to upgrade it: nesting
     * would silently become a savepoint and the mutual exclusion would be
     * fictional, so this refuses rather than pretend.
     *
     * @template TReturn
     *
     * @param callable(): TReturn $callback The guarded work.
     * @param string $name Accepted for signature parity with the named
     *        adapters and ignored: SQLite's write transaction is
     *        database-file-wide — there is nothing to name. Distinct jobs
     *        calling with distinct names still serialize against each other.
     * @return TReturn The callback's return value.
     * @throws ConnectionException When a transaction is already open —
     *         the lock cannot be taken without committing or rolling back
     *         the caller's transaction first.
     * @throws \Throwable Whatever the callback throws, after rolling back.
     */
    #[\Override]
    public function withLock(callable $callback, string $name): mixed
    {
        if ($this->connection->transactionLevel() > 0) {
            throw new ConnectionException(
                'SqliteLock requires a transaction-free connection: an open transaction '
                . 'would nest as a savepoint, taking no RESERVED lock and serializing nothing. '
                . 'Commit or roll back the outer transaction before acquiring the lock.',
            );
        }

        return $this->connection->transaction(function () use ($callback): mixed {
            return $callback();
        });
    }
}
