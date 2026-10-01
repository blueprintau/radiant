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
 *
 * Tables created through createTables() are tracked and dropped in
 * reverse creation order at teardown — best-effort, so a test that left
 * the connection dead still tears down cleanly. The default-on policy
 * keeps a persistent backend (a file-backed sqlite database) from
 * leaking tables between tests; createTablesUntracked() opts individual
 * tables out.
 */
abstract class DatabaseTestCase extends TestCase
{
    /** @var SqlConnection The default SQL connection of the per-test manager. */
    protected SqlConnection $connection;

    /** @var DatabaseManager The per-test manager installed on the static facade. */
    protected DatabaseManager $manager;

    /** @var list<string> Tables created via createTables(), in creation order. */
    private array $createdTables = [];

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
     * global state of one test class never leaks into the next — after
     * dropping the tables createTables() created (reverse order, so FK
     * children go before their parents). The drops go through the
     * connection's drop() so each dialect's identifier quoting applies;
     * a dead connection or an already-missing table is swallowed.
     */
    protected function tearDown(): void
    {
        if ($this->createdTables !== []) {
            foreach (array_reverse($this->createdTables) as $table) {
                try {
                    $this->connection->drop($table);
                } catch (\Throwable) {
                    // Best-effort: the connection may be dead (a
                    // connection-loss test) or the table already gone —
                    // drop() compiles no IF EXISTS, so this catch is the
                    // IF EXISTS.
                }
            }
            $this->createdTables = [];
        }

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
     * class-strings, in declaration order — tracked for teardown.
     *
     * Created tables are dropped in reverse creation order at teardown
     * (see the class docblock). For a table the test manages its own
     * lifecycle for, use {@see createTablesUntracked()}.
     *
     * @param  Blueprint|class-string<\BlueprintAU\Radiant\Model>  ...$sources  The tables to create — a
     *        Blueprint is created verbatim; a model class-string is folded
     *        into a blueprint via Blueprint::fromMetadata().
     */
    final protected function createTables(Blueprint|string ...$sources): void
    {
        $this->createSources($sources);
        $this->trackSources($sources);
    }

    /**
     * Create one or more tables WITHOUT tracking them for teardown —
     * the test manages the table's lifecycle itself (e.g. a table that
     * must survive teardown, or one the test drops explicitly).
     *
     * @param  Blueprint|class-string<\BlueprintAU\Radiant\Model>  ...$sources  The tables to create.
     */
    final protected function createTablesUntracked(Blueprint|string ...$sources): void
    {
        $this->createSources($sources);
    }

    /**
     * Create the sources in declaration order — the shared body of both
     * createTables variants.
     *
     * @param  array<int|string, Blueprint|class-string<\BlueprintAU\Radiant\Model>>  $sources
     */
    private function createSources(array $sources): void
    {
        foreach ($sources as $source) {
            $this->connection->create(
                $source instanceof Blueprint ? $source : Blueprint::fromMetadata($source),
            );
        }
    }

    /**
     * Record the sources' table names for teardown, in creation order.
     *
     * @param  array<int|string, Blueprint|class-string<\BlueprintAU\Radiant\Model>>  $sources
     */
    private function trackSources(array $sources): void
    {
        foreach ($sources as $source) {
            $this->createdTables[] = $source instanceof Blueprint
                ? $source->getTable()
                : Blueprint::fromMetadata($source)->getTable();
        }
    }
}
