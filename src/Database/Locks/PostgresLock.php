<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Locks;

use BlueprintAU\Radiant\Database\Connections\PostgresConnection;

/**
 * Postgres lock: a session-level advisory lock on a named key.
 *
 * `pg_advisory_lock(hashtext(...))` blocks until acquired; the session
 * keeps the lock until `pg_advisory_unlock` runs on the SAME session —
 * which is why the adapter pins the lock to the connection instance.
 *
 * The lock name is supplied at call time via {@see withLock()} — one name
 * is one mutual-exclusion domain, so distinct jobs use distinct names.
 */
final class PostgresLock extends SqlLock
{
    /**
     * @param  PostgresConnection  $connection
     */
    public function __construct(PostgresConnection $connection)
    {
        parent::__construct($connection);
    }

    /**
     * The lock acquisition statement — blocks until acquired.
     *
     * @return string
     */
    #[\Override]
    protected function lockStatement(): string
    {
        return 'SELECT pg_advisory_lock(hashtext(?))';
    }

    /**
     * The lock release statement.
     *
     * @return string
     */
    #[\Override]
    protected function unlockStatement(): string
    {
        return 'SELECT pg_advisory_unlock(hashtext(?))';
    }
}
