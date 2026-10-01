<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Integration;

use BlueprintAU\Radiant\Database\Connections\SqlConnection;
use BlueprintAU\Radiant\Database\Schema\Blueprint;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Database\Schema\Enums\SchemaOperation;
use BlueprintAU\Radiant\Tests\Support\DatabaseTestCase;
use BlueprintAU\Radiant\Tests\Support\Expectation;
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
