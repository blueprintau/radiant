<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Integration;

use BlueprintAU\Radiant\Database\Schema\Blueprint;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Tests\Support\Expectation;

/**
 * Live Postgres tests for the paths no in-memory engine can serve:
 * transactional DDL, nested transaction savepoints, the session advisory
 * lock and the `pg_catalog`/`information_schema` inspector.
 *
 * The dialect-agnostic CRUD/transaction/aggregate/alter cycle comes from
 * IntegrationTestCase; this class adds the Postgres connection config
 * and the Postgres-specific tests. Table lifecycle is handled by
 * DatabaseTestCase — tests declare tables with createTables() and
 * teardown drops them in reverse creation order.
 *
 * The connection is built by the manager through the connector — with
 * `sslmode`, so the DSN append is exercised — and a second named
 * connection ('probe') backs the lock-exclusivity tests.
 *
 * Env overrides (with local defaults):
 *  - RADIANT_PGSQL_{HOST,PORT,USER,PASSWORD,DATABASE} (127.0.0.1:5432 postgres/postgres radiant)
 */
#[\PHPUnit\Framework\Attributes\Group('integration-remote-sql')]
final class PostgresConnectionRemoteTest extends IntegrationTestCase
{
    /**
     * The config for the per-test 'default' connection — the live
     * Postgres server, from env with local defaults. `sslmode` rides
     * along so the connector's DSN append is exercised.
     *
     * @return array<string, mixed>
     */
    #[\Override]
    protected function connectionConfig(): array
    {
        return [
            'driver' => 'pgsql',
            'host' => getenv('RADIANT_PGSQL_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('RADIANT_PGSQL_PORT') ?: '5432'),
            'database' => getenv('RADIANT_PGSQL_DATABASE') ?: 'radiant',
            'username' => getenv('RADIANT_PGSQL_USER') ?: 'postgres',
            'password' => getenv('RADIANT_PGSQL_PASSWORD') ?: 'postgres',
            'sslmode' => 'prefer',
        ];
    }

    /**
     * Postgres DDL is transactional — a rolled-back transaction undoes a
     * CREATE TABLE.
     */
    public function testTransactionalDdlRollsBack(): void
    {
        self::assertTrue($this->connection->supportsTransactionalDdl());

        Expectation::throws(function (): void {
            $this->connection->transaction(function (): void {
                $this->connection->create((new Blueprint('rmt_tx_ddl'))->id());
                throw new \RuntimeException('boom');
            });
        }, \RuntimeException::class);

        self::assertFalse(
            $this->connection->schemaInspector->hasTable('rmt_tx_ddl'),
            'the CREATE TABLE must roll back with the transaction',
        );
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
     * withLock() holds the session advisory lock exclusively while the
     * callback runs — a second session cannot acquire it.
     */
    public function testWithLockHoldsTheLockExclusively(): void
    {
        $result = $this->connection->withLock(function (): string {
            $probe = $this->probe();
            $row = $probe->selectSql(
                "SELECT (pg_try_advisory_lock(hashtext('rmt:probe'))) AS held",
            )->first();
            self::assertNotNull($row);
            self::assertFalse(
                (bool) $row->held,
                'a second session must not acquire the lock while the callback runs',
            );
            $probe->statement("SELECT pg_advisory_unlock(hashtext('rmt:probe'))");

            return 'value';
        }, 'rmt:probe');

        self::assertSame('value', $result, 'the callback\'s return value must pass through');
    }

    /**
     * withLock() releases the lock when the callback throws — a second
     * session can acquire it afterwards.
     */
    public function testWithLockReleasesOnThrow(): void
    {
        Expectation::throws(function (): void {
            $this->connection->withLock(function (): void {
                throw new \RuntimeException('boom');
            }, 'rmt:release');
        }, \RuntimeException::class);

        $probe = $this->probe();
        $row = $probe->selectSql(
            "SELECT (pg_try_advisory_lock(hashtext('rmt:release'))) AS acquired",
        )->first();
        self::assertNotNull($row);
        self::assertTrue((bool) $row->acquired, 'the lock must be released after the callback throws');
        $probe->statement("SELECT pg_advisory_unlock(hashtext('rmt:release'))");
    }

    /**
     * The inspector reads tables, columns, primary keys and foreign keys
     * back from `information_schema` + `pg_catalog`.
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
     * The inspector's content-drift comparison maps Postgres' udt_name
     * short forms onto the declared logical types.
     */
    public function testInspectorColumnTypeMatches(): void
    {
        $inspector = $this->connection->schemaInspector;

        self::assertTrue($inspector->columnTypeMatches('int4', ColumnType::Int, null));
        self::assertTrue($inspector->columnTypeMatches('varchar', ColumnType::String, 100));
        self::assertTrue($inspector->columnTypeMatches('timestamptz', ColumnType::Timestamp, null));
        self::assertFalse($inspector->columnTypeMatches('int4', ColumnType::String, 100));
    }

    /**
     * Reading a missing table fails fast.
     */
    public function testInspectorMissingTableThrows(): void
    {
        Expectation::throwsWithMessage(
            fn () => $this->connection->schemaInspector->table('rmt_missing'),
            \RuntimeException::class,
            'does not exist in the Postgres schema',
        );
    }
}
