<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Schema;

use BlueprintAU\Radiant\Database\Schema\Blueprint;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Database\Schema\SchemaSynchronizer;
use BlueprintAU\Radiant\Tests\Support\DatabaseTestCase;
use BlueprintAU\Radiant\Tests\Support\Expectation;

/**
 * The SQLite rebuild's transactional boundary inside the synchronizer
 * flow: an FK-involved rebuild must succeed under sync() and a
 * transactional apply (the wrapper degrades), child rows survive, the
 * FK gate stays intact, and the PRAGMA is restored afterwards.
 */
final class SynchronizerRebuildTransactionTest extends DatabaseTestCase
{
    /**
     * The synchronizer over the per-test connection.
     *
     * @var SchemaSynchronizer
     */
    private SchemaSynchronizer $synchronizer;

    /**
     * Wire the synchronizer.
     */
    protected function setUpDatabase(): void
    {
        $this->synchronizer = new SchemaSynchronizer($this->connection);
    }

    /**
     * The connector forces foreign_keys ON — the fixture's precondition.
     */
    public function testForeignKeysAreEnforcedByDefault(): void
    {
        $pragma = $this->connection->selectSql('PRAGMA foreign_keys');
        $row = $pragma->first();

        self::assertNotNull($row);
        self::assertSame(1, $row->{'foreign_keys'});
    }

    /**
     * sync() with an FK-involving ModifyColumn on a PARENT-REFERENCED
     * table (a child references it) succeeds: child rows survive, the
     * foreign_key_check gate is empty, and PRAGMA foreign_keys reads ON
     * afterwards.
     */
    public function testSyncAppliesParentReferencedModifyColumn(): void
    {
        $users = (new Blueprint('users'))
            ->id()
            ->column(ColumnType::String, 'name', length: 50);
        $this->connection->create($users);

        $posts = (new Blueprint('posts'))
            ->id()
            ->column(ColumnType::BigInt, 'user_id', foreign: 'users.id', onDelete: 'cascade');
        $this->connection->create($posts);

        $this->connection->statement("INSERT INTO users (name) VALUES ('Alice')");
        $this->connection->statement('INSERT INTO posts (user_id) VALUES (1)');
        $this->connection->statement('INSERT INTO posts (user_id) VALUES (1)');

        // Drift on users — the parent. posts.user_id carries ON DELETE
        // CASCADE, so a drop under enforcement would lose the child rows.
        $desired = (new Blueprint('users'))
            ->id()
            ->column(ColumnType::String, 'name', length: 120);
        $postsDesired = (new Blueprint('posts'))
            ->id()
            ->column(ColumnType::BigInt, 'user_id', foreign: 'users.id', onDelete: 'cascade');

        $applied = $this->synchronizer->sync([$desired, $postsDesired]);

        $modify = array_values(array_filter(
            $applied,
            fn ($change) => $change->operation === \BlueprintAU\Radiant\Database\Schema\Enums\SchemaOperation::ModifyColumn,
        ));
        self::assertCount(1, $modify, 'the plan must carry the parent ModifyColumn');

        // Child rows survived the drop-under-rebuild.
        $childRows = $this->connection->selectSql('SELECT user_id FROM posts')->all();
        self::assertCount(2, $childRows);

        // The FK gate ran clean.
        $violations = $this->connection->selectSql('PRAGMA foreign_key_check(posts)')->all();
        self::assertSame([], $violations);

        // The PRAGMA was restored.
        $pragma = $this->connection->selectSql('PRAGMA foreign_keys');
        $row = $pragma->first();
        self::assertNotNull($row);
        self::assertSame(1, $row->{'foreign_keys'});
    }

    /**
     * The same flow via plan() + apply(transactional: true) — the
     * wrapper degrades and the rebuild's own transaction carries it.
     */
    public function testTransactionalApplyAppliesParentReferencedModifyColumn(): void
    {
        $users = (new Blueprint('users'))
            ->id()
            ->column(ColumnType::String, 'name', length: 50);
        $this->connection->create($users);

        $posts = (new Blueprint('posts'))
            ->id()
            ->column(ColumnType::BigInt, 'user_id', foreign: 'users.id', onDelete: 'cascade');
        $this->connection->create($posts);

        $this->connection->statement("INSERT INTO users (name) VALUES ('Alice')");
        $this->connection->statement('INSERT INTO posts (user_id) VALUES (1)');

        $desired = (new Blueprint('users'))
            ->id()
            ->column(ColumnType::String, 'name', length: 120);
        $postsDesired = (new Blueprint('posts'))
            ->id()
            ->column(ColumnType::BigInt, 'user_id', foreign: 'users.id', onDelete: 'cascade');

        // The taught pattern: plan under the lock, apply AFTER releasing it
        // — the rebuild needs a transaction-free connection, and the lock
        // transaction is one.
        $plan = $this->connection->withLock(
            fn (): array => $this->synchronizer->plan([$desired, $postsDesired]),
            'radiant:schema',
        );

        $applied = $this->synchronizer->apply($plan, transactional: true);

        $modify = array_values(array_filter(
            $applied,
            fn ($change) => $change->operation === \BlueprintAU\Radiant\Database\Schema\Enums\SchemaOperation::ModifyColumn,
        ));
        self::assertCount(1, $modify);

        $childRows = $this->connection->selectSql('SELECT user_id FROM posts')->all();
        self::assertCount(1, $childRows);

        $violations = $this->connection->selectSql('PRAGMA foreign_key_check(posts)')->all();
        self::assertSame([], $violations);

        $pragma = $this->connection->selectSql('PRAGMA foreign_keys');
        $row = $pragma->first();
        self::assertNotNull($row);
        self::assertSame(1, $row->{'foreign_keys'});
    }

    /**
     * The self-declaring case: the rebuilt table carries its own FK —
     * the toggle still fires, the rebuild still applies, and the
     * constraint survives the rebuild.
     */
    public function testSyncAppliesSelfDeclaringForeignKeyModifyColumn(): void
    {
        $teams = (new Blueprint('teams'))
            ->id()
            ->column(ColumnType::String, 'name', length: 50);
        $this->connection->create($teams);

        $users = (new Blueprint('users'))
            ->id()
            ->column(ColumnType::BigInt, 'team_id', nullable: true)
            ->column(ColumnType::String, 'name', length: 50)
            ->foreignKey(['team_id'], 'teams', ['id'], onDelete: 'set null');
        $this->connection->create($users);

        $this->connection->statement("INSERT INTO teams (name) VALUES ('Core')");
        $this->connection->statement("INSERT INTO users (team_id, name) VALUES (1, 'Alice')");

        // Drift: widen name. The table itself declares the FK.
        $desired = (new Blueprint('users'))
            ->id()
            ->column(ColumnType::BigInt, 'team_id', nullable: true)
            ->column(ColumnType::String, 'name', length: 120)
            ->foreignKey(['team_id'], 'teams', ['id'], onDelete: 'set null');
        $teamsDesired = (new Blueprint('teams'))
            ->id()
            ->column(ColumnType::String, 'name', length: 50);

        $applied = $this->synchronizer->sync([$teamsDesired, $desired]);

        $modify = array_values(array_filter(
            $applied,
            fn ($change) => $change->operation === \BlueprintAU\Radiant\Database\Schema\Enums\SchemaOperation::ModifyColumn,
        ));
        self::assertCount(1, $modify);

        // The data survived; the FK is still declared on the live table.
        $rows = $this->connection->selectSql('SELECT team_id, name FROM users')->all();
        self::assertCount(1, $rows);
        self::assertSame('Alice', $rows[0]->name, 'the row data must survive the rebuild');
        self::assertSame(1, $rows[0]->team_id);

        $live = $this->connection->schemaInspector->table('users');
        $nameColumn = array_values(array_filter(
            $live->columns,
            fn (array $column) => $column['name'] === 'name',
        ))[0];
        self::assertSame('varchar(120)', $nameColumn['type'], 'the widened shape must have applied');

        $constraints = $this->connection->selectSql('PRAGMA foreign_key_list(users)')->all();
        self::assertNotSame([], $constraints, 'the foreign key must survive the rebuild');

        $violations = $this->connection->selectSql('PRAGMA foreign_key_check(users)')->all();
        self::assertSame([], $violations);

        $pragma = $this->connection->selectSql('PRAGMA foreign_keys');
        $row = $pragma->first();
        self::assertNotNull($row);
        self::assertSame(1, $row->{'foreign_keys'});
    }

    /**
     * A rebuild that would violate FKs rolls back and restores
     * PRAGMA foreign_keys = ON — even through the degraded apply.
     */
    public function testViolatingRebuildRollsBackAndRestoresPragma(): void
    {
        $users = (new Blueprint('users'))
            ->id()
            ->column(ColumnType::String, 'name', length: 50);
        $this->connection->create($users);

        $posts = (new Blueprint('posts'))
            ->id()
            ->column(ColumnType::BigInt, 'user_id', foreign: 'users.id', onDelete: 'restrict');
        $this->connection->create($posts);

        $this->connection->statement("INSERT INTO users (name) VALUES ('Alice')");
        $this->connection->statement('INSERT INTO posts (user_id) VALUES (1)');

        // Plant an orphan (possible only while enforcement is off).
        $this->connection->statement('PRAGMA foreign_keys = OFF');
        $this->connection->statement('INSERT INTO posts (user_id) VALUES (99)');
        $this->connection->statement('PRAGMA foreign_keys = ON');

        $desired = (new Blueprint('users'))
            ->id()
            ->column(ColumnType::String, 'name', length: 120);
        $postsDesired = (new Blueprint('posts'))
            ->id()
            ->column(ColumnType::BigInt, 'user_id', foreign: 'users.id', onDelete: 'restrict');

        Expectation::throws(
            fn () => $this->synchronizer->sync([$desired, $postsDesired]),
            \LogicException::class,
        );

        // The parent table is untouched.
        $rows = $this->connection->selectSql('SELECT name FROM users')->all();
        self::assertCount(1, $rows);
        self::assertSame('Alice', $rows[0]->name);

        $live = $this->connection->schemaInspector->table('users');
        $nameColumn = array_values(array_filter(
            $live->columns,
            fn (array $column) => $column['name'] === 'name',
        ))[0];
        self::assertSame('varchar(50)', $nameColumn['type'], 'the rebuild must have rolled back');

        // The PRAGMA was restored even through the degraded apply.
        $pragma = $this->connection->selectSql('PRAGMA foreign_keys');
        $row = $pragma->first();
        self::assertNotNull($row);
        self::assertSame(1, $row->{'foreign_keys'});
    }

    /**
     * A NOT NULL add (no default) with a backfill — the other
     * rebuild-routing change — applies through sync() as well.
     */
    public function testSyncAppliesNotNullAddThroughRebuild(): void
    {
        $users = (new Blueprint('users'))
            ->id()
            ->column(ColumnType::String, 'name', length: 50);
        $this->connection->create($users);

        $this->connection->statement("INSERT INTO users (name) VALUES ('Alice')");

        $desired = (new Blueprint('users'))
            ->id()
            ->column(ColumnType::String, 'name', length: 50)
            ->column(ColumnType::String, 'status', length: 20, nullable: false, default: 'active');

        $applied = $this->synchronizer->sync([$desired]);

        $add = array_values(array_filter(
            $applied,
            fn ($change) => $change->operation === \BlueprintAU\Radiant\Database\Schema\Enums\SchemaOperation::AddColumn,
        ));
        self::assertCount(1, $add);

        $rows = $this->connection->selectSql('SELECT name, status FROM users')->all();
        self::assertCount(1, $rows);
        self::assertSame('active', $rows[0]->status, 'the backfill must fill the existing row');

        $pragma = $this->connection->selectSql('PRAGMA foreign_keys');
        $row = $pragma->first();
        self::assertNotNull($row);
        self::assertSame(1, $row->{'foreign_keys'});
    }

    /**
     * A FK-involving ModifyColumn on a NON-FK table rebuilds without the
     * PRAGMA toggle — the transactional apply stays transactional there.
     */
    public function testNonForeignKeyRebuildStaysTransactional(): void
    {
        $this->connection->create(
            (new Blueprint('plain'))->id()->column(ColumnType::String, 'name', length: 50),
        );
        $this->connection->statement("INSERT INTO plain (name) VALUES ('Alice')");

        $desired = (new Blueprint('plain'))
            ->id()
            ->column(ColumnType::String, 'name', length: 120);

        $applied = $this->synchronizer->sync([$desired], transactional: true);

        self::assertCount(1, $applied);
        self::assertSame(0, $this->connection->transactionLevel(), 'the apply must close cleanly');

        $rows = $this->connection->selectSql('SELECT name FROM plain')->all();
        self::assertCount(1, $rows);
        self::assertSame('Alice', $rows[0]->name);
    }

    /**
     * The predicate: a ModifyColumn on a parent-referenced table needs a
     * standalone transaction; the same change on a non-FK table does not.
     */
    public function testChangeRequiresStandaloneTransactionPredicate(): void
    {
        $this->connection->create(
            (new Blueprint('plain'))->id()->column(ColumnType::String, 'name', length: 50),
        );

        $child = (new Blueprint('child'))
            ->id()
            ->column(ColumnType::BigInt, 'plain_id', foreign: 'plain.id');
        $this->connection->create($child);

        $plainDesired = (new Blueprint('plain'))
            ->id()
            ->column(ColumnType::String, 'name', length: 120);

        // plain is a PARENT (child references it) — the rebuild toggles.
        $parentChange = $this->synchronizer->plan([$plainDesired])[0];
        self::assertTrue(
            $this->connection->changeRequiresStandaloneTransaction($parentChange),
            'a parent-referenced rebuild needs the PRAGMA toggle outside any transaction',
        );

        // child declares its own FK — a NOT NULL add WITHOUT a default
        // routes through the rebuild there and toggles too; a nullable
        // add stays in-place, so both shapes are asserted.
        $childDesired = (new Blueprint('child'))
            ->id()
            ->column(ColumnType::BigInt, 'plain_id', foreign: 'plain.id')
            ->column(ColumnType::String, 'label', length: 20, nullable: false)
            ->backfill('label', 'unknown');
        $plan = $this->synchronizer->plan([$childDesired]);
        $childChange = array_values(array_filter(
            $plan,
            fn ($change) => $change->operation === \BlueprintAU\Radiant\Database\Schema\Enums\SchemaOperation::AddColumn,
        ))[0];
        self::assertTrue(
            $this->connection->changeRequiresStandaloneTransaction($childChange),
            'a NOT NULL add through the rebuild on an FK-declaring table toggles the PRAGMA',
        );

        // The nullable add on the same table stays in-place — no toggle.
        $childNullable = (new Blueprint('child'))
            ->id()
            ->column(ColumnType::BigInt, 'plain_id', foreign: 'plain.id')
            ->column(ColumnType::String, 'note', length: 30, nullable: true);
        $nullableChange = array_values(array_filter(
            $this->synchronizer->plan([$childNullable]),
            fn ($change) => $change->operation === \BlueprintAU\Radiant\Database\Schema\Enums\SchemaOperation::AddColumn,
        ))[0];
        self::assertFalse(
            $this->connection->changeRequiresStandaloneTransaction($nullableChange),
            'a nullable in-place add never needs a standalone transaction',
        );
    }
}
