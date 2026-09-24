<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Locks;

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
     * @param  SqliteConnection  $connection
     */
    public function __construct(protected readonly SqliteConnection $connection) {}

    /**
     * Run the callback inside a `BEGIN IMMEDIATE` transaction.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @param  string  $name  Accepted for signature parity and ignored.
     * @return TReturn
     * @throws ConnectionException
     * @throws \Throwable
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
