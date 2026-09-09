<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Schema\Lockers;

/**
 * Postgres schema lock: a session-level advisory lock on a fixed key.
 *
 * `pg_advisory_lock(hashtext(...))` blocks until acquired; the session
 * keeps the lock until `pg_advisory_unlock` runs on the SAME session —
 * which is why the adapter pins the lock to the connection instance.
 */
final class PostgresSchemaLocker extends SessionLockSchemaLocker
{
    /**
     * The lock acquisition statement — blocks until acquired.
     *
     * @return string The lock SQL.
     */
    #[\Override]
    protected function lockStatement(): string
    {
        return "SELECT pg_advisory_lock(hashtext('radiant:schema'))";
    }

    /**
     * The lock release statement.
     *
     * @return string The unlock SQL.
     */
    #[\Override]
    protected function unlockStatement(): string
    {
        return "SELECT pg_advisory_unlock(hashtext('radiant:schema'))";
    }
}
