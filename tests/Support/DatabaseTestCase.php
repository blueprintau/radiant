<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Support;

use BlueprintAU\Radiant\Database;
use BlueprintAU\Radiant\Database\Connections\SqlConnection;
use BlueprintAU\Radiant\Database\DatabaseManager;
use BlueprintAU\Radiant\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase;

/**
 * Abstract base for tests that exercise a live database connection.
 *
 * Owns the sqlite `:memory:` DatabaseManager + static facade wiring that
 * every DB-backed test previously duplicated: setUp() builds a fresh
 * manager, installs it on the static Database facade and exposes the
 * default SqlConnection; tearDown() resets the facade to a fresh empty
 * manager so the static global never leaks between test classes.
 *
 * Subclasses create their tables and seed their data by overriding
 * setUpDatabase() — no parent::setUp() call is needed (the base setUp()
 * runs the hook itself). Tables are declared with createTables(), which
 * accepts hand-built Blueprints and model class-strings interchangeably.
 *
 * The connection config comes from connectionConfig() — the default is
 * sqlite :memory:, and subclasses may override it (e.g. a file-backed
 * sqlite database for persistence/reconnection tests). The manager map
 * always keys the config as 'default'. Extra NAMED connections come from
 * additionalConnections() for cross-connection tests.
 *
 * The connection is deliberately NOT wired automatically to every model
 * class: not every attribute path runs on SQLite (deferrable foreign
 * keys, some partial-index predicates throw UnsupportedFeatureException),
 * so schema creation stays opt-in per test.
 */
abstract class DatabaseTestCase extends TestCase
{
    /** @var SqlConnection The default SQL connection of the per-test manager. */
    protected SqlConnection $connection;

    /** @var DatabaseManager The per-test manager installed on the static facade. */
    protected DatabaseManager $manager;

    /**
     * Build the per-test manager — connectionConfig() plus any named
     * extras from additionalConnections() — install it on the static
     * facade and run the setUpDatabase() hook.
     */
    protected function setUp(): void
    {
        $this->manager = new DatabaseManager([
            'default' => $this->connectionConfig(),
            ...$this->additionalConnections(),
        ]);
        Database::setManager($this->manager);
        $this->connection = $this->manager->sqlConnection();

        $this->setUpDatabase();
    }

    /**
     * Reset the static facade to a fresh empty :memory: manager so the
     * global state of one test class never leaks into the next.
     */
    protected function tearDown(): void
    {
        Database::setManager(new DatabaseManager([
            'default' => ['driver' => 'sqlite', 'database' => ':memory:'],
        ]));
    }

    /**
     * The config for the per-test 'default' connection. The default body
     * wires sqlite :memory:; override for a different database (a file-
     * backed sqlite database, extra options, …).
     *
     * @return array<string, mixed> The connection config (the 'driver'
     *         key must resolve in the manager's connector registry).
     */
    protected function connectionConfig(): array
    {
        return ['driver' => 'sqlite', 'database' => ':memory:'];
    }

    /**
     * Extra NAMED connections to register alongside 'default' — the map
     * keys are the connection names. Reach them through
     * Database::connection($name) / usingConnection($name, …) or
     * $this->manager->connection($name); 'default' always stays
     * connectionConfig(), so $this->connection is unaffected.
     *
     * The default body is empty — most tests need only one connection.
     *
     * @return array<string, array<string, mixed>> The named connection
     *         configs; each 'driver' key must resolve in the manager's
     *         connector registry.
     */
    protected function additionalConnections(): array
    {
        return [];
    }

    /**
     * Hook for subclasses: create tables and seed data. Runs at the end of
     * setUp(), after the connection is wired. The default body is empty.
     */
    protected function setUpDatabase(): void {}

    /**
     * Create one or more tables from hand-built blueprints and/or model
     * class-strings, in declaration order.
     *
     * @param Blueprint|class-string<\BlueprintAU\Radiant\Model> ...$sources The tables to create — a
     *        Blueprint is created verbatim; a model class-string is folded
     *        into a blueprint via Blueprint::fromMetadata().
     */
    final protected function createTables(Blueprint|string ...$sources): void
    {
        foreach ($sources as $source) {
            $this->connection->create(
                $source instanceof Blueprint ? $source : Blueprint::fromMetadata($source),
            );
        }
    }
}
