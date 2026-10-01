<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Schema;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Attributes\Index;
use BlueprintAU\Radiant\Attributes\Table;
use BlueprintAU\Radiant\Database\Schema\Blueprint;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Database\Schema\Enums\ForeignKeyAction;
use BlueprintAU\Radiant\Metadata\MetadataFactory;
use BlueprintAU\Radiant\Model;
use PHPUnit\Framework\TestCase;

/**
 * Exercise the Blueprint edge arms — rename/reference validation, FK
 * option guards, drop-constraint declarations and the MTI skip arms.
 */
final class BlueprintDropConstraintsTest extends TestCase
{
    /**
     * Reset the metadata cache after every test.
     */
    protected function tearDown(): void
    {
        MetadataFactory::clear();

        parent::tearDown();
    }

    // ---- Rename validation ----

    /**
     * A whitespace-only old table name fails the rename validation.
     */
    public function testRenamedFromRejectsEmptyName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'A table rename requires a non-empty old table name.',
        );

        (new Blueprint('users'))->renamedFrom('   ');
    }

    /**
     * A whitespace-only column rename fails the validation.
     */
    public function testRenameColumnRejectsEmptyName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'A column rename requires non-empty column names.',
        );

        (new Blueprint('users'))->renameColumn('   ', 'email');
    }

    /**
     * A column rename to the same name fails — the rename is a no-op.
     */
    public function testRenameColumnRejectsSameName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'A column rename requires different names; got [email] -> [email].',
        );

        (new Blueprint('users'))->renameColumn('email', 'email');
    }

    // ---- Foreign-key guards ----

    /**
     * A foreign key with no columns fails the arity contract.
     */
    public function testForeignKeyRejectsEmptyColumns(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'A foreign key requires at least one column.',
        );

        (new Blueprint('users'))->foreignKey([], 'teams', ['id']);
    }

    /**
     * A foreign key with mismatched column/reference arity fails fast.
     */
    public function testForeignKeyRejectsArityMismatch(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'Foreign key columns and references must have matching arity; got 1 and 2.',
        );

        (new Blueprint('users'))->foreignKey(['team_id'], 'teams', ['id', 'org_id']);
    }

    /**
     * initiallyDeferred without deferrable fails — INITIALLY DEFERRED
     * implies DEFERRABLE.
     */
    public function testForeignKeyRejectsInitiallyDeferredWithoutDeferrable(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'A foreign key declares initiallyDeferred without deferrable:'
            . ' INITIALLY DEFERRED implies DEFERRABLE.',
        );

        (new Blueprint('users'))->foreignKey(
            ['team_id'],
            'teams',
            ['id'],
            ForeignKeyAction::Cascade,
            null,
            false,
            true,
        );
    }

    // ---- Drop declarations ----

    /**
     * dropForeignKey() appends the live constraint name to the drop list.
     */
    public function testDropForeignKeyAppendsName(): void
    {
        $blueprint = (new Blueprint('users'))
            ->dropForeignKey('users_team_id_foreign')
            ->dropForeignKey('users_org_id_foreign');

        self::assertSame(
            ['users_team_id_foreign', 'users_org_id_foreign'],
            $blueprint->getDropForeignKeys(),
        );
    }

    /**
     * dropCheck() appends the live constraint name to the drop list.
     */
    public function testDropCheckAppendsName(): void
    {
        $blueprint = (new Blueprint('users'))
            ->dropCheck('users_name_check');

        self::assertSame(['users_name_check'], $blueprint->getDropChecks());
    }

    // ---- Check naming ----

    /**
     * A CHECK whose expression names no declared column derives a
     * positional suffix name.
     */
    public function testCheckWithoutDeclaredColumnDerivesSuffixName(): void
    {
        $blueprint = (new Blueprint('users'))
            ->id()
            ->string('name', 64)
            ->check('length("other_table_col") > 0');

        $checks = $blueprint->getChecks();

        self::assertCount(1, $checks);
        self::assertSame('users_1_check', $checks[0]['name']);
    }

    /**
     * A CHECK whose expression names a declared column derives its name
     * from that column.
     */
    public function testCheckWithDeclaredColumnDerivesColumnName(): void
    {
        $blueprint = (new Blueprint('users'))
            ->id()
            ->string('name', 64)
            ->check('length("name") > 0');

        $checks = $blueprint->getChecks();

        self::assertCount(1, $checks);
        self::assertSame('users_name_check', $checks[0]['name']);
    }

    // ---- Column helpers ----

    /**
     * datetime() is a timestamp() alias — a nullable datetime column.
     */
    public function testDatetimeIsATimestampAlias(): void
    {
        $blueprint = (new Blueprint('users'))->datetime('expires_at');

        $columns = $blueprint->getColumns();

        self::assertCount(1, $columns);
        self::assertSame(ColumnType::DateTime, $columns[0]['type']);
        self::assertSame('expires_at', $columns[0]['name']);
        self::assertTrue($columns[0]['nullable']);
    }

    // ---- fromMetadata MTI arms ----

    /**
     * An MTI child's blueprint skips the inherited (parent-owned) columns
     * — the child table holds the derived key plus the child's own
     * columns only.
     */
    public function testFromMetadataMtiChildSkipsInheritedColumns(): void
    {
        $blueprint = Blueprint::fromMetadata(MtiChildFixture::class);

        $columns = array_map(fn (array $c) => $c['name'], $blueprint->getColumns());

        self::assertNotContains('email', $columns, 'the inherited email belongs to the parent table');
        self::assertSame(['id', 'label'], $columns);
    }

    /**
     * An MTI child's blueprint emits the parent-link FK — the child PK
     * references the parent table with ON DELETE CASCADE.
     */
    public function testFromMetadataMtiChildEmitsParentForeignKey(): void
    {
        $blueprint = Blueprint::fromMetadata(MtiChildFixture::class);

        $foreignKeys = $blueprint->getForeignKeys();

        self::assertCount(1, $foreignKeys);
        self::assertSame(['id'], $foreignKeys[0]['columns']);
        self::assertSame(['mti_parent_fixtures', 'id'], $foreignKeys[0]['references']);
        self::assertSame(ForeignKeyAction::Cascade, $foreignKeys[0]['onDelete']);
    }

    /**
     * A constraint over an inherited column is skipped from the child
     * blueprint — it belongs to the parent table.
     */
    public function testFromMetadataMtiChildSkipsInheritedColumnConstraint(): void
    {
        $blueprint = Blueprint::fromMetadata(MtiUniqueChildFixture::class);

        $columns = array_map(fn (array $c) => $c['name'], $blueprint->getColumns());
        self::assertSame(['id', 'label'], $columns);

        // The unique over the inherited email column rides the parent.
        self::assertSame([], $blueprint->getIndexes());
    }
}

/**
 * An MTI parent fixture.
 */
#[Table(name: 'mti_parent_fixtures')]
class MtiParentFixture extends Model
{
    /**
     * The shared primary key.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * An inherited column.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 255, unique: true)]
    public string $email;
}

/**
 * An MTI child fixture — owns one column of its own.
 */
#[Table(name: 'mti_child_fixtures')]
class MtiChildFixture extends MtiParentFixture
{
    /**
     * A child-only column.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 64)]
    public string $label;
}

/**
 * An MTI child fixture whose class-level index covers an inherited
 * column — the constraint belongs to the parent table.
 */
#[Table(name: 'mti_unique_child_fixtures')]
#[Index(columns: ['email'])]
class MtiUniqueChildFixture extends MtiParentFixture
{
    /**
     * A child-only column.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 64)]
    public string $label;
}
