<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Schema\Lockers;

use BlueprintAU\Radiant\Database\Connections\SqlConnection;
use BlueprintAU\Radiant\Database\Connections\SqliteConnection;
use BlueprintAU\Radiant\Database\Schema\SchemaLocker;

/**
 * SQLite schema lock: a write transaction (`BEGIN IMMEDIATE`).
 *
 * SQLite serializes writers database-wide; an `IMMEDIATE` transaction
 * takes the RESERVED lock up front, so concurrent migrators block instead
 * of racing. The nested-transaction machinery already knows how to
 * participate (savepoints), so the adapter opens the immediate transaction
 * through the connection's public API — no raw PDO poking.
 */
final class SqliteSchemaLocker implements SchemaLocker
{
    /**
     * @param SqliteConnection $connection The connection to lock on. The DDL
     *        work must run on the SAME connection — SQLite locks are
     *        database-file-wide, but the immediate transaction must cover
     *        the DDL for correctness.
     */
    public function __construct(protected readonly SqliteConnection $connection) {}

    /**
     * Run the callback inside a `BEGIN IMMEDIATE` transaction.
     *
     * Uses the connection's savepoint machinery: at depth 0 the connection
     * issues `BEGIN IMMEDIATE`; deeper, it becomes a savepoint — either way
     * the callback's own transaction() calls keep working unchanged.
     *
     * @template TReturn
     *
     * @param callable(): TReturn $callback The schema work.
     * @return TReturn The callback's return value.
     * @throws \Throwable Whatever the callback throws, after rolling back.
     */
    #[\Override]
    public function withLock(callable $callback): mixed
    {
        return $this->connection->transaction(function () use ($callback): mixed {
            return $callback();
        });
    }
}
