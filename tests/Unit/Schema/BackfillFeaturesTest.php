<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Schema;

use BlueprintAU\Radiant\Database\Query\Expression;
use BlueprintAU\Radiant\Database\Schema\Blueprint;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Database\Schema\Enums\SchemaOperation;
use BlueprintAU\Radiant\Database\Schema\SchemaChange;
use BlueprintAU\Radiant\Database\Schema\SchemaDiffer;
use BlueprintAU\Radiant\Database\Schema\SchemaSynchronizer;
use BlueprintAU\Radiant\Tests\Support\DatabaseTestCase;

/**
 * Backfill ergonomics: Expression backfills on an add, backfills on a
 * nullability-tightening modify, and the differ's draft data-move
 * skeleton — all on live SQLite through plan() → apply().
 */
final class BackfillFeaturesTest extends DatabaseTestCase
{
    // ---- Fix 1: Expression backfill on an add ----

    /**
     * An Expression backfill on a nullable add compiles as a post-add
     * UPDATE that references another column — the entire reason to use
     * an Expression — and never lands in a DEFAULT clause.
     */
    public function testExpressionBackfillOnNullableAddFillsRowsRowWise(): void
    {
        $this->connection->create(
            (new Blueprint('bf_expr'))
                ->id()
                ->column(ColumnType::String, 'name', length: 64),
        );
        $this->connection->table('bf_expr')->insert([
            ['name' => 'widget'],
            ['name' => 'gadget'],
        ]);

        $desired = (new Blueprint('bf_expr'))
            ->id()
            ->column(ColumnType::String, 'name', length: 64)
            ->column(ColumnType::String, 'slug', length: 80, nullable: true)
            ->backfill('slug', new Expression("name || '-suffix'"));

        $synchronizer = new SchemaSynchronizer($this->connection);
        $synchronizer->apply($synchronizer->plan([$desired]));

        $rows = $this->connection->selectSql('SELECT slug FROM bf_expr ORDER BY id')->all();
        self::assertSame(['widget-suffix', 'gadget-suffix'], array_map(fn ($row) => $row->slug, $rows));

        // Converged.
        self::assertSame([], $synchronizer->plan([$desired]));
    }

    /**
     * An Expression backfill on a NOT NULL add routes through the
     * rebuild: the expression is inlined row-wise in the copy projection
     * and the column lands NOT NULL — never as a (non-constant, invalid)
     * DEFAULT.
     */
    public function testExpressionBackfillOnNotNullAddRebuildsRowWise(): void
    {
        $this->connection->create(
            (new Blueprint('bf_expr_notnull'))
                ->id()
                ->column(ColumnType::String, 'name', length: 64),
        );
        $this->connection->table('bf_expr_notnull')->insert([
            ['name' => 'widget'],
            ['name' => 'gadget'],
        ]);

        $desired = (new Blueprint('bf_expr_notnull'))
            ->id()
            ->column(ColumnType::String, 'name', length: 64)
            ->column(ColumnType::String, 'slug', length: 80)
            ->backfill('slug', new Expression("name || '-sfx'"));

        $synchronizer = new SchemaSynchronizer($this->connection);
        $synchronizer->apply($synchronizer->plan([$desired]));

        $rows = $this->connection->selectSql('SELECT slug FROM bf_expr_notnull ORDER BY id')->all();
        self::assertSame(['widget-sfx', 'gadget-sfx'], array_map(fn ($row) => $row->slug, $rows));

        $live = $this->connection->schemaInspector->table('bf_expr_notnull');
        $column = array_values(array_filter($live->columns, fn (array $c) => $c['name'] === 'slug'))[0];
        self::assertFalse($column['nullable']);

        self::assertSame([], $synchronizer->plan([$desired]));
    }

    // ---- Fix 2: backfill on a nullability-tightening modify ----

    /**
     * A nullable → NOT NULL tighten with a declared backfill fills the
     * violating NULL rows before the tighten — the rows are filled, not
     * rejected, and the change STAYS destructive (the gate is not
     * bypassed).
     */
    public function testBackfillOnTighteningModifyFillsViolatingRows(): void
    {
        $this->connection->create(
            (new Blueprint('bf_tighten'))
                ->id()
                ->column(ColumnType::String, 'email', length: 255)
                ->column(ColumnType::String, 'status', length: 32, nullable: true),
        );
        $this->connection->table('bf_tighten')->insert([
            ['email' => 'a@x.io', 'status' => null],
            ['email' => 'b@x.io', 'status' => 'active'],
        ]);

        $desired = (new Blueprint('bf_tighten'))
            ->id()
            ->column(ColumnType::String, 'email', length: 255)
            ->column(ColumnType::String, 'status', length: 32)
            ->backfill('status', 'unknown');

        $synchronizer = new SchemaSynchronizer($this->connection);
        $plan = $synchronizer->plan([$desired]);

        self::assertCount(1, $plan);
        self::assertSame(SchemaOperation::ModifyColumn, $plan[0]->operation);
        self::assertTrue($plan[0]->destructive, 'A tightening modify stays destructive — the backfill is an escape hatch, not a gate bypass.');
        self::assertStringContainsString('#[Backfill]', $plan[0]->description);

        $synchronizer->apply($plan, confirm: fn (SchemaChange $change) => true);

        $rows = $this->connection->selectSql('SELECT status FROM bf_tighten ORDER BY id')->all();
        self::assertSame(['unknown', 'active'], array_map(fn ($row) => $row->status, $rows));

        $live = $this->connection->schemaInspector->table('bf_tighten');
        $column = array_values(array_filter($live->columns, fn (array $c) => $c['name'] === 'status'))[0];
        self::assertFalse($column['nullable']);

        self::assertSame([], $synchronizer->plan([$desired]));
    }

    /**
     * An Expression backfill on a tightening modify is evaluated
     * row-wise — each row's fill derives from that row's data.
     */
    public function testExpressionBackfillOnTighteningModifyFillsRowWise(): void
    {
        $this->connection->create(
            (new Blueprint('bf_tighten_expr'))
                ->id()
                ->column(ColumnType::String, 'email', length: 255)
                ->column(ColumnType::String, 'handle', length: 80, nullable: true),
        );
        $this->connection->table('bf_tighten_expr')->insert([
            ['email' => 'a@x.io', 'handle' => null],
            ['email' => 'b@x.io', 'handle' => 'kept'],
        ]);

        $desired = (new Blueprint('bf_tighten_expr'))
            ->id()
            ->column(ColumnType::String, 'email', length: 255)
            ->column(ColumnType::String, 'handle', length: 80)
            ->backfill('handle', new Expression("email || '-h'"));

        $synchronizer = new SchemaSynchronizer($this->connection);
        $synchronizer->apply($synchronizer->plan([$desired]), confirm: fn (SchemaChange $change) => true);

        $rows = $this->connection->selectSql('SELECT handle FROM bf_tighten_expr ORDER BY id')->all();
        self::assertSame(['a@x.io-h', 'kept'], array_map(fn ($row) => $row->handle, $rows));

        self::assertSame([], $synchronizer->plan([$desired]));
    }

    // ---- Fix 3: draft data-move skeleton ----

    /**
     * A plan that adds some columns and drops others on one table
     * renders the draft data-move skeleton on the destructive drop
     * half — per dropped column one guarded UPDATE naming every added
     * column with EDIT placeholders, plus the verification query.
     */
    public function testAddDropPlanRendersDataMoveSkeleton(): void
    {
        $this->connection->create(
            (new Blueprint('bf_skeleton'))
                ->id()
                ->column(ColumnType::BigInt, 'post_id', nullable: true)
                ->column(ColumnType::String, 'title', length: 255),
        );

        $desired = (new Blueprint('bf_skeleton'))
            ->id()
            ->column(ColumnType::String, 'attachable_type', length: 255, nullable: true)
            ->column(ColumnType::BigInt, 'attachable_id', nullable: true)
            ->column(ColumnType::String, 'title', length: 255)
            ->dropColumn('post_id');

        $changes = (new SchemaDiffer($this->connection->schemaInspector))->diff([$desired]);

        $drop = array_values(array_filter(
            $changes,
            fn (SchemaChange $change) => $change->operation === SchemaOperation::DropColumn,
        ))[0];

        self::assertStringContainsString('Draft data-move skeleton', $drop->description);
        self::assertStringContainsString("UPDATE bf_skeleton SET attachable_type = '<EDIT>', attachable_id = '<EDIT>' WHERE post_id IS NOT NULL;", $drop->description);
        self::assertStringContainsString('Verify before dropping: SELECT COUNT(*) FROM bf_skeleton WHERE attachable_type IS NULL AND (post_id IS NOT NULL);  -- expect 0', $drop->description);
    }

    /**
     * A pure-add plan renders exactly as today — no skeleton, no
     * POSSIBLE-RENAME advisory.
     */
    public function testPureAddPlanRendersNoSkeleton(): void
    {
        $this->connection->create(
            (new Blueprint('bf_pure_add'))
                ->id()
                ->column(ColumnType::String, 'email', length: 255),
        );

        $desired = (new Blueprint('bf_pure_add'))
            ->id()
            ->column(ColumnType::String, 'email', length: 255)
            ->column(ColumnType::String, 'name', length: 64);

        $changes = (new SchemaDiffer($this->connection->schemaInspector))->diff([$desired]);

        foreach ($changes as $change) {
            self::assertStringNotContainsString('Draft data-move skeleton', $change->description);
            self::assertStringNotContainsString('POSSIBLE RENAME', $change->description);
        }
    }

    /**
     * A pure-drop plan renders exactly as today — no skeleton.
     */
    public function testPureDropPlanRendersNoSkeleton(): void
    {
        $this->connection->create(
            (new Blueprint('bf_pure_drop'))
                ->id()
                ->column(ColumnType::String, 'email', length: 255)
                ->column(ColumnType::String, 'legacy', length: 64),
        );

        $desired = (new Blueprint('bf_pure_drop'))
            ->id()
            ->column(ColumnType::String, 'email', length: 255)
            ->dropColumn('legacy');

        $changes = (new SchemaDiffer($this->connection->schemaInspector))->diff([$desired]);

        foreach ($changes as $change) {
            self::assertStringNotContainsString('Draft data-move skeleton', $change->description);
        }
    }

    // ---- Fix 4: DataStep ----

    /**
     * A hand-authored DataStep executes its statements in order — the
     * guarded morph-pair UPDATEs run after the ADD lands the columns and
     * before the DROP removes the sources.
     */
    public function testDataStepExecutesStatementsInOrder(): void
    {
        $this->connection->create(
            (new Blueprint('bf_datastep'))
                ->id()
                ->column(ColumnType::BigInt, 'post_id', nullable: true)
                ->column(ColumnType::String, 'title', length: 255),
        );
        $this->connection->table('bf_datastep')->insert([
            ['post_id' => 7, 'title' => 'first'],
            ['post_id' => null, 'title' => 'orphan'],
        ]);

        // Phase 1: add the morph pair.
        $add = (new Blueprint('bf_datastep'))
            ->column(ColumnType::String, 'attachable_type', length: 255, nullable: true)
            ->column(ColumnType::BigInt, 'attachable_id', nullable: true);
        $this->connection->alter(SchemaOperation::AddColumn, $add);

        // The data step: hand-authored guarded morph-pair fill.
        $step = new SchemaChange(
            'bf_datastep',
            SchemaOperation::DataStep,
            new Blueprint('bf_datastep'),
            true,
            'data step [morph-fill]: 2 statement(s)',
            statements: [
                "UPDATE bf_datastep SET attachable_type = 'post', attachable_id = post_id WHERE post_id IS NOT NULL",
                "UPDATE bf_datastep SET attachable_type = 'none', attachable_id = id WHERE post_id IS NULL",
            ],
        );

        $this->connection->apply($step);

        // Phase 2: drop the source.
        $this->connection->alter(SchemaOperation::DropColumn, (new Blueprint('bf_datastep'))->dropColumn('post_id'));

        $rows = $this->connection->selectSql('SELECT attachable_type, attachable_id FROM bf_datastep ORDER BY id')->all();
        self::assertSame(['post', 7], [$rows[0]->attachable_type, (int) $rows[0]->attachable_id]);
        self::assertSame(['none', 2], [$rows[1]->attachable_type, (int) $rows[1]->attachable_id]);
    }

    /**
     * A destructive DataStep (the default) requires confirmation — the
     * synchronizer's gate covers it with no new gate code.
     */
    public function testDataStepIsDestructiveByDefaultAndGated(): void
    {
        $this->connection->create(
            (new Blueprint('bf_ds_gate'))
                ->id()
                ->column(ColumnType::String, 'email', length: 255),
        );
        $this->connection->table('bf_ds_gate')->insert(['email' => 'a@x.io']);

        $step = new SchemaChange(
            'bf_ds_gate',
            SchemaOperation::DataStep,
            new Blueprint('bf_ds_gate'),
            true,
            'data step [update]: 1 statement(s)',
            statements: ['UPDATE bf_ds_gate SET email = \'redacted@x.io\''],
        );

        // Without confirmation the gate refuses.
        $synchronizer = new SchemaSynchronizer($this->connection);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('without confirmation');
        $synchronizer->apply([$step]);
    }

    /**
     * A confirmed DataStep applies; an insert-only step may opt out of
     * the destructive classification entirely.
     */
    public function testDataStepAppliesWhenConfirmedAndOptOutIsHonored(): void
    {
        $this->connection->create(
            (new Blueprint('bf_ds_opt'))
                ->id()
                ->column(ColumnType::String, 'email', length: 255),
        );

        $mutating = new SchemaChange(
            'bf_ds_opt',
            SchemaOperation::DataStep,
            new Blueprint('bf_ds_opt'),
            true,
            'data step [update]: 1 statement(s)',
            statements: ['UPDATE bf_ds_opt SET email = \'x@x.io\' WHERE 0'],
        );

        $insertOnly = new SchemaChange(
            'bf_ds_opt',
            SchemaOperation::DataStep,
            new Blueprint('bf_ds_opt'),
            false,
            'data step [insert]: 1 statement(s)',
            statements: ['INSERT INTO bf_ds_opt (email) VALUES (\'seed@x.io\')'],
        );

        $synchronizer = new SchemaSynchronizer($this->connection);
        $applied = $synchronizer->apply([$mutating, $insertOnly], confirm: fn (SchemaChange $change) => true);

        self::assertCount(2, $applied);

        $rows = $this->connection->selectSql('SELECT email FROM bf_ds_opt')->all();
        self::assertSame(['seed@x.io'], array_map(fn ($row) => $row->email, $rows));
    }

    /**
     * A DataStep with no statements fails fast at apply.
     */
    public function testDataStepWithoutStatementsFailsFast(): void
    {
        $step = new SchemaChange(
            'nowhere',
            SchemaOperation::DataStep,
            new Blueprint('nowhere'),
            false,
            'data step [empty]',
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('carries no statements');
        $this->connection->apply($step);
    }

    /**
     * A DataStep rides the transactional apply — a failing statement
     * rolls the whole step back (SQLite DDL/DML is transactional).
     */
    public function testDataStepFailureRollsBackInsideTransaction(): void
    {
        $this->connection->create(
            (new Blueprint('bf_ds_txn'))
                ->id()
                ->column(ColumnType::String, 'email', length: 255),
        );
        $this->connection->table('bf_ds_txn')->insert(['email' => 'a@x.io']);

        $step = new SchemaChange(
            'bf_ds_txn',
            SchemaOperation::DataStep,
            new Blueprint('bf_ds_txn'),
            true,
            'data step [failing]: 2 statement(s)',
            statements: [
                'UPDATE bf_ds_txn SET email = \'changed@x.io\'',
                'UPDATE bf_ds_txn SET missing_column = 1',
            ],
        );

        $synchronizer = new SchemaSynchronizer($this->connection);

        try {
            $synchronizer->apply([$step], confirm: fn (SchemaChange $change) => true, transactional: true);
            self::fail('The failing statement should have thrown.');
        } catch (\Throwable) {
            // The first statement must have rolled back with the step.
            $rows = $this->connection->selectSql('SELECT email FROM bf_ds_txn')->all();
            self::assertSame(['a@x.io'], array_map(fn ($row) => $row->email, $rows));
        }
    }
}
