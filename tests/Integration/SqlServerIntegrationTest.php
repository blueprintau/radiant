<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Integration;

use BlueprintAU\Radiant\Database\Connections\SqlConnection;
use BlueprintAU\Radiant\Database\Connectors\ConnectorInterface;
use BlueprintAU\Radiant\Database\Connectors\MySqlConnector;
use BlueprintAU\Radiant\Database\Connectors\PostgresConnector;
use BlueprintAU\Radiant\Database\Exceptions\ConnectionException;
use BlueprintAU\Radiant\Database\Schema\Blueprint;
use BlueprintAU\Radiant\Database\Schema\ColumnType;
use BlueprintAU\Radiant\Database\Schema\SchemaOperation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Live integration tests against a real SQL server.
 *
 * These only run when a MySQL or Postgres server is reachable. Each test
 * connects *through the connector*, so the DSN construction, option merging
 * and post-connect SQL are all exercised — not just the PDO handshake. When
 * no server is reachable (e.g. a dev machine without one), the test is
 * skipped rather than failed.
 *
 * Env overrides (with local defaults):
 *  - RADIANT_MYSQL_{HOST,PORT,USER,PASSWORD,DATABASE} (127.0.0.1:3306 root/"" radiant)
 *  - RADIANT_PGSQL_{HOST,PORT,USER,PASSWORD,DATABASE} (127.0.0.1:5432 postgres/postgres radiant)
 */
final class SqlServerIntegrationTest extends TestCase
{
    /**
     * A live connection to the server, built by the connector.
     *
     * @var SqlConnection
     */
    private SqlConnection $connection;

    /**
     * Connect to a server through its connector, skipping when unreachable.
     *
     * Stores the connection for {@see tearDown()} cleanup. The connection is
     * built by the connector, so the DSN construction, option merging and
     * post-connect SQL are all exercised — not just the PDO handshake.
     *
     * @param array<string, mixed> $config The connection config.
     * @param ConnectorInterface $connector The connector under test.
     * @param string $driver The driver label for messages.
     */
    private function connect(array $config, ConnectorInterface $connector, string $driver): void
    {
        try {
            $connection = $connector->connect($config);
        } catch (ConnectionException $e) {
            self::markTestSkipped(sprintf('%s server not reachable (%s).', $driver, $e->getMessage()));
        }

        if (!$connection instanceof SqlConnection) {
            self::fail(sprintf('%s connector did not return an SqlConnection.', $driver));
        }
        $this->connection = $connection;

        $this->connection->statement('DROP TABLE IF EXISTS users');
    }

    /**
     * Drop the table after each test.
     */
    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->connection->statement('DROP TABLE IF EXISTS users');
        }
        parent::tearDown();
    }

    /**
     * The MySQL and Postgres config/connector/driver triples.
     *
     * @return iterable<string, array{0: array<string, mixed>, 1: ConnectorInterface, 2: string, 3: string}>
     */
    public static function configProvider(): iterable
    {
        $mysql = [
            'driver' => 'mysql',
            'host' => getenv('RADIANT_MYSQL_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('RADIANT_MYSQL_PORT') ?: '3306'),
            'database' => getenv('RADIANT_MYSQL_DATABASE') ?: 'radiant',
            'username' => getenv('RADIANT_MYSQL_USER') ?: 'root',
            'password' => getenv('RADIANT_MYSQL_PASSWORD') ?: '',
        ];
        yield 'mysql' => [$mysql, new MySqlConnector(), 'MySQL', 'MySQL requires a string host, an integer port and a string database.'];

        $postgres = [
            'driver' => 'pgsql',
            'host' => getenv('RADIANT_PGSQL_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('RADIANT_PGSQL_PORT') ?: '5432'),
            'database' => getenv('RADIANT_PGSQL_DATABASE') ?: 'radiant',
            'username' => getenv('RADIANT_PGSQL_USER') ?: 'postgres',
            'password' => getenv('RADIANT_PGSQL_PASSWORD') ?: 'postgres',
        ];
        yield 'postgres' => [$postgres, new PostgresConnector(), 'Postgres', 'Postgres requires a string host, an integer port and a string database.'];
    }

    /**
     * The connector returns the driver's concrete connection class.
     *
     * @param array<string, mixed> $config The connection config.
     * @param ConnectorInterface $connector The connector under test.
     * @param string $driver The human driver label.
     */
    #[DataProvider('configProvider')]
    public function testConnectorReturnsSqlConnection(array $config, ConnectorInterface $connector, string $driver): void
    {
        $this->connect($config, $connector, $driver);

        self::assertInstanceOf(SqlConnection::class, $this->connection);
    }

    /**
     * A full CRUD cycle against the live server.
     *
     * @param array<string, mixed> $config The connection config.
     * @param ConnectorInterface $connector The connector under test.
     * @param string $driver The human driver label.
     */
    #[DataProvider('configProvider')]
    public function testCrudCycle(array $config, ConnectorInterface $connector, string $driver): void
    {
        $this->connect($config, $connector, $driver);

        $this->connection->create('users', (new Blueprint())
            ->id()
            ->string('name', 100)
            ->column(ColumnType::Int, 'age'));

        $inserted = $this->connection->table('users')->insert([
            ['name' => 'Alice', 'age' => 30],
            ['name' => 'Bob', 'age' => 25],
        ]);
        self::assertSame(2, $inserted);

        $id = $this->connection->table('users')->insertIdColumn('id')->insertGetId(['name' => 'Carol', 'age' => 40]);
        self::assertNotNull($id);

        self::assertSame(3, $this->connection->table('users')->count());

        $updated = $this->connection->table('users')->where('name', '=', 'Bob')->update(['age' => 26]);
        self::assertSame(1, $updated);

        $row = $this->connection->table('users')->where('name', '=', 'Bob')->first();
        self::assertNotNull($row);
        self::assertSame(26, (int) $row->age);

        $deleted = $this->connection->table('users')->where('name', '=', 'Carol')->delete();
        self::assertSame(1, $deleted);
        self::assertSame(2, $this->connection->table('users')->count());
    }

    /**
     * Transactions commit on success and roll back on failure.
     *
     * @param array<string, mixed> $config The connection config.
     * @param ConnectorInterface $connector The connector under test.
     * @param string $driver The human driver label.
     */
    #[DataProvider('configProvider')]
    public function testTransactions(array $config, ConnectorInterface $connector, string $driver): void
    {
        $this->connect($config, $connector, $driver);

        $this->connection->create('users', (new Blueprint())
            ->id()
            ->string('name', 100));

        $this->connection->transaction(function () {
            $this->connection->table('users')->insert(['name' => 'Alice']);
            $this->connection->transaction(function () {
                $this->connection->table('users')->insert(['name' => 'Bob']);
            });
        });
        self::assertSame(2, $this->connection->table('users')->count());

        try {
            $this->connection->transaction(function () {
                $this->connection->table('users')->insert(['name' => 'Carol']);
                throw new \RuntimeException('boom');
            });
            self::fail('Expected an exception.');
        } catch (\RuntimeException) {
            // swallowed — the write should have rolled back.
        }

        self::assertSame(2, $this->connection->table('users')->count());
    }

    /**
     * Aggregates run against the live server.
     *
     * @param array<string, mixed> $config The connection config.
     * @param ConnectorInterface $connector The connector under test.
     * @param string $driver The human driver label.
     */
    #[DataProvider('configProvider')]
    public function testAggregates(array $config, ConnectorInterface $connector, string $driver): void
    {
        $this->connect($config, $connector, $driver);

        $this->connection->create('users', (new Blueprint())
            ->id()
            ->string('name', 100)
            ->column(ColumnType::Int, 'age'));

        $this->connection->table('users')->insert([
            ['name' => 'Alice', 'age' => 30],
            ['name' => 'Bob', 'age' => 25],
            ['name' => 'Carol', 'age' => 40],
        ]);

        self::assertSame(95, (int) $this->connection->table('users')->sum('age'));
        self::assertSame(40, (int) $this->connection->table('users')->max('age'));
        self::assertSame(25, (int) $this->connection->table('users')->min('age'));
    }

    /**
     * Schema alter (add/drop column) works on the live server.
     *
     * @param array<string, mixed> $config The connection config.
     * @param ConnectorInterface $connector The connector under test.
     * @param string $driver The human driver label.
     */
    #[DataProvider('configProvider')]
    public function testSchemaAlter(array $config, ConnectorInterface $connector, string $driver): void
    {
        $this->connect($config, $connector, $driver);

        $this->connection->create('users', (new Blueprint())
            ->id()
            ->string('name', 100));

        $this->connection->alter('users', SchemaOperation::AddColumn, (new Blueprint())->column(ColumnType::Int, 'age'));
        $this->connection->table('users')->insert(['name' => 'Alice', 'age' => 30]);
        $row = $this->connection->table('users')->where('name', '=', 'Alice')->first();
        self::assertNotNull($row);
        self::assertSame(30, (int) $row->age);

        $this->connection->alter('users', SchemaOperation::DropColumn, (new Blueprint())->dropColumn('age'));
        $row = $this->connection->table('users')->where('name', '=', 'Alice')->first();
        self::assertNotNull($row);
        self::assertObjectNotHasProperty('age', $row);
    }

    /**
     * A malformed config fails fast — either at validConfig() or connect().
     *
     * @param array<string, mixed> $config The connection config.
     * @param ConnectorInterface $connector The connector under test.
     * @param string $driver The human driver label.
     * @param string $expectedMessage A fragment of the thrown message.
     */
    #[DataProvider('configProvider')]
    public function testConnectInvalidConfigThrows(array $config, ConnectorInterface $connector, string $driver, string $expectedMessage): void
    {
        // The config values don't matter — the connector fails before
        // connecting, on the missing host/port/database.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);
        $connector->connect(['driver' => '']);
    }
}