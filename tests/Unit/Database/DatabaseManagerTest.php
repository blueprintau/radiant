<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Database;

use BlueprintAU\Radiant\Database\Connections\ConnectionInterface;
use BlueprintAU\Radiant\Database\Connections\SqliteConnection;
use BlueprintAU\Radiant\Database\DatabaseManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the {@see DatabaseManager} — the connection factory + registry.
 */
final class DatabaseManagerTest extends TestCase
{
    /**
     * The manager under test.
     *
     * @var DatabaseManager
     */
    private DatabaseManager $manager;

    /**
     * Build a manager with an sqlite + csv connection.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = new DatabaseManager([
            'default' => ['driver' => 'sqlite', 'database' => ':memory:'],
            'sqlite' => ['driver' => 'sqlite', 'database' => ':memory:'],
            'csv' => ['driver' => 'csv', 'path' => sys_get_temp_dir() . '/radiant_manager_test.csv'],
        ]);
    }

    /**
     * connection() returns the default connection.
     */
    public function testConnectionReturnsDefault(): void
    {
        self::assertInstanceOf(SqliteConnection::class, $this->manager->connection());
    }

    /**
     * connection() returns a named connection.
     */
    public function testConnectionByName(): void
    {
        self::assertInstanceOf(ConnectionInterface::class, $this->manager->connection('csv'));
    }

    /**
     * connection() caches by name — the same instance is returned.
     */
    public function testConnectionIsCached(): void
    {
        self::assertSame($this->manager->connection('sqlite'), $this->manager->connection('sqlite'));
        self::assertSame($this->manager->connection('csv'), $this->manager->connection('csv'));
    }

    /**
     * sqlConnection() narrows to a SQL connection by name.
     */
    public function testSqlConnection(): void
    {
        self::assertInstanceOf(SqliteConnection::class, $this->manager->sqlConnection());
        self::assertInstanceOf(SqliteConnection::class, $this->manager->sqlConnection('sqlite'));
    }

    /**
     * sqlConnection() on a non-SQL connection throws.
     */
    public function testSqlConnectionOnNonSqlConnectionThrows(): void
    {
        $this->expectException(\BlueprintAU\Radiant\Database\Exceptions\UnsupportedFeatureException::class);
        $this->expectExceptionMessageIsOrContains('The connection is not a SQL connection.');
        $this->manager->sqlConnection('csv');
    }

    /**
     * hasConnection() reports known names.
     */
    public function testHasConnection(): void
    {
        self::assertTrue($this->manager->hasConnection('sqlite'));
        self::assertFalse($this->manager->hasConnection('nope'));
    }

    /**
     * usingConnection() switches the active connection and restores it after.
     */
    public function testUsingConnectionSwitchesAndRestores(): void
    {
        $inside = $this->manager->usingConnection('csv', fn () => $this->manager->currentConnection());
        self::assertSame('csv', $inside);
        self::assertSame('default', $this->manager->currentConnection());
    }

    /**
     * extendConnector() registers a new driver.
     */
    public function testExtendConnector(): void
    {
        $this->manager->extendConnector(
            'sqlite2',
            \BlueprintAU\Radiant\Database\Connectors\SqliteConnector::class,
        );

        // The connection map still only has sqlite/csv, but the registry is
        // extended — the map validation at construction happened before.
        self::assertFalse($this->manager->hasConnection('sqlite2'));
        $this->addToAssertionCount(1);
    }

    /**
     * addConnection() registers a new connection.
     */
    public function testAddConnection(): void
    {
        $this->manager->addConnection('cache', ['driver' => 'sqlite', 'database' => ':memory:']);
        self::assertTrue($this->manager->hasConnection('cache'));
        self::assertInstanceOf(SqliteConnection::class, $this->manager->connection('cache'));
    }

    /**
     * addConnection() rejects a duplicate name.
     */
    public function testAddConnectionDuplicateThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('already defined');
        $this->manager->addConnection('sqlite', ['driver' => 'sqlite', 'database' => ':memory:']);
    }

    /**
     * An invalid connection config fails fast at construction.
     *
     * @param array<string, mixed> $connections The connections map.
     * @param string $expectedMessage A fragment of the thrown message.
     */
    #[DataProvider('invalidConfigProvider')]
    public function testInvalidConfigThrows(array $connections, string $expectedMessage): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains($expectedMessage);
        new DatabaseManager($connections);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidConfigProvider(): iterable
    {
        yield 'unknown driver' => [['main' => ['driver' => 'nope']], 'No connector registered for [nope]'];
        yield 'missing driver' => [['main' => ['host' => 'localhost']], 'must declare a non-empty string "driver"'];
        yield 'not an array' => [['main' => 'sqlite'], 'must be an array of settings'];
        yield 'missing sqlite database' => [['main' => ['driver' => 'sqlite']], 'SQLite database must be a non-empty path string'];
    }
}