<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Database;

use BlueprintAU\Radiant\Database\Connections\MySqlConnection;
use BlueprintAU\Radiant\Database\Connections\SqliteConnection;
use BlueprintAU\Radiant\Database\Exceptions\UnsupportedFeatureException;
use BlueprintAU\Radiant\Database\Schema\Blueprint;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Database\Schema\Enums\SchemaOperation;
use BlueprintAU\Radiant\Database\Schema\SchemaChange;
use PHPUnit\Framework\TestCase;

/**
 * Exercise the SqlConnection schema-op dispatch — the alter guard, the
 * RenameTable renamedFrom contract, the empty-declaration fail-fasts and
 * the sqlite rebuild routing.
 */
final class SqlConnectionSchemaOpsTest extends TestCase
{
    /**
     * A connection to an in-memory SQLite database.
     *
     * @var SqliteConnection
     */
    private SqliteConnection $connection;

    /**
     * Create the connection and a `users` table.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = new SqliteConnection(new \PDO('sqlite::memory:'));
        $this->connection->statement(
            'CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)',
        );
        $this->connection->statement("INSERT INTO users (name) VALUES ('Alice')");
    }

    /**
     * alter() rejects a non-column operation — the dedicated paths own
     * create/drop/rebuild.
     */
    public function testAlterRejectsNonColumnOperation(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains(
            'Operation [create] is not a column alter; use the dedicated create/drop/rebuildIndexes paths.',
        );

        $this->connection->alter(SchemaOperation::CreateTable, new Blueprint('users'));
    }

    /**
     * apply() on a RenameTable change without a renamedFrom declaration
     * fails fast — the rename target is unknowable.
     */
    public function testApplyRejectsRenameTableWithoutRenamedFrom(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains(
            'A RenameTable change for [users] carries no renamedFrom declaration.',
        );

        $this->connection->apply(new SchemaChange(
            'users',
            SchemaOperation::RenameTable,
            new Blueprint('users'),
            false,
            'rename users',
        ));
    }

    /**
     * addForeignKey() with no FK declaration on the blueprint fails fast.
     *
     * The guard lives on the BASE connection method; sqlite overrides the
     * method to route through the rebuild, so the MySQL connection over a
     * throwaway sqlite PDO exercises it (the guard fires before any SQL).
     */
    public function testAddForeignKeyRejectsEmptyDeclaration(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains(
            'An AddForeignKey change for [users] carries no foreign key declaration.',
        );

        $mysql = new MySqlConnection(new \PDO('sqlite::memory:'));
        $mysql->addForeignKey('users', new Blueprint('users'));
    }

    /**
     * dropForeignKey() with no constraint name on the blueprint fails fast.
     *
     * Same base-method guard, same MySQL-over-sqlite route.
     */
    public function testDropForeignKeyRejectsEmptyDeclaration(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains(
            'A DropForeignKey change for [users] carries no constraint name.',
        );

        $mysql = new MySqlConnection(new \PDO('sqlite::memory:'));
        $mysql->dropForeignKey('users', new Blueprint('users'));
    }

    /**
     * addCheck() with no CHECK declaration on the blueprint fails fast.
     *
     * Same base-method guard, same MySQL-over-sqlite route.
     */
    public function testAddCheckRejectsEmptyDeclaration(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains(
            'An AddCheck change for [users] carries no CHECK declaration.',
        );

        $mysql = new MySqlConnection(new \PDO('sqlite::memory:'));
        $mysql->addCheck('users', new Blueprint('users'));
    }

    /**
     * dropCheck() with no constraint name on the blueprint fails fast.
     *
     * Same base-method guard, same MySQL-over-sqlite route.
     */
    public function testDropCheckRejectsEmptyDeclaration(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains(
            'A DropCheck change for [users] carries no constraint name.',
        );

        $mysql = new MySqlConnection(new \PDO('sqlite::memory:'));
        $mysql->dropCheck('users', new Blueprint('users'));
    }

    /**
     * apply(ModifyColumn) routes to the sqlite rebuild — the table is
     * rebuilt in place and the data survives.
     */
    public function testApplyModifyColumnRoutesThroughRebuild(): void
    {
        $desired = (new Blueprint('users'))
            ->id()
            ->string('name', 255);

        $this->connection->apply(new SchemaChange(
            'users',
            SchemaOperation::ModifyColumn,
            $desired,
            false,
            'modify column(s) on [users]: [name]',
        ));

        $rows = $this->connection->selectSql('SELECT name FROM users');
        self::assertCount(1, $rows);
        $first = $rows->first();
        self::assertNotNull($first);
        self::assertSame('Alice', $first->name);
    }

    /**
     * apply(AddColumn) lands on the in-place alter path — the alter
     * blueprint carries ONLY the added columns (the differ's shape), the
     * new column is present and the data survives.
     */
    public function testApplyAddColumnAltersInPlace(): void
    {
        $alter = (new Blueprint('users'))
            ->column(ColumnType::String, 'email', nullable: true, length: 255);

        $this->connection->apply(new SchemaChange(
            'users',
            SchemaOperation::AddColumn,
            $alter,
            false,
            'alter table [users]: add column(s) [email]',
        ));

        $rows = $this->connection->selectSql('SELECT name, email FROM users');
        self::assertCount(1, $rows);
        $first = $rows->first();
        self::assertNotNull($first);
        self::assertSame('Alice', $first->name);
        self::assertNull($first->email);
    }

    /**
     * alter(DropColumn) on sqlite throws — the sqlite grammar inherits the
     * base no-in-place-drop refusal; drops route through the rebuild
     * (ModifyColumn) instead.
     */
    public function testAlterDropColumnThrowsOnSqlite(): void
    {
        $this->expectException(UnsupportedFeatureException::class);
        $this->expectExceptionMessageIsOrContains('does not support dropping columns');

        $alter = (new Blueprint('users'))->dropColumn('name');

        $this->connection->alter(SchemaOperation::DropColumn, $alter);
    }

    /**
     * A column drop routes through the sqlite rebuild (ModifyColumn) — the
     * copy projection is live ∩ desired, so the dropped column is gone and
     * the remaining data survives.
     */
    public function testApplyColumnDropRoutesThroughRebuild(): void
    {
        $desired = (new Blueprint('users'))->id();

        $this->connection->apply(new SchemaChange(
            'users',
            SchemaOperation::ModifyColumn,
            $desired,
            true,
            'modify column(s) on [users]',
        ));

        $rows = $this->connection->selectSql('SELECT id FROM users');
        self::assertCount(1, $rows);
        $first = $rows->first();
        self::assertNotNull($first);
        self::assertSame(1, $first->id);
    }

    /**
     * apply(RenameTable) with a proper renamedFrom declaration renames the
     * table and keeps the rows.
     */
    public function testApplyRenameTableRenamesWithRows(): void
    {
        $blueprint = (new Blueprint('members'))
            ->id()
            ->string('name', 255)
            ->renamedFrom('users');

        $this->connection->apply(new SchemaChange(
            'members',
            SchemaOperation::RenameTable,
            $blueprint,
            false,
            'rename [users] to [members]',
        ));

        $rows = $this->connection->selectSql('SELECT name FROM members');
        self::assertCount(1, $rows);
        $first = $rows->first();
        self::assertNotNull($first);
        self::assertSame('Alice', $first->name);
    }

    /**
     * apply(CreateTable) dispatches the create path — the table exists
     * afterwards.
     */
    public function testApplyCreateTableCreates(): void
    {
        $blueprint = (new Blueprint('teams'))
            ->id()
            ->string('name', 64);

        $this->connection->apply(new SchemaChange(
            'teams',
            SchemaOperation::CreateTable,
            $blueprint,
            false,
            'create table [teams]',
        ));

        $rows = $this->connection->selectSql(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'teams'",
        );
        self::assertCount(1, $rows);
    }

    /**
     * apply(DropTable) dispatches the drop path — the table is gone.
     */
    public function testApplyDropTableDrops(): void
    {
        $this->connection->apply(new SchemaChange(
            'users',
            SchemaOperation::DropTable,
            new Blueprint('users'),
            true,
            'drop table [users]',
        ));

        $rows = $this->connection->selectSql(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'users'",
        );
        self::assertCount(0, $rows);
    }

    /**
     * apply(AddForeignKey) routes to the sqlite rebuild — the constraint
     * lands and the data survives. The FK's column must be DECLARED on the
     * blueprint (the rebuild creates the temp table from it) and present on
     * the live table (the copy projection is live ∩ desired).
     */
    public function testApplyAddForeignKeyRoutesThroughRebuild(): void
    {
        $this->connection->statement(
            'CREATE TABLE teams (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)',
        );
        $this->connection->statement('ALTER TABLE users ADD COLUMN team_id INTEGER');

        $desired = (new Blueprint('users'))
            ->id()
            ->string('name', 255)
            ->column(ColumnType::BigInt, 'team_id', nullable: true)
            ->foreignKey(['team_id'], 'teams', ['id']);

        $this->connection->apply(new SchemaChange(
            'users',
            SchemaOperation::AddForeignKey,
            $desired,
            false,
            'add foreign key on [users]',
        ));

        $rows = $this->connection->selectSql('SELECT name FROM users');
        self::assertCount(1, $rows);
        $first = $rows->first();
        self::assertNotNull($first);
        self::assertSame('Alice', $first->name);
    }

    /**
     * The column type import is exercised — a typed column declaration
     * compiles through the rebuild.
     */
    public function testRebuildCarriesTypedColumn(): void
    {
        $desired = (new Blueprint('users'))
            ->id()
            ->column(ColumnType::Text, 'bio', nullable: true);

        $this->connection->statement('ALTER TABLE users ADD COLUMN bio TEXT');

        $this->connection->apply(new SchemaChange(
            'users',
            SchemaOperation::ModifyColumn,
            $desired,
            false,
            'modify column(s) on [users]: [bio]',
        ));

        $rows = $this->connection->selectSql('SELECT bio FROM users');
        self::assertCount(1, $rows);
        $first = $rows->first();
        self::assertNotNull($first);
        self::assertNull($first->bio);
    }
}
