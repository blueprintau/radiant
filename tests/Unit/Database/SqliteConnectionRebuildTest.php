<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Database;

use BlueprintAU\Radiant\Database\Connections\SqliteConnection;
use BlueprintAU\Radiant\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase;

/**
 * Exercise the SqliteConnection table-rebuild paths — FK/CHECK drops via
 * rebuild, the temp-name and PRAGMA guards, and the foreign_key_check
 * rollback.
 */
final class SqliteConnectionRebuildTest extends TestCase
{
    /**
     * A connection to an in-memory SQLite database.
     *
     * @var SqliteConnection
     */
    private SqliteConnection $connection;

    /**
     * Create the connection and a parent/child pair joined by a foreign
     * key.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = new SqliteConnection(new \PDO('sqlite::memory:'));
        $this->connection->statement(
            'CREATE TABLE teams (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)',
        );
        $this->connection->statement(
            'CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT,'
            . ' team_id INTEGER, FOREIGN KEY (team_id) REFERENCES teams (id))',
        );
        $this->connection->statement("INSERT INTO teams (name) VALUES ('Core')");
        $this->connection->statement("INSERT INTO users (name, team_id) VALUES ('Alice', 1)");
    }

    /**
     * SQLite DDL is transactional — schema statements roll back with the
     * transaction.
     */
    public function testSupportsTransactionalDdl(): void
    {
        self::assertTrue($this->connection->supportsTransactionalDdl());
    }

    /**
     * dropForeignKey() routes through the rebuild — the constraint is gone
     * and the data survives.
     */
    public function testDropForeignKeyRoutesThroughRebuild(): void
    {
        $desired = (new Blueprint('users'))
            ->id()
            ->string('name', 255)
            ->column(\BlueprintAU\Radiant\Database\Schema\Enums\ColumnType::BigInt, 'team_id', nullable: true);

        $this->connection->dropForeignKey('users', $desired);

        $rows = $this->connection->selectSql('SELECT name, team_id FROM users');
        self::assertCount(1, $rows);
        $first = $rows->first();
        self::assertNotNull($first);
        self::assertSame('Alice', $first->name);
        self::assertSame(1, $first->team_id);

        $constraints = $this->connection->selectSql('PRAGMA foreign_key_list(users)');
        self::assertCount(0, $constraints, 'the foreign key must be gone after the rebuild');
    }

    /**
     * dropCheck() routes through the rebuild — the constraint is gone and
     * the data survives.
     */
    public function testDropCheckRoutesThroughRebuild(): void
    {
        $this->connection->statement('DROP TABLE users');
        $this->connection->statement(
            'CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT,'
            . ' CONSTRAINT users_name_check CHECK (length(name) > 0))',
        );
        $this->connection->statement("INSERT INTO users (name) VALUES ('Alice')");

        $desired = (new Blueprint('users'))
            ->id()
            ->string('name', 255);

        $this->connection->dropCheck('users', $desired);

        $rows = $this->connection->selectSql('SELECT name FROM users');
        self::assertCount(1, $rows);
        $first = $rows->first();
        self::assertNotNull($first);
        self::assertSame('Alice', $first->name);

        $checks = $this->connection->selectSql(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'users'"
            . " AND sql LIKE '%users_name_check%'",
        );
        self::assertCount(0, $checks, 'the CHECK must be gone after the rebuild');
    }

    /**
     * A rebuild whose temp table already exists fails fast — the leftover
     * would collide with the rebuild's own temp table.
     */
    public function testRebuildRejectsWhenTempTableExists(): void
    {
        $this->connection->statement('CREATE TABLE users__radiant_new (id INTEGER)');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains(
            'Cannot rebuild [users]: the temp table [users__radiant_new] already exists.',
        );

        $this->connection->modifyColumn(
            (new Blueprint('users'))->id()->string('name', 255),
        );
    }

    /**
     * A rebuild that would copy no columns is refused — the data cannot
     * survive, so the change must be explicit drop + create.
     */
    public function testRebuildRefusesWhenNoColumnsWouldCopy(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('would copy no columns');

        $this->connection->modifyColumn(
            (new Blueprint('users'))->string('unrelated', 64),
        );
    }

    /**
     * A rebuild that needs the foreign_keys PRAGMA toggle cannot run inside
     * a transaction — the toggle is a no-op there and the drop would
     * cascade-delete child rows.
     */
    public function testRebuildInsideTransactionThrowsWhenPragmaNeeded(): void
    {
        $this->connection->statement('PRAGMA foreign_keys = ON');

        $this->connection->beginTransaction();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains(
            'Cannot rebuild [users] inside a transaction: the foreign_keys PRAGMA toggle is a no-op',
        );

        $this->connection->modifyColumn(
            (new Blueprint('users'))
                ->id()
                ->string('name', 255)
                ->column(\BlueprintAU\Radiant\Database\Schema\Enums\ColumnType::BigInt, 'team_id', nullable: true)
                ->foreignKey(['team_id'], 'teams', ['id']),
        );
    }

    /**
     * A rebuild whose copied rows violate foreign keys rolls the WHOLE
     * rebuild back — the table is untouched, never silently corrupted.
     */
    public function testRebuildRollsBackOnForeignKeyViolation(): void
    {
        // The orphan can only exist while enforcement is OFF.
        $this->connection->statement('PRAGMA foreign_keys = OFF');
        $this->connection->statement(
            'CREATE TABLE orders (id INTEGER PRIMARY KEY AUTOINCREMENT,'
            . ' user_id INTEGER, note TEXT, FOREIGN KEY (user_id) REFERENCES users (id))',
        );
        $this->connection->statement('INSERT INTO orders (user_id, note) VALUES (99, \'orphan\')');
        $this->connection->statement('PRAGMA foreign_keys = ON');

        try {
            $this->connection->modifyColumn(
                (new Blueprint('orders'))
                    ->id()
                    ->column(\BlueprintAU\Radiant\Database\Schema\Enums\ColumnType::BigInt, 'user_id', nullable: true)
                    ->string('note', 255)
                    ->foreignKey(['user_id'], 'users', ['id']),
            );
            self::fail('The rebuild must refuse to produce orphaned rows.');
        } catch (\LogicException $e) {
            self::assertStringContainsString(
                'Rebuilding [orders] would violate foreign keys: 1 row(s) reference missing parents.',
                $e->getMessage(),
            );
        }

        // The rollback restored the ORIGINAL table — orphan row included.
        $rows = $this->connection->selectSql('SELECT note FROM orders');
        self::assertCount(1, $rows);
        $first = $rows->first();
        self::assertNotNull($first);
        self::assertSame('orphan', $first->note);

        // The PRAGMA was toggled OUTSIDE the transaction — restored even on
        // the failure path.
        $pragma = $this->connection->selectSql('PRAGMA foreign_keys');
        $pragmaRow = $pragma->first();
        self::assertNotNull($pragmaRow);
        self::assertSame(1, $pragmaRow->{'foreign_keys'});
    }

    /**
     * A successful rebuild under enforcement restores the PRAGMA — the
     * toggle never leaks.
     */
    public function testSuccessfulRebuildRestoresPragma(): void
    {
        $this->connection->statement('PRAGMA foreign_keys = ON');

        $this->connection->modifyColumn(
            (new Blueprint('users'))
                ->id()
                ->string('name', 255)
                ->column(\BlueprintAU\Radiant\Database\Schema\Enums\ColumnType::BigInt, 'team_id', nullable: true)
                ->foreignKey(['team_id'], 'teams', ['id']),
        );

        $rows = $this->connection->selectSql('SELECT name, team_id FROM users');
        self::assertCount(1, $rows);
        $first = $rows->first();
        self::assertNotNull($first);
        self::assertSame('Alice', $first->name);

        $pragma = $this->connection->selectSql('PRAGMA foreign_keys');
        $pragmaRow = $pragma->first();
        self::assertNotNull($pragmaRow);
        self::assertSame(1, $pragmaRow->{'foreign_keys'});
    }

    /**
     * A rebuild of a table with no FK involvement skips the PRAGMA toggle
     * entirely — the plain path stays plain.
     */
    public function testRebuildWithoutForeignKeysSkipsPragmaToggle(): void
    {
        $this->connection->statement('DROP TABLE users');
        $this->connection->statement(
            'CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)',
        );
        $this->connection->statement("INSERT INTO users (name) VALUES ('Alice')");

        $this->connection->modifyColumn(
            (new Blueprint('users'))->id()->string('name', 255),
        );

        $rows = $this->connection->selectSql('SELECT name FROM users');
        self::assertCount(1, $rows);
        $first = $rows->first();
        self::assertNotNull($first);
        self::assertSame('Alice', $first->name);
    }
}
