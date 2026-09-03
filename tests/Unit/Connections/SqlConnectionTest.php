<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Connections;

use BlueprintAU\Radiant\Database\Connections\SqliteConnection;
use BlueprintAU\Radiant\Database\Query\Enums\WhereOperator;
use BlueprintAU\Radiant\Database\Query\Expression;
use BlueprintAU\Radiant\Database\Query\QueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Live SQLite tests for {@see SqlConnection} — the portable primitives and
 * raw-SQL paths against a real database.
 */
final class SqlConnectionTest extends TestCase
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
        $this->connection->statement('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, email TEXT, age INTEGER, active INTEGER)');
    }

    /**
     * Insert returns the number of rows inserted.
     */
    public function testInsertReturnsCount(): void
    {
        $count = $this->connection
            ->table('users')
            ->insert(['name' => 'Alice', 'email' => 'a@example.com', 'age' => 30]);

        self::assertSame(1, $count);
    }

    /**
     * insertGetId with a declared PK column returns the generated id via
     * SQLite's RETURNING path.
     */
    public function testInsertGetIdReturning(): void
    {
        $id = $this->connection
            ->table('users')
            ->insertIdColumn('id')
            ->insertGetId(['name' => 'Alice', 'email' => 'a@example.com', 'age' => 30]);

        self::assertSame(1, $id);
    }

    /**
     * insertGetId without a declared PK column returns null.
     */
    public function testInsertGetIdWithoutPkColumnReturnsNull(): void
    {
        $id = $this->connection
            ->table('users')
            ->insertGetId(['name' => 'Alice', 'email' => 'a@example.com', 'age' => 30]);

        self::assertNull($id);
    }

    /**
     * Select with a where returns the matching rows.
     */
    public function testSelectWithWhere(): void
    {
        $this->connection->table('users')->insert(['name' => 'Alice', 'email' => 'a@example.com', 'age' => 30]);
        $this->connection->table('users')->insert(['name' => 'Bob', 'email' => 'b@example.com', 'age' => 25]);

        $rows = $this->connection
            ->table('users')
            ->where('age', WhereOperator::Gt, 26)
            ->get();

        self::assertCount(1, $rows);
        self::assertSame('Alice', $rows->first()->name);
    }

    /**
     * Update changes matching rows and returns the affected count.
     */
    public function testUpdateAffectedRows(): void
    {
        $this->connection->table('users')->insert(['name' => 'Alice', 'email' => 'a@example.com', 'age' => 30]);
        $this->connection->table('users')->insert(['name' => 'Bob', 'email' => 'b@example.com', 'age' => 25]);

        $affected = $this->connection
            ->table('users')
            ->where('age', WhereOperator::Eq, 25)
            ->update(['active' => 1]);

        self::assertSame(1, $affected);
        $row = $this->connection->table('users')->where('name', WhereOperator::Eq, 'Bob')->first();
        self::assertNotNull($row);
        self::assertSame(1, $row->active);
    }

    /**
     * Delete removes matching rows and returns the affected count.
     */
    public function testDeleteAffectedRows(): void
    {
        $this->connection->table('users')->insert(['name' => 'Alice', 'email' => 'a@example.com', 'age' => 30]);
        $this->connection->table('users')->insert(['name' => 'Bob', 'email' => 'b@example.com', 'age' => 25]);

        $affected = $this->connection->table('users')->where('age', WhereOperator::Eq, 25)->delete();

        self::assertSame(1, $affected);
        self::assertSame(1, $this->connection->table('users')->count());
        self::assertNull($this->connection->table('users')->where('age', WhereOperator::Eq, 25)->first());
    }

    // ---- Raw primitives ----

    /**
     * selectSql returns rows wrapped in a Collection.
     */
    public function testSelectSql(): void
    {
        $this->connection->table('users')->insert(['name' => 'Alice', 'email' => 'a@example.com', 'age' => 30]);

        $rows = $this->connection->selectSql('SELECT * FROM users WHERE name = ?', ['Alice']);

        self::assertCount(1, $rows);
        self::assertSame('Alice', $rows->first()->name);
    }

    /**
     * statement runs a statement with no result set.
     */
    public function testStatement(): void
    {
        $this->connection->statement('CREATE TABLE posts (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT)');
        self::assertSame(1, $this->connection->table('posts')->insert(['title' => 'Hello']));
    }

    /**
     * affectingStatement returns the affected-row count.
     */
    public function testAffectingStatement(): void
    {
        $this->connection->table('users')->insert(['name' => 'Alice', 'email' => 'a@example.com', 'age' => 30]);
        $affected = $this->connection->affectingStatement('UPDATE users SET active = 1');

        self::assertSame(1, $affected);
    }

    /**
     * The bind guard rejects a non-bindable value.
     */
    public function testBindGuardRejectsUnbindable(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Binding must be a scalar');
        $this->connection->selectSql('SELECT * FROM users WHERE id = ?', [new \stdClass()]);
    }

    /**
     * An Expression binding is inline and never bound.
     */
    public function testExpressionValueInlined(): void
    {
        $this->connection->table('users')->insert(['name' => 'Alice', 'email' => 'a@example.com', 'age' => 30]);

        $rows = $this->connection
            ->table('users')
            ->where('id', WhereOperator::Eq, new Expression('1'))
            ->get();

        self::assertCount(1, $rows);
    }

    /**
     * A DateTime binding is formatted through the codec.
     */
    public function testDateTimeBindingFormatted(): void
    {
        $this->connection->statement('ALTER TABLE users ADD COLUMN created_at DATETIME');
        $this->connection->table('users')->insert(['name' => 'Alice', 'email' => 'a@example.com', 'age' => 30]);

        $dt = new \DateTimeImmutable('2024-01-02 03:04:05', new \DateTimeZone('UTC'));
        $this->connection->table('users')->where('name', '=', 'Alice')->update(['created_at' => $dt]);

        $row = $this->connection->table('users')->where('name', '=', 'Alice')->first();
        self::assertNotNull($row);
        self::assertSame('2024-01-02 03:04:05', $row->created_at);
    }
}