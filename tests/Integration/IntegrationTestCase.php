<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Integration;

use BlueprintAU\Radiant\Database\Connections\SqlConnection;
use BlueprintAU\Radiant\Database\Schema\Blueprint;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Database\Schema\Enums\SchemaOperation;
use BlueprintAU\Radiant\Tests\Integration\Fixtures\CastProbe;
use BlueprintAU\Radiant\Tests\Integration\Fixtures\CastStatus;
use BlueprintAU\Radiant\Tests\Support\CastRoundTrips;
use BlueprintAU\Radiant\Tests\Support\DatabaseTestCase;
use BlueprintAU\Radiant\Tests\Support\Expectation;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;

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
 * live server's config (env-overridable), connectionClass() the concrete
 * connection type, and inspectorSchemaName() the dialect's name in the
 * inspector's missing-table message. The dialect-specific tests
 * (advisory-lock SQL, transactional DDL) live there. Table lifecycle is
 * inherited from DatabaseTestCase — tests declare tables with
 * createTables() and teardown drops them in reverse order.
 */
abstract class IntegrationTestCase extends DatabaseTestCase
{
    use CastRoundTrips;
    /**
     * The concrete connection class the driver's connector builds.
     *
     * @return class-string<SqlConnection>
     */
    abstract protected function connectionClass(): string;

    /**
     * The dialect's name in the inspector's missing-table message.
     *
     * @return string
     */
    abstract protected function inspectorSchemaName(): string;

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
     * The manager builds the default connection through the driver's
     * connector — the concrete dialect class comes back.
     */
    public function testConnectorReturnsConnection(): void
    {
        self::assertInstanceOf($this->connectionClass(), $this->connection);
    }

    /**
     * Every cast arm round-trips through THIS driver's storage: the row
     * saves, re-fetches by primary key, and every column reads back the
     * value that was saved.
     *
     * The casts are dialect-facing — MySQL stringifies decimals and
     * temporal columns, Postgres stores booleans natively and returns
     * its own temporal text — so this is exactly the seam a per-driver
     * regression hides in. The int-typed Timestamp property is the
     * sharpest arm: its encoder binds a datetime string (the only form
     * MySQL and Postgres accept on a temporal column), and the decode
     * must return the integer the property declares on every driver.
     */
    public function testCastMatrixRoundTrips(): void
    {
        $this->createTables(Blueprint::fromMetadata(CastProbe::class));

        $probe = new CastProbe();
        $probe->intTs = 1791186433;
        $probe->carbonTs = Carbon::createFromTimestamp(1791186433, 'UTC');
        $probe->carbonDt = Carbon::parse('2026-03-04 05:06:07', 'UTC');
        $probe->carbonDate = Carbon::parse('1995-11-30', 'UTC');
        $probe->stringDate = '1990-06-15';
        $probe->flag = true;
        $probe->ratio = 2.75;
        $probe->meta = ['theme' => 'dark', 'tabs' => [1, 2]];
        $probe->status = CastStatus::Published;
        $probe->token = '123e4567-e89b-42d3-a456-426614174000';

        $this->roundTrip($probe, [
            'id', 'int_ts', 'carbon_ts', 'carbon_dt', 'carbon_date', 'string_date',
            'flag', 'ratio', 'meta', 'status', 'token',
        ]);
    }

    /**
     * The nullable timestamp arm survives the null → set → null rewrite
     * cycle on THIS driver — a dialect that stringifies temporal cells
     * must not turn the null back into a zero-date or an empty string.
     */
    public function testNullableTimestampRoundTripsThroughNull(): void
    {
        $this->createTables(Blueprint::fromMetadata(CastProbe::class));

        $probe = new CastProbe();
        $probe->intTs = 1791186433;
        $probe->carbonTs = Carbon::createFromTimestamp(1791186433, 'UTC');
        $probe->carbonDt = Carbon::parse('2026-03-04 05:06:07', 'UTC');
        $probe->carbonDate = Carbon::parse('1995-11-30', 'UTC');
        $probe->stringDate = '1990-06-15';
        $probe->flag = true;
        $probe->ratio = 2.75;
        $probe->meta = ['theme' => 'dark'];
        $probe->status = CastStatus::Draft;
        $probe->token = '123e4567-e89b-42d3-a456-426614174000';
        $probe->nullableIntTs = null;

        [$saved, $fresh] = $this->roundTrip($probe);

        self::assertNull($fresh->nullableIntTs);
        self::assertSame(1791186433, $saved->intTs);

        $fresh->nullableIntTs = 1700000000;
        $fresh->save();

        $rewritten = CastProbe::find($probe->id);

        self::assertNotNull($rewritten);
        self::assertSame(1700000000, $rewritten->nullableIntTs);
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

        $this->connection->alter(SchemaOperation::DropColumn, (new Blueprint('users'))->dropColumn('age'));
        $row = $this->connection->table('users')->where('name', '=', 'Alice')->first();
        self::assertNotNull($row);
        self::assertObjectNotHasProperty('age', $row);
    }

    /**
     * A full rename + reshape sync: a sloppy all-TEXT legacy table is
     * renamed and brought to a properly-typed shape covering every change
     * kind at once — table rename, column rename, adds (incl. NOT NULL),
     * modifies and a drop — and the seeded rows survive. This is the
     * regression test for the sync-failure class where a mixed alter lost
     * its add side, a modify restated the primary key, and a SQLite
     * rebuild could not add a NOT NULL column.
     */
    public function testRenameAndReshapeSyncAppliesEveryChangeKind(): void
    {
        // The shared schema may carry a leftover from a prior run — the
        // rename target must be absent for the declared rename to verify.
        foreach (['orders', 'legacy_orders'] as $stale) {
            if ($this->connection->schemaInspector->hasTable($stale)) {
                $this->connection->drop($stale);
            }
        }

        // The EXISTING (live) definition: a sloppy all-TEXT legacy schema.
        $this->createTables(
            (new Blueprint('legacy_orders'))
                ->column(ColumnType::BigInt, 'id', primaryKey: true, autoIncrement: true)
                ->column(ColumnType::Text, 'order_ref')
                ->column(ColumnType::Text, 'customer_email')
                ->column(ColumnType::Text, 'total_amount')
                ->column(ColumnType::Text, 'legacy_flag', nullable: true)
                ->column(ColumnType::Text, 'created_at'),
        );

        $this->connection->table('legacy_orders')->insert([
            ['order_ref' => 'ORD-1', 'customer_email' => 'a@b.com', 'total_amount' => '199.99', 'legacy_flag' => 'gold', 'created_at' => '2026-01-01 10:00:00'],
            ['order_ref' => 'ORD-2', 'customer_email' => 'c@d.com', 'total_amount' => '49.50', 'legacy_flag' => null, 'created_at' => '2026-02-01 10:00:00'],
        ]);

        // The DESIRED definition: renamed + re-typed, covering every kind.
        $desired = (new Blueprint('orders'))
            ->renamedFrom('legacy_orders')
            ->renameColumn('order_ref', 'order_number')
            ->column(ColumnType::BigInt, 'id', primaryKey: true, autoIncrement: true)
            ->column(ColumnType::String, 'order_number', length: 64)
            ->column(ColumnType::String, 'customer_email', length: 255)
            ->column(ColumnType::Decimal, 'total_amount', precision: 10, scale: 2)
            ->column(ColumnType::DateTime, 'created_at')
            ->column(ColumnType::Int, 'priority', default: 0)
            ->column(ColumnType::String, 'discount_code', length: 32, nullable: true);

        $synchronizer = new \BlueprintAU\Radiant\Database\Schema\SchemaSynchronizer($this->connection);
        $changes = $synchronizer->plan([$desired]);

        // Every change applies cleanly — one at a time, so no failure can
        // mask a later change.
        foreach ($changes as $change) {
            $this->connection->apply($change);
        }

        // The acceptance check: live schema matches the desired shape, the
        // legacy column is gone, and both rows survived.
        $live = $this->connection->schemaInspector->table('orders');
        $liveNames = array_column($live->columns, 'name');
        $desiredNames = array_map(fn (array $c) => $c['name'], $desired->getColumns());

        self::assertSame([], array_diff($desiredNames, $liveNames), 'all desired columns must be present');
        self::assertSame([], array_diff($liveNames, $desiredNames), 'no extra live columns (legacy_flag dropped)');
        self::assertSame(2, $this->connection->table('orders')->count(), 'the seeded rows must survive');

        // A re-diff converges — the schema is fully in sync.
        $converged = (new Blueprint('orders'))
            ->column(ColumnType::BigInt, 'id', primaryKey: true, autoIncrement: true)
            ->column(ColumnType::String, 'order_number', length: 64)
            ->column(ColumnType::String, 'customer_email', length: 255)
            ->column(ColumnType::Decimal, 'total_amount', precision: 10, scale: 2)
            ->column(ColumnType::DateTime, 'created_at')
            ->column(ColumnType::Int, 'priority', default: 0)
            ->column(ColumnType::String, 'discount_code', length: 32, nullable: true);
        self::assertSame([], $synchronizer->plan([$converged]), 'a second plan must be empty');
    }

    /**
     * A nested transaction that throws rolls back to its savepoint — the
     * outer transaction's writes survive and the inner ones do not.
     */
    public function testNestedTransactionRollsBackToSavepoint(): void
    {
        $this->createTables((new Blueprint('rmt_savepoint'))->id()->string('name', 64));

        $this->connection->transaction(function (): void {
            $this->connection->table('rmt_savepoint')->insert(['name' => 'outer']);

            try {
                $this->connection->transaction(function (): void {
                    $this->connection->table('rmt_savepoint')->insert(['name' => 'inner']);
                    throw new \RuntimeException('boom');
                });
            } catch (\RuntimeException) {
                // The inner rollback is the subject; the outer continues.
            }
        });

        $names = $this->connection->table('rmt_savepoint')->pluck('name')->all();
        self::assertSame(['outer'], $names, 'the inner savepoint must roll back, the outer commit must hold');
    }

    /**
     * A nested transaction that succeeds releases its savepoint and the
     * outer commit persists both frames' writes.
     */
    public function testNestedTransactionCommitPersistsBothFrames(): void
    {
        $this->createTables((new Blueprint('rmt_savepoint'))->id()->string('name', 64));

        $this->connection->transaction(function (): void {
            $this->connection->table('rmt_savepoint')->insert(['name' => 'outer']);
            $this->connection->transaction(function (): void {
                $this->connection->table('rmt_savepoint')->insert(['name' => 'inner']);
            });
        });

        self::assertSame(2, $this->connection->table('rmt_savepoint')->count());
    }

    /**
     * The inspector reads tables, columns, primary keys and foreign keys
     * back from the live schema.
     */
    public function testInspectorReadsSchema(): void
    {
        $this->createTables(
            (new Blueprint('rmt_ins_parent'))->id()->string('name', 64),
            (new Blueprint('rmt_ins_child'))
                ->id()
                ->foreignId('parentId', 'rmt_ins_parent')
                ->string('label', 32),
        );

        $inspector = $this->connection->schemaInspector;

        self::assertContains('rmt_ins_parent', $inspector->tables());
        self::assertTrue($inspector->hasTable('rmt_ins_parent'));

        $parent = $inspector->table('rmt_ins_parent');
        self::assertSame(['id', 'name'], array_column($parent->columns, 'name'));
        self::assertTrue($parent->columns[0]['primaryKey']);
        self::assertFalse($parent->columns[1]['nullable']);

        $child = $inspector->table('rmt_ins_child');
        self::assertCount(1, $child->foreignKeys);
        self::assertSame(['parentId'], $child->foreignKeys[0]['columns']);
        self::assertSame('rmt_ins_parent', $child->foreignKeys[0]['referencesTable']);
        self::assertSame(['id'], $child->foreignKeys[0]['referencesColumns']);
    }

    /**
     * The inspector reads a declared unique index back.
     */
    public function testInspectorReadsUniqueIndex(): void
    {
        $this->createTables(
            (new Blueprint('rmt_ins_idx'))
                ->id()
                ->column(ColumnType::String, 'email', length: 255, unique: true),
        );

        $live = $this->connection->schemaInspector->table('rmt_ins_idx');
        $columns = array_map(
            fn (array $index) => $index['columns'],
            array_values(array_filter($live->indexes, fn (array $index) => $index['unique'])),
        );

        self::assertContains(['email'], $columns);
    }

    /**
     * The inspector lists the tables that declare a foreign key into a
     * given table.
     */
    public function testInspectorReferencingTables(): void
    {
        $this->createTables(
            (new Blueprint('rmt_ref_parent'))->id(),
            (new Blueprint('rmt_ref_child'))
                ->id()
                ->foreignId('parentId', 'rmt_ref_parent'),
        );

        self::assertSame(
            ['rmt_ref_child'],
            $this->connection->schemaInspector->referencingTables('rmt_ref_parent'),
        );
    }

    /**
     * The inspector's content-drift comparison maps the dialect's native
     * type text onto the declared logical types.
     *
     * @param  string  $liveType  The native type text the inspector reads.
     * @param  ColumnType  $declaredType  The declared logical type.
     * @param  int|null  $length  The declared length, if any.
     * @param  bool  $expected  Whether the pair must match.
     */
    #[DataProvider('columnTypeMatchesProvider')]
    public function testInspectorColumnTypeMatches(string $liveType, ColumnType $declaredType, ?int $length, bool $expected): void
    {
        self::assertSame(
            $expected,
            $this->connection->schemaInspector->columnTypeMatches($liveType, $declaredType, $length),
        );
    }

    /**
     * The (liveType, declaredType, length, expected) tuples per dialect.
     *
     * @return iterable<string, array{string, ColumnType, int|null, bool}>
     */
    abstract public static function columnTypeMatchesProvider(): iterable;

    /**
     * Reading a missing table fails fast with the dialect's message.
     */
    public function testInspectorMissingTableThrows(): void
    {
        Expectation::throwsWithMessage(
            fn () => $this->connection->schemaInspector->table('rmt_missing'),
            \RuntimeException::class,
            "does not exist in the {$this->inspectorSchemaName()} schema",
        );
    }
}
