<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Integration;

use BlueprintAU\Radiant\Database\Connections\SqlConnection;
use BlueprintAU\Radiant\Database\Schema\Blueprint;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Database\Schema\Enums\SchemaOperation;
use BlueprintAU\Radiant\Tests\Support\DatabaseTestCase;
use BlueprintAU\Radiant\Tests\Support\Expectation;

/**
 * Abstract base for the live-server integration suites — the shared
 * plumbing every driver suite would otherwise duplicate.
 *
 * Runs the dialect-agnostic CRUD/transaction/aggregate/alter cycle and
 * registers a second named connection ('probe', same config as
 * 'default') that the lock-exclusivity tests use to prove the advisory
 * lock is held from another session — reach it through probe().
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

    /**
     * A full CRUD cycle against the live server.
     */
    public function testCrudCycle(): void
    {
        $this->createTables((new Blueprint('users'))
            ->id()
            ->string('name', 100)
            ->column(ColumnType::Int, 'age'));

        $inserted = $this->connection->table('users')->insert([
            ['name' => 'Alice', 'age' => 30],
            ['name' => 'Bob', 'age' => 25],
        ]);
        self::assertSame(2, $inserted);

        $id = $this->connection->table('users')->insertIdColumn('id')->insertGetId(['name' => 'Carol', 'age' => 40]);
        self::assertNotNull($id);

        self::assertSame(3, $this->connection->table('users')->count());

        $updated = $this->connection->table('users')->where('name', '=', 'Bob')->update(['age' => 26]);
        self::assertSame(1, $updated);

        $row = $this->connection->table('users')->where('name', '=', 'Bob')->first();
        self::assertNotNull($row);
        self::assertSame(26, (int) $row->age);

        $deleted = $this->connection->table('users')->where('name', '=', 'Carol')->delete();
        self::assertSame(1, $deleted);
        self::assertSame(2, $this->connection->table('users')->count());
    }

    /**
     * Transactions commit on success and roll back on failure.
     */
    public function testTransactions(): void
    {
        $this->createTables((new Blueprint('users'))
            ->id()
            ->string('name', 100));

        $this->connection->transaction(function (): void {
            $this->connection->table('users')->insert(['name' => 'Alice']);
            $this->connection->transaction(function (): void {
                $this->connection->table('users')->insert(['name' => 'Bob']);
            });
        });
        self::assertSame(2, $this->connection->table('users')->count());

        Expectation::throws(
            fn () => $this->connection->transaction(function (): void {
                $this->connection->table('users')->insert(['name' => 'Carol']);
                throw new \RuntimeException('boom');
            }),
            \RuntimeException::class,
        );

        // The count is the assertion's subject — phpstan 2.2.16's
        // alreadyNarrowedType check misreads the literal-vs-count comparison
        // as trivially true.
        /** @phpstan-ignore staticMethod.alreadyNarrowedType (the row count is the assertion's subject, not a tautology) */
        self::assertSame(2, $this->connection->table('users')->count());
    }

    /**
     * Aggregates run against the live server.
     */
    public function testAggregates(): void
    {
        $this->createTables((new Blueprint('users'))
            ->id()
            ->string('name', 100)
            ->column(ColumnType::Int, 'age'));

        $this->connection->table('users')->insert([
            ['name' => 'Alice', 'age' => 30],
            ['name' => 'Bob', 'age' => 25],
            ['name' => 'Carol', 'age' => 40],
        ]);

        self::assertSame(95, (int) $this->connection->table('users')->sum('age'));
        self::assertSame(40, (int) $this->connection->table('users')->max('age'));
        self::assertSame(25, (int) $this->connection->table('users')->min('age'));
    }

    /**
     * Schema alter (add/drop column) works on the live server.
     *
     * The drop-column arm is skipped on sqlite — the dialect rebuilds the
     * table instead of dropping the column, so compileDropColumn throws
     * UnsupportedFeatureException.
     */
    public function testSchemaAlter(): void
    {
        $this->createTables((new Blueprint('users'))
            ->id()
            ->string('name', 100));

        $this->connection->alter(SchemaOperation::AddColumn, (new Blueprint('users'))->column(ColumnType::Int, 'age'));
        $this->connection->table('users')->insert(['name' => 'Alice', 'age' => 30]);
        $row = $this->connection->table('users')->where('name', '=', 'Alice')->first();
        self::assertNotNull($row);
        self::assertSame(30, (int) $row->age);

        if ($this->connection instanceof \BlueprintAU\Radiant\Database\Connections\SqliteConnection) {
            return;
        }

        $this->connection->alter(SchemaOperation::DropColumn, (new Blueprint('users'))->dropColumn('age'));
        $row = $this->connection->table('users')->where('name', '=', 'Alice')->first();
        self::assertNotNull($row);
        self::assertObjectNotHasProperty('age', $row);
    }
}
