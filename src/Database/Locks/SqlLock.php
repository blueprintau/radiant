<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Locks;

use BlueprintAU\Radiant\Database\Connections\SqlConnection;

/**
 * A lock taken by executing a dialect-supplied SQL statement pair —
 * the shared machinery for the SQL-dialect adapters.
 *
 * Each dialect supplies its lock/unlock SQL via {@see lockStatement()}/
 * {@see unlockStatement()}. The lock is taken on the SAME connection the
 * guarded work runs on (session-scoped semantics), so the adapter holds it
 * for the callback's duration and always releases in `finally`.
 */
abstract class SqlLock implements Lock
{
    /**
     * @param SqlConnection $connection The connection to lock on. The same
     *        connection should run the guarded work, so the session-scoped
     *        lock actually covers it. Subclasses extend the signature with
     *        dialect options (e.g. a lock name), so this constructor is not
     *        final.
     */
    public function __construct(protected readonly SqlConnection $connection) {}

    /**
     * The SQL that acquires the dialect's lock. It must BLOCK until
     * acquired (or throw).
     *
     * @param string $name The lock domain to acquire.
     * @return string The lock SQL.
     */
    abstract protected function lockStatement(string $name): string;

    /**
     * The SQL that releases the dialect's lock.
     *
     * @param string $name The lock domain to release.
     * @return string The unlock SQL.
     */
    abstract protected function unlockStatement(string $name): string;

    /**
     * Run the callback while holding the named session-level lock.
     *
     * @template TReturn
     *
     * @param callable(): TReturn $callback The guarded work.
     * @param string $name The lock domain.
     * @return TReturn The callback's return value.
     * @throws \Throwable Whatever the callback throws, after releasing the lock.
     */
    #[\Override]
    final public function withLock(callable $callback, string $name): mixed
    {
        // The lock statement runs raw (no bindings); a failure to acquire
        // must surface as an exception, not a silent no-lock run.
        $this->connection->statement($this->lockStatement($name));
        try {
            return $callback();
        } finally {
            $this->connection->statement($this->unlockStatement($name));
        }
    }
}
