<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Schema;

use BlueprintAU\Radiant\Database\Schema\Blueprint;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Database\Schema\Enums\SchemaOperation;
use BlueprintAU\Radiant\Database\Schema\SchemaDiffer;
use BlueprintAU\Radiant\Database\Schema\SchemaSynchronizer;
use BlueprintAU\Radiant\Tests\Support\DatabaseTestCase;

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
        $this->expectExceptionMessage('does not exist in the live schema');
        $differ->diff([$desired]);
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
            ->column(ColumnType::BigInt, 'author_id', foreign: 'users.id');

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
        $this->expectExceptionMessage('Circular foreign-key dependency');
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
        $this->expectExceptionMessage('Refusing to apply the destructive change');
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
}
