<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Schema\Lockers;

use BlueprintAU\Radiant\Database\Connections\SqlConnection;
use BlueprintAU\Radiant\Database\Exceptions\UnsupportedFeatureException;
use BlueprintAU\Radiant\Database\Schema\SchemaLocker;

/**
 * Serializes schema work across processes using a session-level lock on
 * the shared connection — the dialect adapters share this machinery.
 *
 * Each dialect supplies its lock/unlock SQL via
 * {@see lockStatement()}/{@see unlockStatement()}. The lock is taken on
 * the SAME connection the DDL runs on (session-scoped semantics), so the
 * adapter holds it for the callback's duration and always releases in
 * `finally`.
 */
abstract class SessionLockSchemaLocker implements SchemaLocker
{
    /**
     * @param SqlConnection $connection The connection to lock on. The same
     *        connection should run the schema work, so the session-scoped
     *        lock actually covers it.
     */
    final public function __construct(protected readonly SqlConnection $connection) {}

    /**
     * The SQL that acquires the dialect's session-level lock. It must BLOCK
     * until acquired (or throw).
     *
     * @return string The lock SQL.
     */
    abstract protected function lockStatement(): string;

    /**
     * The SQL that releases the dialect's session-level lock.
     *
     * @return string The unlock SQL.
     */
    abstract protected function unlockStatement(): string;

    /**
     * Run the callback while holding the session-level schema lock.
     *
     * @template TReturn
     *
     * @param callable(): TReturn $callback The schema work.
     * @return TReturn The callback's return value.
     * @throws \Throwable Whatever the callback throws, after releasing the lock.
     */
    #[\Override]
    final public function withLock(callable $callback): mixed
    {
        // The lock statement runs raw (no bindings); a failure to acquire
        // must surface as an exception, not a silent no-lock run.
        $this->connection->statement($this->lockStatement());
        try {
            return $callback();
        } finally {
            $this->connection->statement($this->unlockStatement());
        }
    }
}
