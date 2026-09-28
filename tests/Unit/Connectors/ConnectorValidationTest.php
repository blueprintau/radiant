<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Connectors;

use BlueprintAU\Radiant\Database\Connectors\CsvConnector;
use BlueprintAU\Radiant\Database\Connectors\MySqlConnector;
use BlueprintAU\Radiant\Database\Connectors\PostgresConnector;
use BlueprintAU\Radiant\Database\Connectors\SqliteConnector;
use BlueprintAU\Radiant\Database\Exceptions\ConnectionException;
use PHPUnit\Framework\TestCase;

/**
 * Connector validation — the fail-fast config gates exercised WITHOUT a
 * live server: PDO-options validation, DSN metacharacter rejection,
 * per-driver field validation, and the ConnectionException wrap when PDO
 * construction fails (a connection-refused port reaches createPdo and
 * throws without any server running).
 */
final class ConnectorValidationTest extends TestCase
{
    // ---- SqlConnector: shared PDO-options validation ----

    /**
     * validConfig() delegates the `options` key to validateOptions() — a
     * non-array value rejects before any connection attempt.
     */
    public function testOptionsMustBeArray(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('PDO options must be an array of attributes; got string.');

        (new SqliteConnector())->validConfig([
            'database' => ':memory:',
            'options' => 'not-an-array',
        ]);
    }

    /**
     * PDO option keys must be integer attribute constants.
     */
    public function testOptionKeysMustBeIntegers(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'PDO option keys must be integer attribute constants (PDO::ATTR_*); got string.',
        );

        (new SqliteConnector())->validConfig([
            'database' => ':memory:',
            'options' => ['ATTR_TIMEOUT' => 5],
        ]);
    }

    /**
     * PDO option values must be scalar or array.
     */
    public function testOptionValuesMustBeScalarOrArray(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'PDO option values must be a scalar or array; got stdClass for option 3.',
        );

        (new SqliteConnector())->validConfig([
            'database' => ':memory:',
            'options' => [3 => new \stdClass()],
        ]);
    }

    /**
     * A well-formed options array passes validation.
     */
    public function testWellFormedOptionsPass(): void
    {
        (new SqliteConnector())->validConfig([
            'database' => ':memory:',
            'options' => [\PDO::ATTR_TIMEOUT => 5],
        ]);

        // No exception is the assertion.
        $this->addToAssertionCount(1);
    }

    /**
     * DSN-metacharacter rejection: a host carrying a `;` or whitespace
     * would re-bind the DSN's key-value parsing — rejected at validation.
     */
    public function testPostgresHostWithMetacharactersThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'The "host" config field must not contain semicolons, whitespace '
            . 'or control characters (they are DSN metacharacters); got a value that does.',
        );

        (new PostgresConnector())->validConfig([
            'host' => '127.0.0.1;sslmode=disable',
            'database' => 'radiant',
        ]);
    }

    /**
     * The same metacharacter gate applies to Postgres database names.
     */
    public function testPostgresDatabaseWithMetacharactersThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'The "database" config field must not contain semicolons, whitespace '
            . 'or control characters (they are DSN metacharacters); got a value that does.',
        );

        (new PostgresConnector())->validConfig([
            'host' => '127.0.0.1',
            'database' => 'radiant extra',
        ]);
    }

    /**
     * MySQL host metacharacters reject identically.
     */
    public function testMySqlHostWithMetacharactersThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'The "host" config field must not contain semicolons, whitespace '
            . 'or control characters (they are DSN metacharacters); got a value that does.',
        );

        (new MySqlConnector())->validConfig([
            'host' => '127.0.0.1;unix_socket=/tmp/evil',
            'port' => 3306,
            'database' => 'radiant',
        ]);
    }

    // ---- PostgresConnector ----

    /**
     * Postgres sslmode is allowlisted case-insensitively; an unknown mode
     * rejects.
     */
    public function testPostgresUnknownSslmodeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'Postgres "sslmode" must be one of: disable, allow, prefer, require, '
            . 'verify-ca, verify-full; got [bogus].',
        );

        (new PostgresConnector())->validConfig([
            'host' => '127.0.0.1',
            'database' => 'radiant',
            'sslmode' => 'bogus',
        ]);
    }

    /**
     * A non-string sslmode rejects with the debug-type wording.
     */
    public function testPostgresNonStringSslmodeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'Postgres "sslmode" must be one of: disable, allow, prefer, require, '
            . 'verify-ca, verify-full; got int.',
        );

        (new PostgresConnector())->validConfig([
            'host' => '127.0.0.1',
            'database' => 'radiant',
            'sslmode' => 5,
        ]);
    }

    /**
     * Every allowlisted sslmode passes validation (case normalized inside).
     */
    public function testPostgresAllowedSslmodesPass(): void
    {
        foreach (['disable', 'REQUIRE', 'Verify-Full', 'prefer', 'allow', 'verify-ca'] as $sslmode) {
            (new PostgresConnector())->validConfig([
                'host' => '127.0.0.1',
                'database' => 'radiant',
                'sslmode' => $sslmode,
            ]);
        }

        // No exception is the assertion.
        $this->addToAssertionCount(1);
    }

    /**
     * A non-integer port rejects when present.
     */
    public function testPostgresNonIntPortThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Postgres "port" must be an integer; got string.');

        (new PostgresConnector())->validConfig([
            'host' => '127.0.0.1',
            'port' => '5432',
            'database' => 'radiant',
        ]);
    }

    /**
     * A non-string host rejects at validation.
     */
    public function testPostgresNonStringHostThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Postgres requires a non-empty string "host"; got int.');

        (new PostgresConnector())->validConfig([
            'host' => 127001,
            'database' => 'radiant',
        ]);
    }

    // ---- MySqlConnector ----

    /**
     * A non-integer port rejects at validation.
     */
    public function testMySqlNonIntPortThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('MySQL requires an integer "port"; got string.');

        (new MySqlConnector())->validConfig([
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => 'radiant',
        ]);
    }

    /**
     * A missing port rejects at validation — the field is required for
     * MySQL (unlike Postgres, where it defaults to 5432).
     */
    public function testMySqlMissingPortThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('MySQL requires an integer "port"; got nothing.');

        (new MySqlConnector())->validConfig([
            'host' => '127.0.0.1',
            'database' => 'radiant',
        ]);
    }

    // ---- SqliteConnector ----

    /**
     * The SQLite database path must be a non-empty string.
     */
    public function testSqliteNonStringDatabaseThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('SQLite database must be a non-empty path string; got int.');

        (new SqliteConnector())->validConfig(['database' => 42]);
    }

    /**
     * An empty SQLite path rejects.
     */
    public function testSqliteEmptyDatabaseThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('SQLite database must be a non-empty path string; got string.');

        (new SqliteConnector())->validConfig(['database' => '']);
    }

    /**
     * A missing SQLite path rejects with the "nothing" wording.
     */
    public function testSqliteMissingDatabaseThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('SQLite database must be a non-empty path string; got nothing.');

        (new SqliteConnector())->validConfig([]);
    }

    // ---- CsvConnector ----

    /**
     * The CSV path must be a non-empty string.
     */
    public function testCsvNonStringPathThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('CSV requires a non-empty "path" string; got int.');

        (new CsvConnector())->validConfig(['path' => 42]);
    }

    /**
     * A missing CSV path rejects with the "nothing" wording.
     */
    public function testCsvMissingPathThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('CSV requires a non-empty "path" string; got nothing.');

        (new CsvConnector())->validConfig([]);
    }

    /**
     * A non-boolean readonly flag rejects.
     */
    public function testCsvNonBoolReadonlyThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('CSV requires a "readonly" boolean; got string.');

        (new CsvConnector())->validConfig(['path' => '/tmp/x.csv', 'readonly' => 'yes']);
    }

    /**
     * A well-formed readonly flag and path pass validation.
     */
    public function testCsvValidConfigPasses(): void
    {
        (new CsvConnector())->validConfig(['path' => '/tmp/x.csv', 'readonly' => true]);

        // No exception is the assertion.
        $this->addToAssertionCount(1);
    }

    // ---- ConnectionException wrap (no server needed) ----

    /**
     * A connection-refused port reaches createPdo() and surfaces as the
     * library's ConnectionException wrapping the PDOException — no server
     * required.
     */
    public function testConnectionRefusalSurfacesAsConnectionException(): void
    {
        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessageIsOrContains('Could not connect to database.');

        (new PostgresConnector())->connect([
            'host' => '127.0.0.1',
            'port' => 1, // nothing listens here; refusal is immediate
            'database' => 'radiant',
        ]);
    }
}
