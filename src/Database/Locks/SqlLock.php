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
     * @param  SqlConnection  $connection
     */
    public function __construct(protected readonly SqlConnection $connection) {}

    /**
     * The SQL that acquires the dialect's lock.
     *
     * The lock name never appears in this SQL — it is bound as a parameter
     * by {@see withLock()}.
     *
     * @return string
     */
    abstract protected function lockStatement(): string;

    /**
     * The SQL that releases the dialect's lock.
     *
     * @return string
     */
    abstract protected function unlockStatement(): string;

    /**
     * Run the callback while holding the named session-level lock.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @param  string  $name
     * @return TReturn
     * @throws \Throwable
     */
    #[\Override]
    final public function withLock(callable $callback, string $name): mixed
    {
        // The lock statement runs with the name bound (no interpolation); a
        // failure to acquire must surface as an exception, not a silent
        // no-lock run.
        $this->connection->statement($this->lockStatement(), [$name]);
        try {
            return $callback();
        } finally {
            $this->connection->statement($this->unlockStatement(), [$name]);
        }
    }
}
