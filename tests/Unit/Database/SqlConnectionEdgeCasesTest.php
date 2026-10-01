<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Database;

use BlueprintAU\Radiant\Database\Connections\CsvConnection;
use BlueprintAU\Radiant\Database\Connections\MySqlConnection;
use BlueprintAU\Radiant\Database\Connections\SqlConnection;
use BlueprintAU\Radiant\Database\Connections\SqliteConnection;
use BlueprintAU\Radiant\Database\Exceptions\QueryException;
use BlueprintAU\Radiant\Database\Exceptions\UnsupportedFeatureException;
use BlueprintAU\Radiant\Tests\Support\Expectation;
use BlueprintAU\Radiant\Database\Query\QueryBuilder;
use BlueprintAU\Radiant\Tests\Support\NullConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Exercise the SqlConnection edge arms — dialect narrowing, insertGetId
 * fallbacks, chunked cursoring, connection-loss staleness and teardown.
 */
final class SqlConnectionEdgeCasesTest extends TestCase
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
        $this->connection->statement('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
    }

    /**
     * assertSql() must reject a non-SQL connection.
     */
    public function testAssertSqlRejectsNonSqlConnection(): void
    {
        $this->expectException(UnsupportedFeatureException::class);
        $this->expectExceptionMessageIsOrContains('The connection is not a SQL connection.');

        /** @phpstan-ignore staticMethod.impossibleType (the negative path IS the test's subject) */
        SqlConnection::assertSql(new CsvConnection('/dev/null'));
    }

    /**
     * assertSql() must accept a SQL connection without throwing.
     */
    public function testAssertSqlAcceptsSqlConnection(): void
    {
        /** @phpstan-ignore staticMethod.impossibleType (the positive path IS the test's subject) */
        SqlConnection::assertSql($this->connection);

        self::assertFalse($this->connection->isStale());
    }

    /**
     * from() on a SQL connection of the WRONG dialect names the expected
     * dialect in the throw.
     */
    public function testFromRejectsWrongDialect(): void
    {
        $this->expectException(UnsupportedFeatureException::class);
        $this->expectExceptionMessageIsOrContains(
            'The connection is SQL, but not a BlueprintAU\Radiant\Database\Connections\MySqlConnection.',
        );

        MySqlConnection::from($this->connection);
    }

    /**
     * from() on a non-SQL connection gets the generic message.
     */
    public function testFromRejectsNonSqlConnection(): void
    {
        $this->expectException(UnsupportedFeatureException::class);
        $this->expectExceptionMessageIsOrContains('The connection is not a SQL connection.');

        MySqlConnection::from(new NullConnection());
    }

    /**
     * from() on the matching dialect returns the same instance, typed.
     */
    public function testFromReturnsMatchingDialect(): void
    {
        self::assertSame($this->connection, SqliteConnection::from($this->connection));
    }

    /**
     * insertGetId() on a RETURNING dialect whose statement yields no row
     * reports null — the row-missing arm.
     */
    public function testInsertGetIdReturnsNullWhenReturningYieldsNoRow(): void
    {
        $connection = new /** A SQL connection whose insert-for-id compiles to a rowless SELECT. */
        class (new \PDO('sqlite::memory:')) extends SqlConnection {
            /**
             * Swap in a grammar whose insert-for-id compiles to a rowless
             * SELECT with the RETURNING flag set.
             *
             * @return \BlueprintAU\Radiant\Database\Grammars\Grammar
             */
            protected function getDefaultQueryGrammar(): \BlueprintAU\Radiant\Database\Grammars\Grammar
            {
                return new /** A grammar that pretends to RETURN but selects nothing. */
                class () extends \BlueprintAU\Radiant\Database\Grammars\Grammar {
                    /**
                     * Claim the RETURNING capability so insertGetId() takes
                     * the select-the-key path.
                     *
                     * @return bool
                     */
                    protected function usesReturning(): bool
                    {
                        return true;
                    }

                    /**
                     * Compile the (empty-row) insert as a rowless SELECT —
                     * the "RETURNING yielded nothing" simulation.
                     *
                     * @param  QueryBuilder  $builder
                     * @return string
                     */
                    protected function compileEmptyInsert(QueryBuilder $builder): string
                    {
                        unset($builder);

                        return 'SELECT 1 AS id WHERE 0';
                    }

                    /**
                     * Suppress the RETURNING append — the rowless SELECT
                     * must stay rowless.
                     *
                     * @param  string  $sql
                     * @param  string|null  $pk
                     * @return string
                     */
                    protected function withReturning(string $sql, string|null $pk): string
                    {
                        unset($pk);

                        return $sql;
                    }

                    /**
                     * The identifier wrapper — plain double quotes.
                     *
                     * @param  string  $value
                     * @return string
                     */
                    protected function wrap(string $value): string
                    {
                        return '"' . str_replace('"', '""', $value) . '"';
                    }
                };
            }

            /**
             * The default schema grammar — sqlite's.
             *
             * @return \BlueprintAU\Radiant\Database\Schema\Grammars\SchemaGrammar
             */
            protected function getDefaultSchemaGrammar(): \BlueprintAU\Radiant\Database\Schema\Grammars\SchemaGrammar
            {
                return new \BlueprintAU\Radiant\Database\Schema\Grammars\SqliteSchemaGrammar();
            }

            /**
             * The default schema inspector — sqlite's.
             *
             * @return \BlueprintAU\Radiant\Database\Schema\Inspectors\SchemaInspector
             */
            protected function getDefaultSchemaInspector(): \BlueprintAU\Radiant\Database\Schema\Inspectors\SchemaInspector
            {
                return new \BlueprintAU\Radiant\Database\Schema\Inspectors\SqliteSchemaInspector(
                    new \PDO('sqlite::memory:'),
                );
            }

            /**
             * Savepoints are supported (sqlite semantics).
             *
             * @return bool
             */
            protected function supportsSavepoints(): bool
            {
                return true;
            }

            /**
             * Create a savepoint.
             *
             * @param  string  $name
             */
            protected function createSavepoint(string $name): void
            {
                $this->statement("SAVEPOINT {$name}");
            }

            /**
             * Release a savepoint.
             *
             * @param  string  $name
             */
            protected function releaseSavepoint(string $name): void
            {
                $this->statement("RELEASE SAVEPOINT {$name}");
            }

            /**
             * Roll back to a savepoint.
             *
             * @param  string  $name
             */
            protected function rollbackToSavepoint(string $name): void
            {
                $this->statement("ROLLBACK TO SAVEPOINT {$name}");
            }
        };

        $id = $connection->table('users')->insertIdColumn('id')->insertGetId([]);

        self::assertNull($id);
    }

    /**
     * insertGetId() on a non-RETURNING dialect whose lastInsertId() is
     * false reports null — the no-generated-id arm.
     *
     * The fallback lives on the lastInsertId() path, so the grammar must
     * claim NO RETURNING support (MySQL semantics) for it to run.
     */
    public function testInsertGetIdReturnsNullWhenLastInsertIdIsFalse(): void
    {
        $connection = new /** A SQL connection whose grammar never RETURNs. */
        class (new NoLastInsertIdPdo()) extends SqlConnection {
            /**
             * A grammar without RETURNING — the MySQL-shaped fallback path.
             *
             * @return \BlueprintAU\Radiant\Database\Grammars\Grammar
             */
            protected function getDefaultQueryGrammar(): \BlueprintAU\Radiant\Database\Grammars\Grammar
            {
                return new /** A grammar that reads the key back via lastInsertId(). */
                class () extends \BlueprintAU\Radiant\Database\Grammars\Grammar {
                    /**
                     * No RETURNING — insertGetId() falls through to
                     * lastInsertId().
                     *
                     * @return bool
                     */
                    protected function usesReturning(): bool
                    {
                        return false;
                    }

                    /**
                     * The identifier wrapper — plain double quotes.
                     *
                     * @param  string  $value
                     * @return string
                     */
                    protected function wrap(string $value): string
                    {
                        return '"' . str_replace('"', '""', $value) . '"';
                    }
                };
            }

            /**
             * The default schema grammar — sqlite's.
             *
             * @return \BlueprintAU\Radiant\Database\Schema\Grammars\SchemaGrammar
             */
            protected function getDefaultSchemaGrammar(): \BlueprintAU\Radiant\Database\Schema\Grammars\SchemaGrammar
            {
                return new \BlueprintAU\Radiant\Database\Schema\Grammars\SqliteSchemaGrammar();
            }

            /**
             * The default schema inspector — sqlite's.
             *
             * @return \BlueprintAU\Radiant\Database\Schema\Inspectors\SchemaInspector
             */
            protected function getDefaultSchemaInspector(): \BlueprintAU\Radiant\Database\Schema\Inspectors\SchemaInspector
            {
                return new \BlueprintAU\Radiant\Database\Schema\Inspectors\SqliteSchemaInspector(
                    new \PDO('sqlite::memory:'),
                );
            }

            /**
             * Savepoints are supported (sqlite semantics).
             *
             * @return bool
             */
            protected function supportsSavepoints(): bool
            {
                return true;
            }

            /**
             * Create a savepoint.
             *
             * @param  string  $name
             */
            protected function createSavepoint(string $name): void
            {
                $this->statement("SAVEPOINT {$name}");
            }

            /**
             * Release a savepoint.
             *
             * @param  string  $name
             */
            protected function releaseSavepoint(string $name): void
            {
                $this->statement("RELEASE SAVEPOINT {$name}");
            }

            /**
             * Roll back to a savepoint.
             *
             * @param  string  $name
             */
            protected function rollbackToSavepoint(string $name): void
            {
                $this->statement("ROLLBACK TO SAVEPOINT {$name}");
            }
        };

        $connection->statement('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');

        $id = $connection->table('users')->insertIdColumn('id')->insertGetId(['name' => 'Alice']);

        self::assertNull($id);
    }

    /**
     * insertGetId() with a caller-assigned non-auto-increment key fails
     * fast — lastInsertId() would be a stale id from an earlier insert.
     *
     * The guard lives on the lastInsertId() path, so the grammar must
     * claim NO RETURNING support (MySQL semantics) for it to fire.
     */
    public function testInsertGetIdRejectsNonAutoIncrementKey(): void
    {
        $connection = new /** A SQL connection whose grammar never RETURNs. */
        class (new \PDO('sqlite::memory:')) extends SqlConnection {
            /**
             * A grammar without RETURNING — the MySQL-shaped fallback path.
             *
             * @return \BlueprintAU\Radiant\Database\Grammars\Grammar
             */
            protected function getDefaultQueryGrammar(): \BlueprintAU\Radiant\Database\Grammars\Grammar
            {
                return new /** A grammar that reads the key back via lastInsertId(). */
                class () extends \BlueprintAU\Radiant\Database\Grammars\Grammar {
                    /**
                     * No RETURNING — insertGetId() falls through to
                     * lastInsertId(), where the non-auto-increment guard lives.
                     *
                     * @return bool
                     */
                    protected function usesReturning(): bool
                    {
                        return false;
                    }

                    /**
                     * The identifier wrapper — plain double quotes.
                     *
                     * @param  string  $value
                     * @return string
                     */
                    protected function wrap(string $value): string
                    {
                        return '"' . str_replace('"', '""', $value) . '"';
                    }
                };
            }

            /**
             * The default schema grammar — sqlite's.
             *
             * @return \BlueprintAU\Radiant\Database\Schema\Grammars\SchemaGrammar
             */
            protected function getDefaultSchemaGrammar(): \BlueprintAU\Radiant\Database\Schema\Grammars\SchemaGrammar
            {
                return new \BlueprintAU\Radiant\Database\Schema\Grammars\SqliteSchemaGrammar();
            }

            /**
             * The default schema inspector — sqlite's.
             *
             * @return \BlueprintAU\Radiant\Database\Schema\Inspectors\SchemaInspector
             */
            protected function getDefaultSchemaInspector(): \BlueprintAU\Radiant\Database\Schema\Inspectors\SchemaInspector
            {
                return new \BlueprintAU\Radiant\Database\Schema\Inspectors\SqliteSchemaInspector(
                    new \PDO('sqlite::memory:'),
                );
            }

            /**
             * Savepoints are supported (sqlite semantics).
             *
             * @return bool
             */
            protected function supportsSavepoints(): bool
            {
                return true;
            }

            /**
             * Create a savepoint.
             *
             * @param  string  $name
             */
            protected function createSavepoint(string $name): void
            {
                $this->statement("SAVEPOINT {$name}");
            }

            /**
             * Release a savepoint.
             *
             * @param  string  $name
             */
            protected function releaseSavepoint(string $name): void
            {
                $this->statement("RELEASE SAVEPOINT {$name}");
            }

            /**
             * Roll back to a savepoint.
             *
             * @param  string  $name
             */
            protected function rollbackToSavepoint(string $name): void
            {
                $this->statement("ROLLBACK TO SAVEPOINT {$name}");
            }
        };

        $connection->statement('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('is not auto-increment');

        $connection
            ->table('users')
            ->insertIdColumn('id', autoIncrement: false)
            ->insertGetId(['id' => 5, 'name' => 'Alice']);
    }

    /**
     * chunkSql() rejects a non-positive chunk size.
     *
     * @param int $size The invalid size.
     */
    #[DataProvider('invalidChunkSizeProvider')]
    public function testChunkSqlRejectsInvalidSize(int $size): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains("Chunk size must be at least 1; got {$size}.");

        $this->connection->chunkSql('SELECT * FROM users', [], $size, static fn (): bool => true);
    }

    /**
     * The invalid chunk sizes.
     *
     * @return iterable<string, array{0: int}> The sizes.
     */
    public static function invalidChunkSizeProvider(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-3];
    }

    /**
     * chunkSql() hands the callback fixed-size chunks, then the trailing
     * partial chunk.
     */
    public function testChunkSqlDeliversFixedChunksThenPartial(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->connection->statement("INSERT INTO users (name) VALUES ('u{$i}')");
        }

        $sizes = [];
        $this->connection->chunkSql(
            'SELECT * FROM users ORDER BY id',
            [],
            2,
            function (array $chunk) use (&$sizes): bool {
                $sizes[] = count($chunk);

                return true;
            },
        );

        self::assertSame([2, 2, 1], $sizes);
    }

    /**
     * chunkSql() stops immediately when the callback returns strict false.
     */
    public function testChunkSqlStopsOnStrictFalse(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->connection->statement("INSERT INTO users (name) VALUES ('u{$i}')");
        }

        $sizes = [];
        $this->connection->chunkSql(
            'SELECT * FROM users ORDER BY id',
            [],
            2,
            function (array $chunk) use (&$sizes): bool {
                $sizes[] = count($chunk);

                return false;
            },
        );

        self::assertSame([2], $sizes, 'the strict-false return must stop after the first chunk');
    }

    /**
     * chunkSql() propagates a failing statement as a QueryException.
     */
    public function testChunkSqlWrapsQueryFailure(): void
    {
        $this->expectException(QueryException::class);

        $this->connection->chunkSql('SELECT * FROM no_such_table', [], 2, static fn (): bool => true);
    }

    /**
     * A connection-loss PDOException on the cursor path marks the
     * connection stale — the prepareAndExecute twin of the run() arm.
     */
    public function testConnectionLossOnCursorPathMarksStale(): void
    {
        $broken = new FailingRollbackPdo('server has gone away');

        $property = new \ReflectionProperty(SqlConnection::class, 'pdo');
        $property->setValue($this->connection, $broken);

        self::assertFalse($this->connection->isStale());

        Expectation::throws(
            fn () => $this->connection->chunkSql('SELECT * FROM users', [], 2, static fn (): bool => true),
            QueryException::class,
        );

        self::assertTrue($this->connection->isStale(), 'the connection-loss shape must mark the connection stale');
    }

    /**
     * A destructor over an abandoned transaction whose PDO cannot roll
     * back must swallow the failure — teardown is best-effort.
     */
    #[\PHPUnit\Framework\Attributes\DoesNotPerformAssertions]
    public function testDestructorSwallowsFailedRollback(): void
    {
        $connection = new SqliteConnection(new FailingRollbackPdo('server has gone away'));
        $connection->beginTransaction();

        // The destructor runs when $connection goes out of scope; the
        // FailingRollbackPdo's rollBack() throws and the catch must absorb
        // it (no uncatchable teardown error escaping the test).
        unset($connection);
    }
}

/**
 * A PDO stand-in whose lastInsertId() always reports false — the
 * "no generated id" simulation.
 */
final class NoLastInsertIdPdo extends \PDO
{
    /**
     * Initialize the parent with a real (unused) connection.
     */
    public function __construct()
    {
        parent::__construct('sqlite::memory:');
    }

    /**
     * Always report no generated id.
     *
     * @param  string|null  $name  The sequence name (unused).
     * @return string|false Always false.
     */
    #[\Override]
    public function lastInsertId(string|null $name = null): string|false
    {
        unset($name);

        return false;
    }
}

/**
 * A PDO stand-in whose rollBack() always throws a connection-loss-shaped
 * PDOException — the "dead connection at teardown" simulation.
 */
final class FailingRollbackPdo extends \PDO
{
    /**
     * The message every failing call raises.
     *
     * @var string
     */
    private string $failureMessage;

    /**
     * @param string $failureMessage The failure message fragment.
     */
    public function __construct(string $failureMessage)
    {
        parent::__construct('sqlite::memory:');
        $this->failureMessage = $failureMessage;
    }

    /**
     * Always throws a connection-loss-shaped PDOException.
     *
     * @param  string  $query  The SQL (never executed).
     * @param  array<int, mixed>  $options  Unused driver options.
     * @return \PDOStatement|false Never returns.
     * @throws \PDOException Always.
     */
    #[\Override]
    public function prepare(string $query, array $options = []): \PDOStatement|false
    {
        unset($query, $options);

        throw $this->failure();
    }

    /**
     * Always throws a connection-loss-shaped PDOException.
     *
     * @return bool Never returns.
     * @throws \PDOException Always.
     */
    #[\Override]
    public function rollBack(): bool
    {
        throw $this->failure();
    }

    /**
     * A PDOException with a connection-loss SQLSTATE and message.
     *
     * @return \PDOException The exception to throw.
     */
    private function failure(): \PDOException
    {
        $message = $this->failureMessage;
        $e = new \PDOException($message);
        $e->errorInfo = ['08006', 0, $message];

        return $e;
    }
}
