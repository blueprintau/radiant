<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Schema;

use BlueprintAU\Radiant\Database\Schema\Blueprint;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
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
}
