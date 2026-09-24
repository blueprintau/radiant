<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Locks;

/**
 * A cross-process mutual-exclusion gate for arbitrary work.
 *
 * Anything a multi-instance host must serialize — the schema `diff → apply`
 * loop (with `DROP TABLE` in its vocabulary), cache warmups, cron jobs that
 * must not overlap — needs this gate. The library ships SQL-backed adapters
 * for each dialect; the host decides when and what to lock, because a lock
 * nobody asked for is a deadlock nobody can debug.
 *
 * The host wraps its critical section:
 *
 * ```
 * $lock = new PostgresLock($connection);   // dialect adapter
 * $lock->withLock(function (): void {
 *     // ... work that must be serialized across processes ...
 * });
 * ```
 *
 * Dialect adapters ship in {@see BlueprintAU\Radiant\Database\Locks}; the
 * default {@see NoopLock} documents that locking is the host's explicit
 * decision — the library never silently takes global locks a host did not
 * ask for.
 */
interface Lock
{
    /**
     * Run the callback while holding the named cross-process lock.
     *
     * Implementations must block until the lock is acquired (or fail
     * loudly), hold it for the callback's duration, and always release it —
     * including on exception. The name is supplied per call, not per
     * adapter: one name is one mutual-exclusion domain.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @param  string  $name  A distinct name per distinct critical section.
     * @return TReturn
     * @throws \Throwable
     */
    public function withLock(callable $callback, string $name): mixed;
}
