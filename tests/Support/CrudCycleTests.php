<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Support;

use BlueprintAU\Radiant\Database\Schema\Blueprint;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Database\Schema\Enums\SchemaOperation;

/**
 * The shared live-server CRUD/transaction/aggregate/alter cycle, run by
 * every remote-SQL suite.
 *
 * The tests are dialect-agnostic — they only touch `$this->connection`,
 * which the consuming suite wires through its own connector (so the DSN
 * construction, option merging and post-connect SQL stay exercised).
 * Consuming suites must extend DatabaseTestCase — the tables are created
 * through createTables(), so teardown tracks and drops them.
 */
trait CrudCycleTests
{
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
