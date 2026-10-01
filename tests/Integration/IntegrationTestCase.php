<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Integration;

use BlueprintAU\Radiant\Database\Connections\SqlConnection;
use BlueprintAU\Radiant\Tests\Support\CrudCycleTests;
use BlueprintAU\Radiant\Tests\Support\DatabaseTestCase;

/**
 * Abstract base for the live-server integration suites — the shared
 * plumbing every driver suite would otherwise duplicate.
 *
 * Runs the dialect-agnostic CrudCycleTests cycle and registers a second
 * named connection ('probe', same config as 'default') that the lock-
 * exclusivity tests use to prove the advisory lock is held from another
 * session — reach it through probe().
 *
 * **Policy: fail, never skip.** These suites require a reachable server.
 * When no server is up, they FAIL — the caller must exclude the
 * `integration-remote-sql` group explicitly (phpunit.xml excludes it for
 * the default local run; CI runs it where a failure is a real signal).
 *
 * Concrete subclasses pick the driver: connectionConfig() returns the
 * live server's config (env-overridable), and the dialect-specific tests
 * (advisory-lock SQL, inspector messages, transactional DDL) live there.
 * Table lifecycle is inherited from DatabaseTestCase — tests declare
 * tables with createTables() and teardown drops them in reverse order.
 */
abstract class IntegrationTestCase extends DatabaseTestCase
{
    use CrudCycleTests;

    /**
     * Register the probe — a second session on the same server, built
     * from the same config as 'default'.
     *
     * @return array<string, array<string, mixed>>
     */
    #[\Override]
    protected function additionalConnections(): array
    {
        return ['probe' => $this->connectionConfig()];
    }

    /**
     * The probe connection — a second session on the same server.
     *
     * @return SqlConnection
     */
    protected function probe(): SqlConnection
    {
        return $this->manager->sqlConnection('probe');
    }
}
