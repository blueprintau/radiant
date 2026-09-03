<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit;

use BlueprintAU\Radiant\Database;
use BlueprintAU\Radiant\Database\Connections\CsvConnection;
use BlueprintAU\Radiant\Database\Connections\SqliteConnection;
use BlueprintAU\Radiant\Database\DatabaseManager;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the {@see Database} static facade.
 */
final class DatabaseFacadeTest extends TestCase
{
    /**
     * The manager injected into the facade.
     *
     * @var DatabaseManager
     */
    private DatabaseManager $manager;

    /**
     * Build a manager and inject it into the facade.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = new DatabaseManager([
            'default' => ['driver' => 'sqlite', 'database' => ':memory:'],
            'sqlite' => ['driver' => 'sqlite', 'database' => ':memory:'],
            'csv' => ['driver' => 'csv', 'path' => sys_get_temp_dir() . '/radiant_facade_test.csv'],
        ]);
        Database::setManager($this->manager);
    }

    /**
     * Reset the static manager between tests.
     */
    protected function tearDown(): void
    {
        Database::setManager(new DatabaseManager(['default' => ['driver' => 'sqlite', 'database' => ':memory:']]));
        parent::tearDown();
    }

    /**
     * connection() delegates to the manager.
     */
    public function testConnection(): void
    {
        self::assertInstanceOf(SqliteConnection::class, Database::connection());
        self::assertInstanceOf(SqliteConnection::class, Database::connection('sqlite'));
        self::assertInstanceOf(CsvConnection::class, Database::connection('csv'));
    }

    /**
     * table() returns a builder bound to the default connection.
     */
    public function testTable(): void
    {
        Database::statement('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
        Database::table('users')->insert(['name' => 'Alice']);
        self::assertSame(1, Database::table('users')->count());
    }

    /**
     * select() runs raw SQL through the active SQL connection.
     */
    public function testSelect(): void
    {
        Database::statement('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
        Database::table('users')->insert(['name' => 'Alice']);

        $rows = Database::select('select * from users');
        self::assertCount(1, $rows);
        self::assertSame('Alice', $rows->first()->name);
    }

    /**
     * statement()/affectingStatement() run raw DDL/DML.
     */
    public function testStatements(): void
    {
        Database::statement('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
        $affected = Database::affectingStatement('insert into users (name) values (?)', ['Bob']);

        self::assertSame(1, $affected);
        self::assertSame(1, Database::table('users')->count());
    }

    /**
     * usingConnection() switches the active connection for the callback.
     */
    public function testUsingConnection(): void
    {
        $inside = Database::usingConnection(
            'csv',
            fn () => Database::connection()::class,
        );
        self::assertSame(CsvConnection::class, $inside);
        self::assertInstanceOf(SqliteConnection::class, Database::connection());
    }

    /**
     * SQL operations on a non-SQL connection throw.
     */
    public function testSelectOnNonSqlConnectionThrows(): void
    {
        $this->expectException(\BlueprintAU\Radiant\Database\Exceptions\UnsupportedFeatureException::class);
        $this->expectExceptionMessage('The active connection is not a SQL connection.');
        Database::usingConnection('csv', fn () => Database::select('select * from users'));
    }
}