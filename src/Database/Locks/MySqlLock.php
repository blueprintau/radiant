<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Locks;

use BlueprintAU\Radiant\Database\Connections\MySqlConnection;
use BlueprintAU\Radiant\Database\Connections\SqlConnection;

/**
 * MySQL lock: `GET_LOCK` / `RELEASE_LOCK` on a named lock.
 *
 * `GET_LOCK` blocks until the named lock is free (or the timeout elapses,
 * returning 0 — PDO then surfaces a non-exception zero row, so the
 * statement is executed and checked by the caller in the base class's
 * `statement()` path only insofar as SQL allows; the fixed 30-second
 * timeout keeps a wedged worker from hanging forever).
 *
 * The lock name is supplied at call time via {@see withLock()} — one name
 * is one mutual-exclusion domain, so distinct jobs use distinct names.
 */
final class MySqlLock extends SqlLock
{
    /**
     * @param MySqlConnection $connection The connection to lock on — the
     *        guarded work must run on the same session.
     */
    public function __construct(MySqlConnection $connection)
    {
        parent::__construct($connection);
    }

    /**
     * The lock acquisition statement — blocks up to 30s, then fails.
     *
     * GET_LOCK returns 0 on timeout and NULL on error; making the failure
     * visible requires converting the result to an error. The SELECT wraps
     * the call in a signal expression that raises an error via a division
     * by zero on a non-1 result — a portable SQL trick MySQL evaluates
     * deterministically here.
     *
     * @param string $name The named lock to acquire.
     * @return string The lock SQL.
     */
    #[\Override]
    protected function lockStatement(string $name): string
    {
        return sprintf(
            "SELECT IF(GET_LOCK('%s', 30) = 1, 1, crc32('lock-timeout') DIV 0)",
            $name,
        );
    }

    /**
     * The lock release statement.
     *
     * @param string $name The named lock to release.
     * @return string The unlock SQL.
     */
    #[\Override]
    protected function unlockStatement(string $name): string
    {
        return sprintf("DO RELEASE_LOCK('%s')", $name);
    }
}
