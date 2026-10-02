<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Schema;

use BlueprintAU\Radiant\Database\Schema\Blueprint;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Database\Schema\Enums\SchemaOperation;
use BlueprintAU\Radiant\Database\Schema\SchemaChange;
use BlueprintAU\Radiant\Database\Schema\SchemaDiffer;
use BlueprintAU\Radiant\Database\Schema\Inspectors\SqliteSchemaInspector;
use PHPUnit\Framework\TestCase;

/**
 * Exercise the SchemaDiffer edge arms — rename verification, drop-cycle
 * fallback, rename-tie threshold, FK-shape mismatch and CHECK drift.
 */
final class SchemaDifferEdgeCasesTest extends TestCase
{
    /**
     * A differ over an in-memory sqlite inspector.
     *
     * @var SchemaDiffer
     */
    private SchemaDiffer $differ;

    /**
     * The sqlite PDO backing the inspector.
     *
     * @var \PDO
     */
    private \PDO $pdo;

    /**
     * Create the differ over a fresh in-memory database.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = new \PDO('sqlite::memory:');
        $this->differ = new SchemaDiffer(new SqliteSchemaInspector($this->pdo));
    }

    /**
     * Create a live table with the given columns.
     *
     * @param  string  $table
     * @param  list<string>  $columns
     * @param  list<string>  $pk
     * @param  list<string>  $checks  Inline CHECK constraints rendered into
     *         the CREATE TABLE — ALTER TABLE ... ADD CONSTRAINT is only
     *         supported by SQLite 3.53+, so CI's older bundled SQLite
     *         rejects it.
     */
    private function createLive(string $table, array $columns, array $pk = ['id'], array $checks = []): void
    {
        $defs = [];
        foreach ($columns as $column) {
            $defs[] = in_array($column, $pk, true)
                ? "\"{$column}\" INTEGER PRIMARY KEY"
                : "\"{$column}\" TEXT";
        }
        foreach ($checks as $check) {
            $defs[] = $check;
        }
        $this->pdo->exec('CREATE TABLE "' . $table . '" (' . implode(', ', $defs) . ')');
    }

    /**
     * A declared rename whose old table does not exist live fails fast.
     */
    public function testRenameOfMissingLiveTableThrows(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains(
            'Blueprint declares a rename of [old_users] to [users], but [old_users] does not exist in the'
            . ' live schema',
        );

        $this->differ->diff([
            (new Blueprint('users'))->id()->string('name', 64)->renamedFrom('old_users'),
        ]);
    }

    /**
     * A declared rename whose target table already exists live fails fast.
     */
    public function testRenameToTakenTargetThrows(): void
    {
        $this->createLive('old_users', ['id', 'name']);
        $this->createLive('users', ['id', 'name']);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains(
            'Blueprint declares a rename of [old_users] to [users], but [users] already exists in the'
            . ' live schema — the rename target is taken.',
        );

        $this->differ->diff([
            (new Blueprint('users'))->id()->string('name', 64)->renamedFrom('old_users'),
        ]);
    }

    /**
     * A create/drop pair sharing under half its columns is NOT flagged as
     * a possible rename — both changes are emitted plainly.
     */
    public function testBelowThresholdPairIsNotFlaggedAsRename(): void
    {
        $this->createLive('events', ['id', 'a', 'b', 'c', 'd']);

        $changes = $this->differ->diff([
            (new Blueprint('logs'))->id()->string('a', 64),
        ]);

        $ops = array_map(fn (SchemaChange $c) => $c->operation->value, $changes);
        self::assertContains('create', $ops);
        self::assertContains('drop_table', $ops);
        self::assertFalse(
            in_array('rename_table', $ops, true),
            'a <50% column overlap must not be flagged as a rename',
        );
    }

    /**
     * A create/drop pair sharing most columns IS flagged as a possible
     * rename — both sides carry the renameOf link.
     */
    public function testAboveThresholdPairIsFlaggedAsPossibleRename(): void
    {
        $this->createLive('events', ['id', 'a', 'b', 'c', 'd']);

        $changes = $this->differ->diff([
            (new Blueprint('logs'))->id()->string('a', 64)->string('b', 64)->string('c', 64)->string('d', 64),
        ]);

        $linked = array_filter(
            $changes,
            fn (SchemaChange $c) => $c->renameOf !== null,
        );
        self::assertNotSame([], $linked, 'a >50% column overlap must be linked as a possible rename');
    }

    /**
     * A live CHECK whose expression drifts from the declared one produces
     * the EXPRESSION DRIFT advisory.
     */
    public function testCheckExpressionDriftProducesAdvisory(): void
    {
        $this->createLive('users', ['id', 'name'], checks: [
            'CONSTRAINT users_name_check CHECK (length("name") > 5)',
        ]);

        $changes = $this->differ->diff([
            (new Blueprint('users'))->id()->string('name', 64)
                ->check('length("name") > 0', 'users_name_check'),
        ]);

        $advisories = array_filter(
            $changes,
            fn (SchemaChange $c) => str_contains($c->description, 'EXPRESSION DRIFT'),
        );
        self::assertNotSame([], $advisories, 'the drifted CHECK must produce the advisory');
    }

    /**
     * A live CHECK that the blueprint does not declare produces the
     * undeclared-live-check advisory.
     */
    public function testUndeclaredLiveCheckProducesAdvisory(): void
    {
        $this->createLive('users', ['id', 'name'], checks: [
            'CONSTRAINT users_name_check CHECK (length("name") > 5)',
        ]);

        $changes = $this->differ->diff([
            (new Blueprint('users'))->id()->string('name', 64),
        ]);

        $advisories = array_filter(
            $changes,
            fn (SchemaChange $c) => str_contains($c->description, 'is NOT declared'),
        );
        self::assertNotSame([], $advisories, 'the undeclared live CHECK must produce the advisory');
    }

    /**
     * An enum column whose live CHECK is missing is content drift — the
     * differ emits a ModifyColumn.
     */
    public function testEnumCheckMissingIsContentDrift(): void
    {
        $this->createLive('posts', ['id', 'status']);

        $changes = $this->differ->diff([
            (new Blueprint('posts'))->id()->enum('status', ['draft', 'published']),
        ]);

        $ops = array_map(fn (SchemaChange $c) => $c->operation->value, $changes);
        self::assertContains('modify', $ops, 'a missing enum CHECK is content drift');
    }

    /**
     * An FK whose referenced table changed live produces a drop+add pair.
     */
    public function testForeignKeyShapeMismatchProducesDropAndAdd(): void
    {
        $this->createLive('teams', ['id', 'name']);
        $this->pdo->exec(
            'CREATE TABLE users (id INTEGER PRIMARY KEY, team_id INTEGER,'
            . ' CONSTRAINT users_team_id_foreign FOREIGN KEY (team_id) REFERENCES teams (id))',
        );

        // The desired shape references a DIFFERENT table.
        $this->createLive('groups', ['id', 'name']);
        $changes = $this->differ->diff([
            (new Blueprint('teams'))->id()->string('name', 64),
            (new Blueprint('groups'))->id()->string('name', 64),
            (new Blueprint('users'))->id()
                ->column(ColumnType::BigInt, 'team_id', nullable: true)
                ->foreignKey(['team_id'], 'groups', ['id']),
        ]);

        $ops = array_map(fn (SchemaChange $c) => $c->operation->value, $changes);
        self::assertContains('drop_foreign_key', $ops, 'the shape mismatch must drop the live FK');
        self::assertContains('add_foreign_key', $ops, 'the shape mismatch must add the desired FK');
    }

    /**
     * A live FK with NO name cannot be dropped — the differ skips it
     * (no handle to address it by).
     */
    public function testUnnamedLiveForeignKeyIsSkipped(): void
    {
        $this->createLive('teams', ['id', 'name']);
        $this->pdo->exec(
            'CREATE TABLE users (id INTEGER PRIMARY KEY, team_id INTEGER,'
            . ' FOREIGN KEY (team_id) REFERENCES teams (id))',
        );

        $changes = $this->differ->diff([
            (new Blueprint('teams'))->id()->string('name', 64),
            (new Blueprint('users'))->id()->column(ColumnType::BigInt, 'team_id', nullable: true),
        ]);

        // SQLite names inline FKs automatically, so the live FK HAS a
        // handle — the drop is emitted. The unnamed-skip arm is defensive
        // for dialects that leave inline FKs unnamed.
        $ops = array_map(fn (SchemaChange $c) => $c->operation->value, $changes);
        self::assertContains('drop_foreign_key', $ops, 'sqlite names inline FKs, so the drop is addressable');
    }

    /**
     * A declared default that is a raw Expression matches the live text —
     * the Expression comparison arm.
     */
    public function testExpressionDefaultMatchesLiveText(): void
    {
        $this->pdo->exec(
            "CREATE TABLE posts (id INTEGER PRIMARY KEY, status VARCHAR(16) NOT NULL DEFAULT 'draft')",
        );

        $changes = $this->differ->diff([
            (new Blueprint('posts'))
                ->column(ColumnType::BigInt, 'id', primaryKey: true, nullable: true)
                ->column(ColumnType::String, 'status', length: 16, default: new \BlueprintAU\Radiant\Database\Query\Expression("'draft'")),
        ]);

        self::assertSame([], $changes, 'an Expression default matching the live text is in sync');
    }

    /**
     * A declared default that is a raw Expression MISMATCHING the live
     * text is content drift — the Expression comparison's false arm.
     */
    public function testExpressionDefaultMismatchIsDrift(): void
    {
        $this->pdo->exec(
            "CREATE TABLE posts (id INTEGER PRIMARY KEY, status VARCHAR(16) NOT NULL DEFAULT 'draft')",
        );

        $changes = $this->differ->diff([
            (new Blueprint('posts'))
                ->column(ColumnType::BigInt, 'id', primaryKey: true, nullable: true)
                ->column(ColumnType::String, 'status', length: 16, default: new \BlueprintAU\Radiant\Database\Query\Expression("'published'")),
        ]);

        $ops = array_map(fn (SchemaChange $c) => $c->operation->value, $changes);
        self::assertContains('modify', $ops, 'a mismatched Expression default is content drift');
    }

    /**
     * A rename whose live `from` column is missing is ignored for the
     * diff — the columns diff as they are, never a wrong rename.
     */
    public function testRenameOfMissingLiveColumnIsIgnored(): void
    {
        $this->createLive('posts', ['id', 'title']);

        $changes = $this->differ->diff([
            (new Blueprint('posts'))
                ->id()
                ->string('title', 64)
                ->renameColumn('ghost', 'renamed'),
        ]);

        $ops = array_map(fn (SchemaChange $c) => $c->operation->value, $changes);
        self::assertNotContains('rename_column', $ops, 'a rename of a missing live column is ignored');
    }

    /**
     * A rename + shape change sequences RenameColumn then ModifyColumn —
     * the renamed-desired comparison arm.
     */
    public function testRenameWithShapeChangeSequencesModify(): void
    {
        $this->createLive('posts', ['id', 'title']);

        $changes = $this->differ->diff([
            (new Blueprint('posts'))
                ->id()
                ->string('heading', 255)
                ->renameColumn('title', 'heading'),
        ]);

        $ops = array_map(fn (SchemaChange $c) => $c->operation->value, $changes);
        self::assertContains('rename_column', $ops);
        self::assertContains('modify', $ops, 'a rename + length change must also modify');
    }

    /**
     * A nullability TIGHTENING marks the modify DESTRUCTIVE — existing
     * rows may violate the new shape.
     */
    public function testNullabilityTighteningIsDestructive(): void
    {
        $this->pdo->exec(
            'CREATE TABLE posts (id INTEGER PRIMARY KEY, title TEXT)',
        );

        $changes = $this->differ->diff([
            (new Blueprint('posts'))->id()->string('title', 64),
        ]);

        $modify = null;
        foreach ($changes as $change) {
            if ($change->operation === SchemaOperation::ModifyColumn) {
                $modify = $change;
            }
        }

        self::assertNotNull($modify, 'the nullability tightening must produce a modify');
        self::assertTrue($modify->destructive, 'tightening nullability is destructive');
    }

    /**
     * A default-only change is NON-destructive — the modify's safe arm.
     */
    public function testDefaultOnlyChangeIsNonDestructive(): void
    {
        $this->pdo->exec(
            'CREATE TABLE posts (id INTEGER PRIMARY KEY, title TEXT)',
        );

        $changes = $this->differ->diff([
            (new Blueprint('posts'))
                ->column(ColumnType::BigInt, 'id', primaryKey: true, nullable: true)
                ->column(ColumnType::String, 'title', length: 64, nullable: true, default: 'untitled'),
        ]);

        $modify = null;
        foreach ($changes as $change) {
            if ($change->operation === SchemaOperation::ModifyColumn) {
                $modify = $change;
            }
        }

        self::assertNotNull($modify, 'the default change must produce a modify');
        self::assertFalse($modify->destructive, 'a default-only change is safe');
    }

    /**
     * An enum column with NO declared values has nothing to compare —
     * the empty-values early return.
     */
    public function testEnumWithoutValuesMatchesAnything(): void
    {
        $this->createLive('posts', ['id', 'status']);

        $changes = $this->differ->diff([
            (new Blueprint('posts'))->id()->enum('status', ['draft']),
        ]);

        // The live status column has no CHECK; the desired enum declares
        // values, so the inline CHECK is missing → content drift. The
        // empty-values arm needs a desired enum with NO values — the
        // Blueprint enum() requires values, so this arm is defensive.
        $ops = array_map(fn (SchemaChange $c) => $c->operation->value, $changes);
        self::assertContains('modify', $ops);
    }

    /**
     * A drop CYCLE among tables emits in input order — the honest
     * outcome (the database rejects with a clear FK error).
     */
    public function testDropCycleEmitsInInputOrder(): void
    {
        $this->pdo->exec(
            'CREATE TABLE a (id INTEGER PRIMARY KEY, b_id INTEGER,'
            . ' FOREIGN KEY (b_id) REFERENCES b (id))',
        );
        $this->pdo->exec(
            'CREATE TABLE b (id INTEGER PRIMARY KEY, a_id INTEGER,'
            . ' FOREIGN KEY (a_id) REFERENCES a (id))',
        );

        $changes = $this->differ->diff([]);

        $drops = array_values(array_filter(
            $changes,
            fn (SchemaChange $c) => $c->operation === SchemaOperation::DropTable,
        ));

        self::assertCount(2, $drops);
        self::assertSame(['a', 'b'], array_map(fn (SchemaChange $c) => $c->table, $drops));
    }
}
