<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Locks;

use BlueprintAU\Radiant\Database\Connections\SqliteConnection;
use BlueprintAU\Radiant\Database\Exceptions\ConnectionException;

/**
 * SQLite lock: a write transaction.
 *
 * SQLite serializes writers database-wide; a writer holds the RESERVED
 * lock for its transaction's duration, so concurrent workers block
 * instead of racing. The transaction is opened through the connection's
 * public API (`PDO::beginTransaction()`), which on pdo_sqlite issues a
 * DEFERRED `BEGIN` — the RESERVED lock is taken on the first write, not
 * at begin. A plan→apply flow writes early, and any concurrent writer
 * blocks the whole transaction anyway, so the serialization holds; the
 * up-front acquire of a literal `BEGIN IMMEDIATE` is not relied on.
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
     * Run the callback inside a write transaction.
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
