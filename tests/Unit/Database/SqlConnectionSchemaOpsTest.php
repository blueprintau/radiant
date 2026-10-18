<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Database;

use BlueprintAU\Radiant\Database\Connections\MySqlConnection;
use BlueprintAU\Radiant\Database\Connections\SqliteConnection;
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
     * alter(DropColumn) on sqlite drops the column in place — SQLite 3.35+
     * supports DROP COLUMN natively, and the data in the remaining columns
     * survives.
     */
    public function testAlterDropColumnDropsOnSqlite(): void
    {
        $alter = (new Blueprint('users'))->dropColumn('name');

        $this->connection->alter(SchemaOperation::DropColumn, $alter);

        $live = $this->connection->schemaInspector->table('users');
        self::assertSame(['id'], array_column($live->columns, 'name'));
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

    /**
     * apply(DropColumn) drops the column's foreign-key constraints FIRST
     * — the base applyDropColumn() emits the dialect FK-drop for each
     * live constraint naming a subject column before the native
     * `DROP COLUMN`. MySQL/MariaDB reject the column drop otherwise
     * (error 1828), and Postgres requires the constraint gone first.
     *
     * The MySQL connection over a sqlite PDO exercises the statement
     * ORDER through a statement-logging PDO wrapper (MySQL has no live
     * server in unit tests; the base applyDropColumn() is
     * dialect-neutral and the FK-drop form is MySQL's).
     */
    public function testApplyDropColumnDropsForeignKeysFirst(): void
    {
        $log = new StatementLog();

        $pdo = new LoggingPdo($log);
        $mysql = new MySqlConnection($pdo);

        $pdo->catalogTable('users', [
            ['id', 'int', 'NO', null, 'PRI'],
            ['name', 'varchar(255)', 'YES', null, ''],
            ['circle_id', 'bigint', 'YES', null, ''],
        ]);
        $pdo->catalogForeignKey('users_circle_id_foreign', 'users', 'circle_id', 'users', 'id');

        $mysql->statement('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, circle_id INTEGER)');

        $blueprint = (new Blueprint('users'))
            ->id()
            ->string('name', 255)
            ->column(ColumnType::BigInt, 'circle_id', foreign: 'users.id');

        $mysql->apply(new SchemaChange(
            'users',
            SchemaOperation::DropColumn,
            $blueprint,
            true,
            'alter table [users]: drop column(s) [circle_id]',
            false,
            null,
            ['circle_id'],
        ));

        $fkDropIndices = array_keys(array_filter(
            $log->statements,
            fn (string $sql) => str_contains($sql, 'DROP FOREIGN KEY'),
        ));
        $columnDropIndices = array_keys(array_filter(
            $log->statements,
            fn (string $sql) => str_contains($sql, 'DROP COLUMN'),
        ));

        self::assertNotSame([], $fkDropIndices, 'the FK drop must be in the log');
        self::assertNotSame([], $columnDropIndices, 'the column drop must be in the log');
        self::assertLessThan(
            $columnDropIndices[0],
            $fkDropIndices[0],
            'the DROP FOREIGN KEY must run BEFORE the DROP COLUMN',
        );
        self::assertStringContainsString('users_circle_id_foreign', $log->statements[$fkDropIndices[0]]);
    }

    /**
     * apply(DropColumn) on a NON-FK-referenced column emits NO FK-drop —
     * the fast path stays untouched.
     */
    public function testApplyDropColumnWithoutForeignKeysSkipsFkDrops(): void
    {
        $log = new StatementLog();

        $pdo = new LoggingPdo($log);
        $mysql = new MySqlConnection($pdo);

        $pdo->catalogTable('users', [
            ['id', 'int', 'NO', null, 'PRI'],
            ['name', 'varchar(255)', 'YES', null, ''],
        ]);

        $mysql->statement('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');

        $blueprint = (new Blueprint('users'))
            ->id()
            ->string('name', 255);

        $mysql->apply(new SchemaChange(
            'users',
            SchemaOperation::DropColumn,
            $blueprint,
            true,
            'alter table [users]: drop column(s) [name]',
            false,
            null,
            ['name'],
        ));

        self::assertSame([], array_values(array_filter(
            $log->statements,
            fn (string $sql) => str_contains($sql, 'DROP FOREIGN KEY'),
        )), 'no FK drop may compile for an FK-free column');
    }

    /**
     * A DIFFER-PRODUCED DropForeignKey change applies on MySQL — the
     * regression test for the LogicException
     * "A DropForeignKey change for [users] carries no constraint name":
     * the differ used to bake the live constraint name only into the
     * change's description, so the in-place dialect's
     * `getDropForeignKeys()` read was empty. SQLite masked the bug (its
     * rebuild ignores the blueprint's drop list).
     *
     * The MySQL shim runs the full differ → synchronizer-apply pipeline:
     * the drifted FK (declared nowhere in the desired shape) is detected
     * as DropForeignKey, and the apply emits the named
     * `DROP FOREIGN KEY` statement.
     */
    public function testDifferProducedDropForeignKeyAppliesOnMysql(): void
    {
        $log = new StatementLog();

        $pdo = new LoggingPdo($log);
        $mysql = new MySqlConnection($pdo);

        // The LIVE table carries an FK the desired shape will not declare.
        $pdo->catalogTable('users', [
            ['id', 'int', 'NO', null, 'PRI'],
            ['circle_id', 'bigint', 'YES', null, ''],
        ]);
        $pdo->catalogForeignKey('users_circle_id_foreign', 'users', 'circle_id', 'users', 'id');

        $mysql->statement('CREATE TABLE users (id INTEGER PRIMARY KEY, circle_id INTEGER)');

        // Desired shape: the column stays, the FK is undeclared.
        $desired = (new Blueprint('users'))
            ->id()
            ->column(ColumnType::BigInt, 'circle_id');

        $differ = new \BlueprintAU\Radiant\Database\Schema\SchemaDiffer($mysql->schemaInspector);

        // The probe shape: the shim's declared-vs-live column metadata
        // also trips a column-type modify (int vs bigint metadata text) —
        // filter the assertion to the FK drop only.
        $drops = array_values(array_filter(
            $differ->diff([$desired]),
            fn (\BlueprintAU\Radiant\Database\Schema\SchemaChange $change) => $change->operation === SchemaOperation::DropForeignKey,
        ));
        self::assertCount(1, $drops);

        // The fix: the constraint name rides the blueprint's drop list —
        // the handle the in-place dialect's apply reads.
        self::assertSame(['users_circle_id_foreign'], $drops[0]->blueprint->getDropForeignKeys());

        // The apply path — this threw the LogicException before the fix.
        $mysql->apply($drops[0]);

        $fkDrops = array_values(array_filter(
            $log->statements,
            fn (string $sql) => str_contains($sql, 'DROP FOREIGN KEY'),
        ));
        self::assertCount(1, $fkDrops, 'exactly one named FK drop must compile');
        self::assertStringContainsString('users_circle_id_foreign', $fkDrops[0]);

        // A re-diff converges — no FK drift remains.
        $remaining = array_filter(
            $differ->diff([$desired]),
            fn (\BlueprintAU\Radiant\Database\Schema\SchemaChange $change) => $change->operation === SchemaOperation::DropForeignKey,
        );
        self::assertSame([], $remaining);
    }
}

/**
 * The captured statement log, shared by reference with the logging PDO.
 */
final class StatementLog
{
    /** @var list<string> */
    public array $statements = [];
}

/**
 * A PDO stand-in that emulates a MySQL connection on sqlite: an attached
 * `information_schema` catalog feeds MySqlSchemaInspector, the
 * `DATABASE()` function answers the shim's schema name, and a
 * `DROP FOREIGN KEY` statement mutates the catalog (MySQL semantics)
 * instead of failing sqlite's parser. Every statement is recorded — the
 * compile-order observer for connections whose statement() is final.
 */
final class LoggingPdo extends \Pdo\Sqlite
{
    /**
     * Build the shim: the catalog tables the MySQL inspector queries,
     * plus the DATABASE() stub.
     *
     * @param  StatementLog  $log  The shared capture target.
     */
    public function __construct(
        private readonly StatementLog $log,
    ) {
        parent::__construct('sqlite::memory:');
        $this->createFunction('DATABASE', fn (): string => 'test', 0);

        // ATTACH of a second :memory: creates a writable schema sqlite
        // resolves `information_schema.*` through — impossible to create
        // in the main database directly.
        $this->exec('ATTACH DATABASE \':memory:\' AS information_schema');

        foreach ([
            'tables (table_schema TEXT, table_name TEXT)',
            'columns (table_schema TEXT, table_name TEXT, ordinal_position INTEGER,'
                . ' column_name TEXT, column_type TEXT, is_nullable TEXT,'
                . ' column_default TEXT, column_key TEXT)',
            'statistics (table_schema TEXT, table_name TEXT, index_name TEXT,'
                . ' column_name TEXT, non_unique INTEGER, seq_in_index INTEGER)',
            'key_column_usage (constraint_schema TEXT, constraint_name TEXT,'
                . ' table_schema TEXT, table_name TEXT, column_name TEXT,'
                . ' referenced_table_name TEXT, referenced_column_name TEXT,'
                . ' ordinal_position INTEGER)',
            'referential_constraints (constraint_schema TEXT, constraint_name TEXT,'
                . ' delete_rule TEXT, update_rule TEXT)',
        ] as $ddl) {
            $this->exec("CREATE TABLE information_schema.{$ddl}");
        }
    }

    /**
     * Seed a catalog row for a live table — one row per MySQL column the
     * inspector's `columns` query should answer.
     *
     * @param  string  $table
     * @param  list<array{string, string, string, string|null, string}>  $columns
     *     Each: [name, type, is_nullable, column_default, column_key].
     */
    public function catalogTable(string $table, array $columns): void
    {
        $this->exec(
            "INSERT INTO information_schema.tables VALUES ('test', '{$table}')",
        );

        foreach ($columns as $position => [$name, $type, $nullable, $default, $key]) {
            $default = $default === null ? 'NULL' : "'{$default}'";

            $this->exec(sprintf(
                'INSERT INTO information_schema.columns VALUES'
                . " ('test', '%s', %d, '%s', '%s', '%s', %s, '%s')",
                $table,
                $position + 1,
                $name,
                $type,
                $nullable,
                $default,
                $key,
            ));
        }
    }

    /**
     * Seed a catalog FK — the constraint MySqlSchemaInspector reads and
     * applyDropColumn() drops.
     *
     * @param  string  $name
     * @param  string  $table
     * @param  string  $column
     * @param  string  $referencesTable
     * @param  string  $referencesColumn
     */
    public function catalogForeignKey(
        string $name,
        string $table,
        string $column,
        string $referencesTable,
        string $referencesColumn,
    ): void {
        $this->exec(sprintf(
            'INSERT INTO information_schema.key_column_usage VALUES'
            . " ('test', '%s', 'test', '%s', '%s', '%s', '%s', 1)",
            $name,
            $table,
            $column,
            $referencesTable,
            $referencesColumn,
        ));
        $this->exec(sprintf(
            'INSERT INTO information_schema.referential_constraints VALUES'
            . " ('test', '%s', 'NO ACTION', 'NO ACTION')",
            $name,
        ));
    }

    /**
     * Record the SQL; a `DROP FOREIGN KEY` mutates the catalog (MySQL
     * emulation), everything else runs on sqlite.
     *
     * @param  string  $query
     * @param  array<int, mixed>  $options
     * @return \PDOStatement|false
     */
    #[\Override]
    public function prepare(string $query, array $options = []): \PDOStatement|false
    {
        $this->log->statements[] = $query;

        if (preg_match('/DROP\s+FOREIGN\s+KEY\s+[`"]?([A-Za-z0-9_]+)/i', $query, $matches) === 1) {
            $name = $matches[1];

            $this->exec(
                "DELETE FROM information_schema.key_column_usage WHERE constraint_name = '{$name}'",
            );
            $this->exec(
                "DELETE FROM information_schema.referential_constraints WHERE constraint_name = '{$name}'",
            );

            return new LoggingNoopStatement();
        }

        return parent::prepare($query, $options);
    }

    /**
     * Record the SQL, then run it here.
     *
     * @param  string  $query
     * @return int|false
     */
    #[\Override]
    public function exec(string $query): int|false
    {
        $this->log->statements[] = $query;

        return parent::exec($query);
    }

    /**
     * Record the SQL, then run it here.
     *
     * @param  string  $query
     * @param  int|null  $fetchMode
     * @param  mixed  ...$fetchModeArgs
     * @return \PDOStatement|false
     */
    #[\Override]
    public function query(string $query, int|null $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false
    {
        $this->log->statements[] = $query;

        return parent::query($query, $fetchMode, ...$fetchModeArgs);
    }
}

/**
 * The emulated MySQL statement's handle — the catalog mutation already
 * ran; nothing left to execute.
 */
final class LoggingNoopStatement extends \PDOStatement
{
    /**
     * Nothing to run — the emulated statement has no driver work.
     *
     * @param  array<int|string, mixed>|null  $params
     * @return bool
     */
    #[\Override]
    public function execute(?array $params = null): bool
    {
        return true;
    }
}
