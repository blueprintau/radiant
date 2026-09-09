<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Schema\Lockers;

/**
 * MySQL schema lock: `GET_LOCK` / `RELEASE_LOCK` on a fixed lock name.
 *
 * `GET_LOCK` blocks until the named lock is free (or the timeout elapses,
 * returning 0 — PDO then surfaces a non-exception zero row, so the
 * statement is executed and checked by the caller in the base class's
 * `statement()` path only insofar as SQL allows; the fixed 30-second
 * timeout keeps a wedged deploy from hanging forever).
 */
final class MySqlSchemaLocker extends SessionLockSchemaLocker
{
    /** The fixed lock name — one namespace for all Radiant schema work. */
    private const LOCK_NAME = 'radiant:schema';

    /**
     * The lock acquisition statement — blocks up to 30s, then fails.
     *
     * GET_LOCK returns 0 on timeout and NULL on error; making the failure
     * visible requires converting the result to an error. The SELECT wraps
     * the call in a signal expression that raises an error via a division
     * by zero on a non-1 result — a portable SQL trick MySQL evaluates
     * deterministically here.
     *
     * @return string The lock SQL.
     */
    #[\Override]
    protected function lockStatement(): string
    {
        return sprintf(
            "SELECT IF(GET_LOCK('%s', 30) = 1, 1, crc32('lock-timeout') DIV 0)",
            self::LOCK_NAME,
        );
    }

    /**
     * The lock release statement.
     *
     * @return string The unlock SQL.
     */
    #[\Override]
    protected function unlockStatement(): string
    {
        return sprintf("DO RELEASE_LOCK('%s')", self::LOCK_NAME);
    }
}
