<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Concerns;

/**
 * Detects a connection whose transport has died, and tracks that state.
 *
 * Extracted from {@see \BlueprintAU\Radiant\Database\Connections\SqlConnection}
 * so any connection backend can share the same failure semantics: a
 * connection-loss error marks the instance stale, and a caching layer (e.g.
 * {@see \BlueprintAU\Radiant\Database\DatabaseManager}) consults
 * {@see isStale()} before handing the instance out again — one server
 * restart no longer poisons the cached connection for the life of the
 * process.
 *
 * The SQLSTATE handling is SQL-specific; a non-SQL backend using this trait
 * would override {@see isConnectionLoss()} (or simply never call it, since
 * nothing here auto-marks without a call site).
 */
trait DetectsConnectionLoss
{
    /**
     * Whether a connection-loss error has marked this connection dead.
     *
     * @var bool
     */
    protected bool $stale = false;

    /**
     * Whether this connection has been marked dead by a connection-loss
     * error and should be discarded by a caching layer.
     *
     * @return bool True when a connection-loss error has marked it stale.
     */
    public function isStale(): bool
    {
        return $this->stale;
    }

    /**
     * Mark this connection dead after a connection-loss error.
     *
     * Public so a caching layer can also force-evict; the query paths set
     * it automatically when they detect a connection loss.
     */
    public function markStale(): void
    {
        $this->stale = true;
    }

    /**
     * Clear the stale flag — used after a successful reconnect so a rebuilt
     * or recovered connection is served normally again.
     */
    public function clearStale(): void
    {
        $this->stale = false;
    }

    /**
     * Whether a PDOException looks like a lost connection rather than a
     * statement-level failure.
     *
     * The SQLSTATE codes are the ones the PDO drivers use for "connection
     * no longer valid"; driver-specific server-has-gone-away errors surface
     * as 08xxx codes or generic HY000 with a message match, so both shapes
     * are checked.
     *
     * @param \PDOException $e The exception thrown by the failed query.
     * @return bool True when the failure indicates the connection is gone.
     */
    protected function isConnectionLoss(\PDOException $e): bool
    {
        $sqlstate = (string) ($e->errorInfo[0] ?? $e->getCode());
        if (in_array($sqlstate, ['08001', '08003', '08006', '08007', '08S01', '28000'], true)) {
            return true;
        }

        if ($sqlstate === 'HY000') {
            return str_contains($e->getMessage(), 'server has gone away')
                || str_contains($e->getMessage(), 'Lost connection')
                || str_contains($e->getMessage(), 'Error while sending')
                || str_contains($e->getMessage(), 'broken pipe')
                || str_contains($e->getMessage(), 'connection closed');
        }

        return false;
    }
}
