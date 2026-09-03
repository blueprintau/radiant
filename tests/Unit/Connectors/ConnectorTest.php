<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Connectors;

use BlueprintAU\Radiant\Database\Connections\SqliteConnection;
use BlueprintAU\Radiant\Database\Connectors\CsvConnector;
use BlueprintAU\Radiant\Database\Connectors\MySqlConnector;
use BlueprintAU\Radiant\Database\Connectors\PostgresConnector;
use BlueprintAU\Radiant\Database\Connectors\SqliteConnector;
use BlueprintAU\Radiant\Database\Connections\CsvConnection;
use PHPUnit\Framework\TestCase;

/**
 * Tests for connector config validation and connection building.
 */
final class ConnectorTest extends TestCase
{
    /**
     * SQLite connector builds a SQLite connection from a valid config.
     */
    public function testSqliteConnector(): void
    {
        $config = ['driver' => 'sqlite', 'database' => ':memory:'];
        $connector = new SqliteConnector();
        $connector->validConfig($config);
        $connection = $connector->connect($config);

        self::assertInstanceOf(SqliteConnection::class, $connection);
    }

    /**
     * SQLite requires a database path string.
     */
    public function testSqliteRequiresDatabase(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('SQLite database must be a path string');
        (new SqliteConnector())->validConfig(['driver' => 'sqlite']);
    }

    /**
     * MySQL connector validates its required fields.
     */
    public function testMySqlValidConfig(): void
    {
        (new MySqlConnector())->validConfig([
            'driver' => 'mysql',
            'host' => 'localhost',
            'port' => 3306,
            'database' => 'app',
        ]);
        $this->addToAssertionCount(1);
    }

    /**
     * MySQL connector rejects a missing database.
     */
    public function testMySqlRequiresDatabase(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('MySQL requires a non-empty string "database"');
        (new MySqlConnector())->validConfig(['driver' => 'mysql', 'host' => 'localhost', 'port' => 3306]);
    }

    /**
     * Postgres connector requires its required fields.
     */
    public function testPostgresValidConfig(): void
    {
        (new PostgresConnector())->validConfig([
            'driver' => 'pgsql',
            'host' => 'localhost',
            'database' => 'app',
        ]);
        $this->addToAssertionCount(1);
    }

    /**
     * Postgres connector rejects a missing host.
     */
    public function testPostgresRequiresHost(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Postgres requires a non-empty string "host"');
        (new PostgresConnector())->validConfig(['driver' => 'pgsql', 'database' => 'app']);
    }

    /**
     * CSV connector requires a path.
     */
    public function testCsvValidConfig(): void
    {
        $connector = new CsvConnector();
        $connector->validConfig(['driver' => 'csv', 'path' => '/tmp/data.csv']);
        $connection = $connector->connect(['driver' => 'csv', 'path' => '/tmp/data.csv']);

        self::assertInstanceOf(CsvConnection::class, $connection);
    }

    /**
     * CSV connector rejects a missing path.
     */
    public function testCsvRequiresPath(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('CSV requires a "path" string');
        (new CsvConnector())->validConfig(['driver' => 'csv']);
    }
}