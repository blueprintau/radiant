<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Schema;

/**
 * A cross-process mutual-exclusion gate for schema work.
 *
 * `diff → apply` reads the live schema, computes changes, then executes
 * DDL — with `DROP TABLE` in its vocabulary. Two instances doing this
 * concurrently (rolling deploys, cron overlap, multi-node) interleave
 * inspector reads and DDL against stale snapshots: duplicated CREATEs fail
 * rollouts, half-applied ALTERs wedge schema state, and a DROP decision
 * racing another instance's rename loses data. Every migration tool
 * eventually needs this lock; this is the seam.
 *
 * The host wraps its schema loop:
 *
 * ```
 * $locker = new PostgresSchemaLocker($connection);   // dialect adapter
 * $locker->withLock(function () use ($differ, $connection, $models): void {
 *     foreach ($differ->diff(Blueprint::fromMetadata(...)) as $change) {
 *         $connection->apply($change);
 *     }
 * });
 * ```
 *
 * Dialect adapters ship in {@see Lockers}; the default
 * {@see NoopSchemaLocker} documents that locking is the host's explicit
 * decision — the library never silently takes global locks a host did not
 * ask for.
 */
interface SchemaLocker
{
    /**
     * Run the callback while holding the cross-process schema lock.
     *
     * Implementations must block until the lock is acquired (or fail
     * loudly), hold it for the callback's duration, and always release it —
     * including on exception. The callback receives no arguments; it
     * closes over its own state.
     *
     * @template TReturn
     *
     * @param callable(): TReturn $callback The schema work to run under lock.
     * @return TReturn The callback's return value.
     * @throws \Throwable Whatever the callback throws, after releasing the lock.
     */
    public function withLock(callable $callback): mixed;
}
