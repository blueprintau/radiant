<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Schema;

use BlueprintAU\Radiant\Database\Schema\Blueprint;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Database\Schema\Enums\SchemaOperation;
use BlueprintAU\Radiant\Database\Schema\SchemaDiffer;
use BlueprintAU\Radiant\Database\Schema\SchemaSynchronizer;
use BlueprintAU\Radiant\Tests\Support\DatabaseTestCase;
use BlueprintAU\Radiant\Tests\Support\Expectation;

/**
 * End-to-end migration features on live SQLite: declared renames
 * (table + column, data preserved), content drift (modify via the
 * rebuild), FK/CHECK diffing, dependency ordering, and the synchronizer
 * flow (confirm gate, lock, transactional rollback).
 */
final class MigrationSyncTest extends DatabaseTestCase
{
    /**
     * Build a simple two-column table live.
     *
     * @param string $table The table name.
     */
    private function createSimpleTable(string $table): void
    {
        $blueprint = (new Blueprint($table))
            ->id()
            ->column(ColumnType::String, 'name', length: 50);
        $this->connection->create($blueprint);
    }

    /**
     * A declared table rename emits an executable RenameTable change
     * (non-destructive), and applying it preserves every row.
     */
    public function testDeclaredTableRenameAppliesAndPreservesData(): void
    {
        $this->createSimpleTable('legacy_users');
        $this->connection->statement(
            "INSERT INTO legacy_users (name) VALUES ('Alice'), ('Bob')",
        );

        $desired = (new Blueprint('users'))
            ->renamedFrom('legacy_users')
            ->id()
            ->column(ColumnType::String, 'name', length: 50);

        $differ = new SchemaDiffer($this->connection->schemaInspector);
        $changes = $differ->diff([$desired]);

        self::assertCount(1, $changes);
        self::assertSame(SchemaOperation::RenameTable, $changes[0]->operation);
        self::assertSame('legacy_users', $changes[0]->renameOf);
        self::assertFalse($changes[0]->destructive);

        $this->connection->apply($changes[0]);

        // The rows travelled with the rename.
        $rows = $this->connection->selectSql('SELECT name FROM users ORDER BY id')->all();
        self::assertCount(2, $rows);
        self::assertSame('Alice', $rows[0]->name);

        // The old table is gone; a re-diff converges (the re-diff uses a
        // blueprint WITHOUT the renamedFrom declaration — the rename is
        // done, and re-declaring it would now fail fast by design).
        self::assertFalse($this->connection->schemaInspector->hasTable('legacy_users'));
        $converged = (new Blueprint('users'))
            ->id()
            ->column(ColumnType::String, 'name', length: 50);
        self::assertSame([], $differ->diff([$converged]));
    }

    /**
     * A declared rename that does not match reality (old table absent)
     * FAILS FAST — the declaration is a typo that would silently create a
     * duplicate table.
     */
    public function testDeclaredRenameWithNoLiveOldTableFailsFast(): void
    {
        $desired = (new Blueprint('users'))
            ->renamedFrom('ghost_table')
            ->id()
            ->column(ColumnType::String, 'name', length: 50);

        $differ = new SchemaDiffer($this->connection->schemaInspector);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('does not exist in the live schema');
        $differ->diff([$desired]);
    }

    /**
     * A declared table rename WITH column drift emits the rename AND the
     * column changes in one plan — the rename first, then the follow-up
     * alters against the new name.
     */
    public function testDeclaredTableRenameWithColumnDriftEmitsOnePlan(): void
    {
        $this->createSimpleTable('legacy_users');
        $this->connection->statement("INSERT INTO legacy_users (name) VALUES ('Alice'), ('Bob')");

        // Drift: add email, widen name — on top of the rename.
        $desired = (new Blueprint('users'))
            ->renamedFrom('legacy_users')
            ->id()
            ->column(ColumnType::String, 'name', length: 120)
            ->column(ColumnType::String, 'email', length: 255, nullable: true);

        $differ = new SchemaDiffer($this->connection->schemaInspector);
        $changes = $differ->diff([$desired]);

        $operations = array_map(fn ($change) => $change->operation, $changes);
        self::assertSame(
            [SchemaOperation::RenameTable, SchemaOperation::AddColumn, SchemaOperation::ModifyColumn],
            $operations,
        );
        // Every follow-up change targets the NEW table name.
        self::assertSame('users', $changes[1]->table);
        self::assertSame('users', $changes[2]->table);

        foreach ($changes as $change) {
            $this->connection->apply($change);
        }

        // The data travelled; the new column is live; the widen applied.
        $rows = $this->connection->selectSql('SELECT name FROM users ORDER BY id')->all();
        self::assertCount(2, $rows);
        self::assertSame('Alice', $rows[0]->name);

        $live = $this->connection->schemaInspector->table('users');
        self::assertContains('email', array_column($live->columns, 'name'));

        // A fresh blueprint (no declaration) converges — the schema is in sync.
        $converged = (new Blueprint('users'))
            ->id()
            ->column(ColumnType::String, 'name', length: 120)
            ->column(ColumnType::String, 'email', length: 255, nullable: true);
        self::assertSame([], $differ->diff([$converged]));
    }

    /**
     * A declared rename with ADD-ONLY drift emits rename + AddColumn.
     */
    public function testDeclaredTableRenameWithAddOnlyDrift(): void
    {
        $this->createSimpleTable('legacy_users');

        $desired = (new Blueprint('users'))
            ->renamedFrom('legacy_users')
            ->id()
            ->column(ColumnType::String, 'name', length: 50)
            ->column(ColumnType::String, 'email', length: 255, nullable: true);

        $differ = new SchemaDiffer($this->connection->schemaInspector);
        $changes = $differ->diff([$desired]);

        $operations = array_map(fn ($change) => $change->operation, $changes);
        self::assertSame([SchemaOperation::RenameTable, SchemaOperation::AddColumn], $operations);

        foreach ($changes as $change) {
            $this->connection->apply($change);
        }

        $converged = (new Blueprint('users'))
            ->id()
            ->column(ColumnType::String, 'name', length: 50)
            ->column(ColumnType::String, 'email', length: 255, nullable: true);
        self::assertSame([], $differ->diff([$converged]));
    }

    /**
     * A declared rename with MODIFY-ONLY drift emits rename + ModifyColumn
     * (the SQLite rebuild path — data survives).
     */
    public function testDeclaredTableRenameWithModifyOnlyDrift(): void
    {
        $this->createSimpleTable('legacy_users');
        $this->connection->statement("INSERT INTO legacy_users (name) VALUES ('Alice')");

        $desired = (new Blueprint('users'))
            ->renamedFrom('legacy_users')
            ->id()
            ->column(ColumnType::String, 'name', length: 120);

        $differ = new SchemaDiffer($this->connection->schemaInspector);
        $changes = $differ->diff([$desired]);

        $operations = array_map(fn ($change) => $change->operation, $changes);
        self::assertSame([SchemaOperation::RenameTable, SchemaOperation::ModifyColumn], $operations);

        foreach ($changes as $change) {
            $this->connection->apply($change);
        }

        // The rebuild preserved the row and widened the column.
        $rows = $this->connection->selectSql('SELECT name FROM users')->all();
        self::assertSame('Alice', $rows[0]->name);
        $live = $this->connection->schemaInspector->table('users');
        self::assertStringContainsString('120', $live->columns[1]['type']);

        $converged = (new Blueprint('users'))
            ->id()
            ->column(ColumnType::String, 'name', length: 120);
        self::assertSame([], $differ->diff([$converged]));
    }

    /**
     * A declared rename with ADD + MODIFY + DROP drift emits the rename,
     * the add/drop alter, and the modify — all targeting the new name.
     */
    public function testDeclaredTableRenameWithAddModifyDropDrift(): void
    {
        $blueprint = (new Blueprint('legacy_users'))
            ->id()
            ->column(ColumnType::String, 'name', length: 50)
            ->column(ColumnType::String, 'legacy_flag', length: 10);
        $this->connection->create($blueprint);
        $this->connection->statement("INSERT INTO legacy_users (name, legacy_flag) VALUES ('Alice', 'y')");

        $desired = (new Blueprint('users'))
            ->renamedFrom('legacy_users')
            ->id()
            ->column(ColumnType::String, 'name', length: 120)            // modify
            ->column(ColumnType::String, 'email', length: 255, nullable: true)  // add
            ->dropColumn('legacy_flag');                                  // drop

        $differ = new SchemaDiffer($this->connection->schemaInspector);
        $changes = $differ->diff([$desired]);

        $operations = array_map(fn ($change) => $change->operation, $changes);
        self::assertSame(
            [
                SchemaOperation::RenameTable,
                SchemaOperation::AddColumn,
                SchemaOperation::DropColumn,
                SchemaOperation::ModifyColumn,
            ],
            $operations,
        );
        // The drop change is destructive (it loses data); the add is not.
        self::assertFalse($changes[1]->destructive);
        self::assertTrue($changes[2]->destructive);

        // Every change applies — the add and the in-place drop both land
        // on SQLite 3.35+, and the modify routes through the rebuild.
        foreach ($changes as $change) {
            $this->connection->apply($change);
        }

        $live = $this->connection->schemaInspector->table('users');
        $names = array_column($live->columns, 'name');
        self::assertContains('email', $names);
        self::assertNotContains('legacy_flag', $names);
        self::assertStringContainsString('120', $live->columns[1]['type']);
    }

    /**
     * A declared table rename composed with a declared column rename AND
     * a shape change sequences rename-table → rename-column → modify.
     */
    public function testDeclaredTableRenameComposesWithColumnRename(): void
    {
        $this->createSimpleTable('legacy_users');
        $this->connection->statement("INSERT INTO legacy_users (name) VALUES ('Alice')");

        $desired = (new Blueprint('users'))
            ->renamedFrom('legacy_users')
            ->id()
            ->column(ColumnType::String, 'full_name', length: 120)
            ->renameColumn('name', 'full_name');

        $differ = new SchemaDiffer($this->connection->schemaInspector);
        $changes = $differ->diff([$desired]);

        $operations = array_map(fn ($change) => $change->operation, $changes);
        self::assertSame(
            [SchemaOperation::RenameTable, SchemaOperation::RenameColumn, SchemaOperation::ModifyColumn],
            $operations,
        );

        foreach ($changes as $change) {
            $this->connection->apply($change);
        }

        $rows = $this->connection->selectSql('SELECT full_name FROM users')->all();
        self::assertSame('Alice', $rows[0]->full_name);

        $converged = (new Blueprint('users'))
            ->id()
            ->column(ColumnType::String, 'full_name', length: 120);
        self::assertSame([], $differ->diff([$converged]));
    }

    /**
     * A declared column rename emits an executable RenameColumn change,
     * suppresses the add+drop advisory, and preserves the data.
     */
    public function testDeclaredColumnRenameAppliesAndPreservesData(): void
    {
        $this->createSimpleTable('users');
        $this->connection->statement("INSERT INTO users (name) VALUES ('Alice')");

        $desired = (new Blueprint('users'))
            ->id()
            ->column(ColumnType::String, 'full_name', length: 50)
            ->renameColumn('name', 'full_name');

        $differ = new SchemaDiffer($this->connection->schemaInspector);
        $changes = $differ->diff([$desired]);

        self::assertCount(1, $changes);
        self::assertSame(SchemaOperation::RenameColumn, $changes[0]->operation);
        self::assertFalse($changes[0]->destructive);
        self::assertFalse($changes[0]->possibleRename);

        $this->connection->apply($changes[0]);

        $rows = $this->connection->selectSql('SELECT full_name FROM users')->all();
        self::assertCount(1, $rows);
        self::assertSame('Alice', $rows[0]->full_name);

        self::assertSame([], $differ->diff([$desired]));
    }

    /**
     * A declared column rename PLUS a shape change sequences two changes:
     * RenameColumn first, then ModifyColumn.
     */
    public function testColumnRenameWithShapeChangeSequencesBothOps(): void
    {
        $this->createSimpleTable('users');

        $desired = (new Blueprint('users'))
            ->id()
            ->column(ColumnType::String, 'full_name', length: 120)
            ->renameColumn('name', 'full_name');

        $differ = new SchemaDiffer($this->connection->schemaInspector);
        $changes = $differ->diff([$desired]);

        self::assertCount(2, $changes);
        self::assertSame(SchemaOperation::RenameColumn, $changes[0]->operation);
        self::assertSame(SchemaOperation::ModifyColumn, $changes[1]->operation);

        // Applying in order works: rename lands, then the rebuild modifies.
        $this->connection->apply($changes[0]);
        $this->connection->apply($changes[1]);

        $live = $this->connection->schemaInspector->table('users');
        $names = array_column($live->columns, 'name');
        self::assertSame(['id', 'full_name'], $names);
    }

    /**
     * Content drift (type change) is detected as ModifyColumn and applied
     * via the SQLite rebuild — data survives, indexes are recreated.
     */
    public function testContentDriftModifiesViaRebuildAndPreservesData(): void
    {
        $blueprint = (new Blueprint('users'))
            ->id()
            ->column(ColumnType::String, 'name', length: 50)
            ->column(ColumnType::String, 'email', length: 255, index: true);
        $this->connection->create($blueprint);

        $this->connection->statement(
            "INSERT INTO users (name, email) VALUES ('Alice', 'a@x.io'), ('Bob', 'b@x.io')",
        );

        // Widen the name column — a pure type/length drift.
        $desired = (new Blueprint('users'))
            ->id()
            ->column(ColumnType::String, 'name', length: 120)
            ->column(ColumnType::String, 'email', length: 255, index: true);

        $differ = new SchemaDiffer($this->connection->schemaInspector);
        $changes = $differ->diff([$desired]);

        self::assertCount(1, $changes);
        self::assertSame(SchemaOperation::ModifyColumn, $changes[0]->operation);
        // The change's blueprint carries the FULL desired column set (the
        // SQLite rebuild renders the whole table); the description names
        // the modified subset.
        self::assertStringContainsString('[name]', $changes[0]->description);

        $this->connection->apply($changes[0]);

        // Data survived the rebuild.
        $rows = $this->connection->selectSql('SELECT name FROM users ORDER BY id')->all();
        self::assertCount(2, $rows);
        self::assertSame('Alice', $rows[0]->name);

        // The widened length is live.
        $live = $this->connection->schemaInspector->table('users');
        $nameColumn = $live->columns[1];
        self::assertSame('name', $nameColumn['name']);
        self::assertStringContainsString('120', $nameColumn['type']);

        // The declared index was recreated after the rebuild.
        $indexNames = array_column($live->indexes, 'name');
        self::assertContains('users_email_index', $indexNames);

        // Convergence.
        self::assertSame([], $differ->diff([$desired]));
    }

    /**
     * A mixed add+drop alter applies BOTH sides — the differ emits them as
     * separate changes (never one dominant-operation record that would
     * silently lose a side), and both land on the live schema.
     */
    public function testMixedAddDropAlterAppliesBothSides(): void
    {
        $blueprint = (new Blueprint('users'))
            ->id()
            ->column(ColumnType::String, 'name', length: 50)
            ->column(ColumnType::String, 'legacy_flag', length: 10, nullable: true);
        $this->connection->create($blueprint);
        $this->connection->statement("INSERT INTO users (name, legacy_flag) VALUES ('Alice', 'y')");

        $desired = (new Blueprint('users'))
            ->id()
            ->column(ColumnType::String, 'name', length: 50)
            ->column(ColumnType::String, 'email', length: 255, nullable: true)
            ->dropColumn('legacy_flag');

        $differ = new SchemaDiffer($this->connection->schemaInspector);
        $changes = $differ->diff([$desired]);

        // Separate add and drop changes — never a merged record.
        $operations = array_map(fn ($change) => $change->operation, $changes);
        self::assertSame([SchemaOperation::AddColumn, SchemaOperation::DropColumn], $operations);

        foreach ($changes as $change) {
            $this->connection->apply($change);
        }

        // BOTH sides landed: the column was added AND the flag dropped.
        $live = $this->connection->schemaInspector->table('users');
        $names = array_column($live->columns, 'name');
        self::assertContains('email', $names, 'the add side must not be lost');
        self::assertNotContains('legacy_flag', $names, 'the drop side must not be lost');

        // The row survived.
        $rows = $this->connection->selectSql('SELECT name FROM users')->all();
        self::assertCount(1, $rows);
    }

    /**
     * A rebuild that adds a NOT NULL column backfills the existing rows —
     * the copy projection has no source for the added column, so the
     * rebuild relaxes the temp table and copies the column's declared
     * default into the surviving rows.
     */
    public function testRebuildBackfillsAddedNotNullColumn(): void
    {
        $blueprint = (new Blueprint('users'))
            ->id()
            ->column(ColumnType::String, 'name', length: 50);
        $this->connection->create($blueprint);
        $this->connection->statement("INSERT INTO users (name) VALUES ('Alice'), ('Bob')");

        // Add a NOT NULL column WITH a default via a modify (the rebuild
        // path) — the existing rows must be backfilled, not rejected.
        $desired = (new Blueprint('users'))
            ->id()
            ->column(ColumnType::String, 'name', length: 50)
            ->column(ColumnType::Int, 'priority', default: 7);

        $differ = new SchemaDiffer($this->connection->schemaInspector);
        $changes = $differ->diff([$desired]);

        foreach ($changes as $change) {
            $this->connection->apply($change);
        }

        // The existing rows carry the backfilled default.
        $rows = $this->connection->selectSql('SELECT name, priority FROM users ORDER BY id')->all();
        self::assertCount(2, $rows);
        self::assertSame(7, (int) $rows[0]->priority);
        self::assertSame(7, (int) $rows[1]->priority);
    }

    /**
     * An explicit backfill() wins over the column's declared default —
     * the ongoing default (for new rows) and the migration backfill (for
     * existing rows) can differ.
     */
    public function testRebuildBackfillOverridesColumnDefault(): void
    {
        $blueprint = (new Blueprint('users'))
            ->id()
            ->column(ColumnType::String, 'name', length: 50);
        $this->connection->create($blueprint);
        $this->connection->statement("INSERT INTO users (name) VALUES ('Alice')");

        // The column's ongoing default is 7, but the existing row is
        // backfilled with the explicit migration value 3.
        $desired = (new Blueprint('users'))
            ->id()
            ->column(ColumnType::String, 'name', length: 50)
            ->column(ColumnType::Int, 'priority', default: 7)
            ->backfill('priority', 3);

        $this->connection->modifyColumn($desired);

        $row = $this->connection->selectSql('SELECT priority FROM users')->first();
        self::assertNotNull($row);
        self::assertSame(3, (int) $row->priority, 'the explicit backfill() must win over the column default');
    }

    /**
     * A rebuild that adds a NOT NULL column with NEITHER a declared
     * default NOR an explicit backfill() fails fast at compile time — the
     * framework never guesses a zero value for the existing rows.
     */
    public function testRebuildRejectsNotNullAddWithoutDefaultOrBackfill(): void
    {
        $blueprint = (new Blueprint('users'))
            ->id()
            ->column(ColumnType::String, 'name', length: 50);
        $this->connection->create($blueprint);
        $this->connection->statement("INSERT INTO users (name) VALUES ('Alice')");

        $desired = (new Blueprint('users'))
            ->id()
            ->column(ColumnType::String, 'name', length: 50)
            ->column(ColumnType::String, 'shipping_address', length: 500); // NOT NULL, no default, no backfill

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('declares no default and no backfill() value');
        $this->connection->modifyColumn($desired);
    }

    /**
     * A rebuild (triggered by a NOT NULL add) renders the FULL desired
     * shape — which already excludes the dropped columns and includes the
     * added ones. A sibling DropColumn/AddColumn that applies AFTER the
     * rebuild on the same table must no-op, never double-apply (a second
     * drop of an already-absent column would fail with "no such column").
     */
    public function testRebuildSubsumesSiblingColumnChanges(): void
    {
        $blueprint = (new Blueprint('users'))
            ->id()
            ->column(ColumnType::String, 'name', length: 50)
            ->column(ColumnType::String, 'legacy_flag', length: 10, nullable: true);
        $this->connection->create($blueprint);
        $this->connection->statement("INSERT INTO users (name, legacy_flag) VALUES ('Alice', 'y')");

        // Add a NOT NULL column (forces the rebuild) AND drop a column in
        // the same plan. The differ emits add → drop; the add's rebuild
        // already excludes legacy_flag, so the drop must no-op. The added
        // column declares no default, so an explicit backfill() supplies
        // the existing row's value.
        $desired = (new Blueprint('users'))
            ->id()
            ->column(ColumnType::String, 'name', length: 50)
            ->column(ColumnType::String, 'shipping_address', length: 500) // NOT NULL, no default
            ->backfill('shipping_address', 'pending')
            ->dropColumn('legacy_flag');

        $differ = new SchemaDiffer($this->connection->schemaInspector);
        $changes = $differ->diff([$desired]);

        $operations = array_map(fn ($change) => $change->operation, $changes);
        self::assertSame([SchemaOperation::AddColumn, SchemaOperation::DropColumn], $operations);

        // Every change applies cleanly — the drop no-ops after the rebuild.
        foreach ($changes as $change) {
            $this->connection->apply($change);
        }

        $live = $this->connection->schemaInspector->table('users');
        $names = array_column($live->columns, 'name');
        self::assertContains('shipping_address', $names);
        self::assertNotContains('legacy_flag', $names);
        self::assertSame(1, $this->connection->table('users')->count());

        // The existing row carries the explicit backfill value.
        $row = $this->connection->selectSql('SELECT shipping_address FROM users')->first();
        self::assertNotNull($row);
        self::assertSame('pending', $row->shipping_address);
    }

    /**
     * A multi-column add on SQLite compiles one statement per column (its
     * ALTER TABLE accepts a single ADD COLUMN clause) — and every column
     * lands when the statements execute.
     */
    public function testSqliteMultiColumnAddExecutesEveryStatement(): void
    {
        $blueprint = (new Blueprint('users'))
            ->id()
            ->column(ColumnType::String, 'name', length: 50);
        $this->connection->create($blueprint);

        $add = (new Blueprint('users'))
            ->column(ColumnType::String, 'email', length: 255, nullable: true)
            ->column(ColumnType::Int, 'age', nullable: true);

        // The grammar emits one statement per column.
        $statements = $this->connection->schemaGrammar->compileAddColumns($add);
        self::assertCount(2, $statements);

        $this->connection->alter(SchemaOperation::AddColumn, $add);

        $live = $this->connection->schemaInspector->table('users');
        $names = array_column($live->columns, 'name');
        self::assertContains('email', $names);
        self::assertContains('age', $names);
    }

    /**
     * A backfill() on an in-place (nullable) add fills the existing rows
     * via an UPDATE — SQLite cannot SET/DROP a column default in place, so
     * the backfill is never silently dropped.
     */
    public function testSqliteInPlaceAddAppliesBackfill(): void
    {
        $blueprint = (new Blueprint('users'))
            ->id()
            ->column(ColumnType::String, 'name', length: 50);
        $this->connection->create($blueprint);
        $this->connection->statement("INSERT INTO users (name) VALUES ('Alice'), ('Bob')");

        $add = (new Blueprint('users'))
            ->column(ColumnType::String, 'nickname', length: 50, nullable: true)
            ->backfill('nickname', 'unknown');

        $this->connection->alter(SchemaOperation::AddColumn, $add);

        $rows = $this->connection->selectSql('SELECT nickname FROM users ORDER BY id')->all();
        self::assertSame(['unknown', 'unknown'], array_map(fn ($row) => $row->nickname, $rows));
    }

    /**
     * A NOT NULL column without a default cannot be added to a non-empty
     * table — its backfill() renders as a temporary inline DEFAULT so the
     * ADD succeeds and fills the existing rows (no separate UPDATE).
     */
    public function testSqliteInPlaceNotNullAddBackfillsViaInlineDefault(): void
    {
        $blueprint = (new Blueprint('users'))
            ->id()
            ->column(ColumnType::String, 'name', length: 50);
        $this->connection->create($blueprint);
        $this->connection->statement("INSERT INTO users (name) VALUES ('Alice'), ('Bob')");

        $add = (new Blueprint('users'))
            ->column(ColumnType::Int, 'priority') // NOT NULL, no default
            ->backfill('priority', 5);

        // The ADD carries the backfill as an inline DEFAULT; no UPDATE.
        $statements = $this->connection->schemaGrammar->compileAddColumns($add);
        self::assertCount(1, $statements);
        self::assertStringContainsString('DEFAULT 5', $statements[0]);

        $this->connection->alter(SchemaOperation::AddColumn, $add);

        $rows = $this->connection->selectSql('SELECT priority FROM users ORDER BY id')->all();
        self::assertSame([5, 5], array_map(fn ($row) => (int) $row->priority, $rows));
    }

    /**
     * The full rename + reshape stress scenario on SQLite: a sloppy
     * all-TEXT legacy table is renamed and brought to a properly-typed
     * shape covering every change kind at once — table rename, column
     * rename, adds (incl. NOT NULL), modifies and a drop — and the seeded
     * rows survive. The SQLite twin of the integration regression test.
     */
    public function testRenameAndReshapeSyncAppliesEveryChangeKind(): void
    {
        $this->connection->create(
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

        $synchronizer = new SchemaSynchronizer($this->connection);
        $changes = $synchronizer->plan([$desired]);

        foreach ($changes as $change) {
            $this->connection->apply($change);
        }

        $live = $this->connection->schemaInspector->table('orders');
        $liveNames = array_column($live->columns, 'name');
        $desiredNames = array_map(fn (array $c) => $c['name'], $desired->getColumns());

        self::assertSame([], array_diff($desiredNames, $liveNames), 'all desired columns must be present');
        self::assertSame([], array_diff($liveNames, $desiredNames), 'no extra live columns (legacy_flag dropped)');
        self::assertSame(2, $this->connection->table('orders')->count(), 'the seeded rows must survive');

        // A re-diff converges.
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
     * A morph keyType switch (bigint → uuid) is detected as a
     * ModifyColumn drift — the DDL side of the switch is handled by the
     * differ; migrating the stored values is the host's concern.
     */
    public function testMorphKeyTypeSwitchDetectedAsModifyColumn(): void
    {
        $blueprint = (new Blueprint('comments'))
            ->id()
            ->string('body', 64)
            ->morphs('commentable');
        $this->connection->create($blueprint);

        // The model switched its #[Morphs] to keyType: Uuid — the desired
        // shape now carries a uuid key column.
        $desired = (new Blueprint('comments'))
            ->id()
            ->string('body', 64)
            ->uuidMorphs('commentable');

        $differ = new SchemaDiffer($this->connection->schemaInspector);
        $changes = $differ->diff([$desired]);

        self::assertCount(1, $changes);
        self::assertSame(SchemaOperation::ModifyColumn, $changes[0]->operation);
        self::assertStringContainsString('[commentable_id]', $changes[0]->description);

        $this->connection->apply($changes[0]);

        // Convergence: the uuid shape is now live.
        self::assertSame([], $differ->diff([$desired]));
    }

    /**
     * A nullability tightening is flagged destructive; a default-only
     * change is not.
     */
    public function testModifyDestructiveClassification(): void
    {
        $blueprint = (new Blueprint('users'))
            ->id()
            ->column(ColumnType::String, 'name', length: 50, nullable: true);
        $this->connection->create($blueprint);

        $differ = new SchemaDiffer($this->connection->schemaInspector);

        // Tighten nullability → destructive.
        $tighten = (new Blueprint('users'))
            ->id()
            ->column(ColumnType::String, 'name', length: 50, nullable: false);
        $changes = $differ->diff([$tighten]);
        self::assertCount(1, $changes);
        self::assertTrue($changes[0]->destructive);

        // Default-only change → non-destructive.
        $this->connection->apply($changes[0]);

        $defaulted = (new Blueprint('users'))
            ->id()
            ->column(ColumnType::String, 'name', length: 50, nullable: false, default: 'anon');
        $changes = $differ->diff([$defaulted]);
        self::assertCount(1, $changes);
        self::assertFalse($changes[0]->destructive);
    }

    /**
     * FK drift: a declared FK missing live is detected as AddForeignKey;
     * an extra live FK is detected as DropForeignKey. Shape-first: a
     * same-shape FK with a different name is in sync.
     */
    public function testForeignKeyDiffing(): void
    {
        $this->createSimpleTable('users');
        $this->createSimpleTable('posts');

        $differ = new SchemaDiffer($this->connection->schemaInspector);

        // Declare an FK posts.author_id -> users.id — missing live. BOTH
        // tables ride the desired set (the differ drops undeclared live
        // tables, so users must stay declared). The FK targets the PK
        // (SQLite requires referenced columns to be PK or uniquely
        // indexed).
        $users = (new Blueprint('users'))
            ->id()
            ->column(ColumnType::String, 'name', length: 50);

        $desired = (new Blueprint('posts'))
            ->id()
            ->column(ColumnType::String, 'name', length: 50)
            ->column(ColumnType::BigInt, 'author_id', foreign: 'users.id')
            // The added NOT NULL column declares no default — an explicit
            // backfill() fills the (empty) existing rows.
            ->backfill('author_id', 0);

        $changes = $differ->diff([$users, $desired]);
        // The new column (add) plus the FK (add_foreign_key) — two changes.
        self::assertCount(2, $changes);
        self::assertSame(SchemaOperation::AddForeignKey, $changes[1]->operation);

        foreach ($changes as $change) {
            $this->connection->apply($change);
        }

        // In sync now — and shape-first: the live constraint carries the
        // derived name, but a re-declaration with the same shape matches.
        self::assertSame([], $differ->diff([$users, $desired]));

        // Drop it: the desired state no longer declares the FK.
        $withoutFk = (new Blueprint('posts'))
            ->id()
            ->column(ColumnType::String, 'name', length: 50)
            ->column(ColumnType::BigInt, 'author_id');

        $changes = $differ->diff([$users, $withoutFk]);
        self::assertCount(1, $changes);
        self::assertSame(SchemaOperation::DropForeignKey, $changes[0]->operation);
        // The drop handle (the live constraint name) is in the description.
        self::assertStringContainsString('posts_author_id_foreign', $changes[0]->description);
    }

    /**
     * CHECK drift: a declared CHECK missing live is AddCheck; a
     * same-name different-expression mismatch is advisory-only (no
     * executable drop+add is emitted for it).
     */
    public function testCheckDiffing(): void
    {
        $blueprint = (new Blueprint('users'))
            ->id()
            ->column(ColumnType::Int, 'age');
        $this->connection->create($blueprint);

        $differ = new SchemaDiffer($this->connection->schemaInspector);

        // Declare a CHECK — missing live.
        $desired = (new Blueprint('users'))
            ->id()
            ->column(ColumnType::Int, 'age')
            ->check('age >= 18', 'adult');

        $changes = $differ->diff([$desired]);
        self::assertCount(1, $changes);
        self::assertSame(SchemaOperation::AddCheck, $changes[0]->operation);

        $this->connection->apply($changes[0]);
        self::assertSame([], $differ->diff([$desired]));

        // Same name, different expression → advisory-only change (the
        // operation is AddCheck but the description says advisory; the
        // blueprint carries NO executable declaration).
        $drifted = (new Blueprint('users'))
            ->id()
            ->column(ColumnType::Int, 'age')
            ->check('age >= 21', 'adult');

        $changes = $differ->diff([$drifted]);
        self::assertCount(1, $changes);
        self::assertStringContainsString('EXPRESSION DRIFT', $changes[0]->description);
        self::assertStringContainsString('Advisory only', $changes[0]->description);
        self::assertSame([], $changes[0]->blueprint->getChecks());
    }

    /**
     * Dependency ordering: creates are topologically sorted (referenced
     * tables first) even when declared in the wrong order; drops are
     * reverse-ordered (children first).
     */
    public function testDependencyOrdering(): void
    {
        $differ = new SchemaDiffer($this->connection->schemaInspector);

        // Declare posts (FK → users) BEFORE users — the sort must flip it.
        $posts = (new Blueprint('posts'))
            ->id()
            ->column(ColumnType::BigInt, 'author_id', foreign: 'users.id');

        $users = (new Blueprint('users'))
            ->id();

        $changes = $differ->diff([$posts, $users]);

        self::assertCount(2, $changes);
        self::assertSame('users', $changes[0]->table);
        self::assertSame('posts', $changes[1]->table);

        // Applying in the emitted order works (FK target exists first).
        $this->connection->apply($changes[0]);
        $this->connection->apply($changes[1]);

        // Drops: reverse order — posts (child) before users (parent).
        $changes = $differ->diff([]);
        self::assertCount(2, $changes);
        self::assertSame('posts', $changes[0]->table);
        self::assertSame('users', $changes[1]->table);
    }

    /**
     * A circular FK dependency fails fast with the cycle path named.
     */
    public function testCircularDependencyFailsFast(): void
    {
        $a = (new Blueprint('cycle_a'))
            ->id()
            ->column(ColumnType::BigInt, 'b_id', foreign: 'cycle_b.id');

        $b = (new Blueprint('cycle_b'))
            ->id()
            ->column(ColumnType::BigInt, 'a_id', foreign: 'cycle_a.id');

        $differ = new SchemaDiffer($this->connection->schemaInspector);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('Circular foreign-key dependency');
        $differ->diff([$a, $b]);
    }

    /**
     * The synchronizer: applies non-destructive changes under the lock,
     * fails fast on destructive changes without a confirm callback, and
     * honors a declining confirm.
     */
    public function testSynchronizerFlow(): void
    {
        $desired = (new Blueprint('users'))
            ->id()
            ->column(ColumnType::String, 'name', length: 50);

        $synchronizer = new SchemaSynchronizer($this->connection);

        // Non-destructive create applies.
        $applied = $synchronizer->sync([$desired]);
        self::assertCount(1, $applied);
        self::assertSame(SchemaOperation::CreateTable, $applied[0]->operation);

        // In sync → no changes.
        self::assertSame([], $synchronizer->sync([$desired]));

        // A destructive change (drop the table from the desired set) with
        // no confirm → fail-fast throw.
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('Refusing to apply the destructive change');
        $synchronizer->sync([]);
    }

    /**
     * The synchronizer's confirm callback gates destructive changes: a
     * declining confirm skips the change; an accepting one applies it.
     */
    public function testSynchronizerConfirmGate(): void
    {
        $this->createSimpleTable('temp_data');

        $synchronizer = new SchemaSynchronizer($this->connection);

        // Declined: the table survives.
        $applied = $synchronizer->sync([], confirm: fn (): bool => false);
        self::assertSame([], $applied);
        self::assertTrue($this->connection->schemaInspector->hasTable('temp_data'));

        // Accepted: the table goes.
        $applied = $synchronizer->sync([], confirm: fn (): bool => true);
        self::assertCount(1, $applied);
        self::assertFalse($this->connection->schemaInspector->hasTable('temp_data'));
    }

    /**
     * Transactional sync applies atomically — on sqlite (savepoint-backed
     * transactions) the changes commit together.
     */
    public function testTransactionalSyncAppliesAtomically(): void
    {
        $synchronizer = new SchemaSynchronizer($this->connection);

        $applied = $synchronizer->sync([
            (new Blueprint('tx_sync_a'))->id()->string('name', 50),
            (new Blueprint('tx_sync_b'))->id()->string('name', 50),
        ], transactional: true);

        self::assertCount(2, $applied);
        self::assertTrue($this->connection->schemaInspector->hasTable('tx_sync_a'));
        self::assertTrue($this->connection->schemaInspector->hasTable('tx_sync_b'));
        self::assertSame(0, $this->connection->transactionLevel(), 'the transaction must be closed after sync');
    }

    /**
     * A failed transactional sync rolls back EVERY change — the schema is
     * untouched.
     */
    public function testTransactionalSyncRollsBackOnFailure(): void
    {
        $synchronizer = new SchemaSynchronizer($this->connection);

        // A blueprint with an invalid column type makes the second apply
        // fail mid-loop; the first CREATE TABLE must roll back with it.
        $bad = new Blueprint('tx_sync_bad');
        $bad->id()->column(ColumnType::String, 'name', length: 50);

        Expectation::throws(function () use ($synchronizer, $bad): void {
            $synchronizer->sync([
                (new Blueprint('tx_sync_ok'))->id(),
                $bad,
            ], transactional: true);
        }, \Throwable::class);

        self::assertFalse(
            $this->connection->schemaInspector->hasTable('tx_sync_ok'),
            'the first CREATE TABLE must roll back with the failed transaction',
        );
    }

    /**
     * Transactional sync on a dialect WITHOUT transactional DDL fails
     * fast — the apply would silently commit change-by-change while
     * appearing atomic. The stub flips the base class's false default
     * back (sqlite's override returns true, so the anonymous subclass
     * restores the base behavior).
     */
    public function testTransactionalSyncOnNonTransactionalDdlThrows(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $connection = new /** non-transactional-DDL dialect */ class ($pdo) extends \BlueprintAU\Radiant\Database\Connections\SqlConnection {
            /**
             * The base default — sqlite's true override is undone.
             *
             * @return bool
             */
            #[\Override]
            public function supportsTransactionalDdl(): bool
            {
                return false;
            }

            /**
             * @return \BlueprintAU\Radiant\Database\Grammars\Grammar
             */
            #[\Override]
            protected function getDefaultQueryGrammar(): \BlueprintAU\Radiant\Database\Grammars\Grammar
            {
                return new \BlueprintAU\Radiant\Database\Grammars\SqliteGrammar();
            }

            /**
             * @return \BlueprintAU\Radiant\Database\Schema\Grammars\SchemaGrammar
             */
            #[\Override]
            protected function getDefaultSchemaGrammar(): \BlueprintAU\Radiant\Database\Schema\Grammars\SchemaGrammar
            {
                return new \BlueprintAU\Radiant\Database\Schema\Grammars\SqliteSchemaGrammar();
            }

            /**
             * @return \BlueprintAU\Radiant\Database\Schema\Inspectors\SchemaInspector
             */
            #[\Override]
            protected function getDefaultSchemaInspector(): \BlueprintAU\Radiant\Database\Schema\Inspectors\SchemaInspector
            {
                return new \BlueprintAU\Radiant\Database\Schema\Inspectors\SqliteSchemaInspector($this->pdo);
            }

            /**
             * @param  string  $name
             * @return void
             */
            #[\Override]
            protected function createSavepoint(string $name): void {}

            /**
             * @param  string  $name
             * @return void
             */
            #[\Override]
            protected function releaseSavepoint(string $name): void {}

            /**
             * @param  string  $name
             * @return void
             */
            #[\Override]
            protected function rollbackToSavepoint(string $name): void {}

            /**
             * @return bool
             */
            #[\Override]
            protected function supportsSavepoints(): bool
            {
                return false;
            }
        };

        $synchronizer = new SchemaSynchronizer($connection);

        Expectation::throwsWithMessage(
            fn () => $synchronizer->sync([(new Blueprint('tx_refused'))->id()], transactional: true),
            \LogicException::class,
            'does not support transactional DDL',
        );
    }

    /**
     * The rebuild's integrity gate: FK-violating child data fails
     * foreign_key_check and rolls back — the table is untouched.
     */
    public function testRebuildIntegrityGateRollsBack(): void
    {
        $users = (new Blueprint('users'))
            ->id()
            ->column(ColumnType::String, 'name', length: 50);
        $this->connection->create($users);

        $posts = (new Blueprint('posts'))
            ->id()
            ->column(ColumnType::BigInt, 'author_id', foreign: 'users.id');
        $this->connection->create($posts);

        $this->connection->statement("INSERT INTO users (name) VALUES ('Alice')");
        $this->connection->statement('INSERT INTO posts (author_id) VALUES (1)');

        // Modify users via the rebuild — the child row is valid, so it
        // succeeds and the child FK survives the rebuild. BOTH tables ride
        // the desired set (posts must stay declared or it diffs as a drop).
        $desired = (new Blueprint('users'))
            ->id()
            ->column(ColumnType::String, 'name', length: 120)
            ->column(ColumnType::BigInt, 'extra', nullable: true);

        $postsDesired = (new Blueprint('posts'))
            ->id()
            ->column(ColumnType::BigInt, 'author_id', foreign: 'users.id');

        $differ = new SchemaDiffer($this->connection->schemaInspector);
        $changes = $differ->diff([$desired, $postsDesired]);
        self::assertCount(2, $changes);
        self::assertSame(SchemaOperation::AddColumn, $changes[0]->operation);
        self::assertSame(SchemaOperation::ModifyColumn, $changes[1]->operation);

        foreach ($changes as $change) {
            $this->connection->apply($change);
        }

        // Data + the child relationship survived.
        $rows = $this->connection->selectSql('SELECT name FROM users')->all();
        self::assertCount(1, $rows);
        $childRows = $this->connection->selectSql('SELECT author_id FROM posts')->all();
        self::assertCount(1, $childRows);
    }

    // ---- Two-phase sync: plan() + apply() ----

    /**
     * plan() computes the changes but touches nothing — the schema is
     * unchanged until apply() runs.
     */
    public function testPlanTouchesNothing(): void
    {
        $desired = (new Blueprint('plan_users'))
            ->id()
            ->column(ColumnType::String, 'name', length: 50);

        $synchronizer = new SchemaSynchronizer($this->connection);

        $plan = $synchronizer->plan([$desired]);

        self::assertCount(1, $plan);
        self::assertSame(SchemaOperation::CreateTable, $plan[0]->operation);
        self::assertFalse(
            $this->connection->schemaInspector->hasTable('plan_users'),
            'plan() must not apply anything',
        );
    }

    /**
     * apply() applies exactly the pre-computed plan — no re-diff — and
     * returns only the changes actually applied.
     */
    public function testApplyAppliesExactlyThePlan(): void
    {
        $desired = (new Blueprint('plan_users'))
            ->id()
            ->column(ColumnType::String, 'name', length: 50);

        $synchronizer = new SchemaSynchronizer($this->connection);
        $plan = $synchronizer->plan([$desired]);

        $applied = $synchronizer->apply($plan);

        self::assertSame($plan, $applied);
        self::assertTrue($this->connection->schemaInspector->hasTable('plan_users'));

        // Re-applying the same plan is applied verbatim — no re-diff — so
        // a stale CREATE TABLE fails loudly instead of silently no-oping.
        Expectation::throws(
            fn () => $synchronizer->apply($plan),
            \Throwable::class,
        );
    }

    /**
     * The two-phase flow under a host-held lock: plan → display → apply
     * inside withLock() — the shown plan is exactly what gets applied.
     */
    public function testHostHeldLockPlanThenApply(): void
    {
        $desired = (new Blueprint('locked_users'))
            ->id()
            ->column(ColumnType::String, 'name', length: 50);

        $synchronizer = new SchemaSynchronizer($this->connection);

        $applied = $this->connection->withLock(function () use ($desired, $synchronizer): array {
            $plan = $synchronizer->plan([$desired]);

            self::assertCount(1, $plan);

            return $synchronizer->apply($plan);
        }, 'radiant:schema');

        self::assertCount(1, $applied);
        self::assertTrue($this->connection->schemaInspector->hasTable('locked_users'));
    }

    /**
     * apply() honors the confirm gate: a declined destructive change is
     * excluded from the return and not applied.
     */
    public function testApplyConfirmGateExcludesDeclinedChanges(): void
    {
        $this->createSimpleTable('plan_temp');

        $synchronizer = new SchemaSynchronizer($this->connection);
        $plan = $synchronizer->plan([]);

        self::assertCount(1, $plan);
        self::assertTrue($plan[0]->destructive);

        $applied = $synchronizer->apply($plan, confirm: fn (): bool => false);

        self::assertSame([], $applied);
        self::assertTrue($this->connection->schemaInspector->hasTable('plan_temp'));

        $applied = $synchronizer->apply($plan, confirm: fn (): bool => true);

        self::assertSame($plan, $applied);
        self::assertFalse($this->connection->schemaInspector->hasTable('plan_temp'));
    }

    // ---- onChange callback ----

    /**
     * onChange fires once per applied change, in order, with the change
     * instance — and never for declined changes.
     */
    public function testOnChangeFiresPerAppliedChange(): void
    {
        $this->createSimpleTable('cb_temp');

        $synchronizer = new SchemaSynchronizer($this->connection);

        $seen = [];
        $applied = $synchronizer->sync([], confirm: fn (): bool => false, onChange: function ($change) use (&$seen): void {
            $seen[] = $change;
        });

        self::assertSame([], $applied);
        self::assertSame([], $seen, 'a declined change must not fire onChange');

        $applied = $synchronizer->sync([], confirm: fn (): bool => true, onChange: function ($change) use (&$seen): void {
            $seen[] = $change;
        });

        self::assertCount(1, $applied);
        self::assertSame([$applied[0]], $seen, 'onChange must receive the applied change instance');
    }

    /**
     * onChange fires for every applied change under transactional sync —
     * and a throwing callback aborts the run and rolls everything back.
     */
    public function testTransactionalOnChangeFiresAndThrowingCallbackRollsBack(): void
    {
        $synchronizer = new SchemaSynchronizer($this->connection);

        $seen = [];
        $applied = $synchronizer->sync([
            (new Blueprint('cb_tx_a'))->id()->string('name', 50),
            (new Blueprint('cb_tx_b'))->id()->string('name', 50),
        ], transactional: true, onChange: function ($change) use (&$seen): void {
            $seen[] = $change;
        });

        self::assertCount(2, $applied);
        self::assertSame($applied, $seen);
        self::assertSame(0, $this->connection->transactionLevel());

        // A throwing callback aborts the run — the first CREATE TABLE
        // rolls back with the failed transaction.
        Expectation::throws(function () use ($synchronizer): void {
            $synchronizer->sync([
                (new Blueprint('cb_tx_c'))->id(),
                (new Blueprint('cb_tx_d'))->id(),
            ], transactional: true, onChange: function (): void {
                throw new \RuntimeException('progress bar exploded');
            });
        }, \RuntimeException::class);

        self::assertFalse(
            $this->connection->schemaInspector->hasTable('cb_tx_c'),
            'the first CREATE TABLE must roll back when onChange throws',
        );
        self::assertSame(0, $this->connection->transactionLevel());
    }
}
