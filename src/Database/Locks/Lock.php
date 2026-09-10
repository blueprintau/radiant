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
     * including on exception. The callback receives no arguments; it
     * closes over its own state.
     *
     * The name is supplied per call, not per adapter: one name is one
     * mutual-exclusion domain, so distinct jobs use distinct names and a
     * single adapter instance can guard several. Adapters whose mechanism
     * cannot be named (e.g. SQLite's database-wide write transaction)
     * accept and ignore the name — the signature stays uniform.
     *
     * @template TReturn
     *
     * @param callable(): TReturn $callback The work to run under lock.
     * @param string $name The lock domain. Use a distinct name per distinct
     *        critical section; unrelated jobs must not share one.
     * @return TReturn The callback's return value.
     * @throws \Throwable Whatever the callback throws, after releasing the lock.
     */
    public function withLock(callable $callback, string $name): mixed;
}
