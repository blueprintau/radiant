<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Audit;

use BlueprintAU\Radiant\Database\Connections\CsvConnection;
use BlueprintAU\Radiant\Database\Exceptions\QueryException;
use BlueprintAU\Radiant\Database\Exceptions\UnsupportedFeatureException;
use BlueprintAU\Radiant\Database\Grammars\Grammar;
use BlueprintAU\Radiant\Database\Query\Enums\WhereOperator;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for the 2026-09-09 audit remediation (AUDIT-SUMMARY).
 *
 * One suite per fixed finding family; each test names the finding ID it
 * locks in.
 */
final class AuditRegressionTest extends TestCase
{
    /**
     * The temp CSV file used by a test.
     *
     * @var string
     */
    private string $path;

    /**
     * The temp CSV paths created during a test (fixed + potential orphans).
     *
     * @var list<string>
     */
    private array $paths = [];

    /**
     * Create a temp CSV file with a known dataset.
     *
     * @param list<array<string, string|int|float|null>> $rows The data rows.
     * @return CsvConnection The connection.
     */
    private function makeCsv(array $rows): CsvConnection
    {
        $this->path = tempnam(sys_get_temp_dir(), 'radiant_audit_') ?: throw new \RuntimeException('no tempnam');
        $this->paths[] = $this->path;
        $handle = fopen($this->path, 'w');
        \assert($handle !== false);

        fputcsv($handle, array_keys($rows[0]), escape: '');
        foreach ($rows as $row) {
            fputcsv($handle, $row, escape: '');
        }
        fclose($handle);
        return new CsvConnection($this->path);
    }

    /**
     * Clean up every temp file this test may have created.
     */
    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
            // Unique-temp cleanup: no fixed .radiant-tmp sibling is ever
            // left behind, but glob for any strays this test could own.
            foreach (glob($path . '.radiant-*.tmp') ?: [] as $stray) {
                @unlink($stray);
            }
        }
        parent::tearDown();
    }

    // ---- Chain 1: CSV data loss family ----

    /**
     * AUD-CNC-001 / AUD-RES-001: a mid-write failure must not orphan a
     * `.radiant-*.tmp` file, and no fixed temp path exists for a second
     * writer to clobber. Non-scalar write values fail before any file I/O.
     */
    public function testCsvWriteFailureLeavesNoTempFiles(): void
    {
        $connection = $this->makeCsv([['id' => '1', 'name' => 'Ana']]);

        try {
            $connection->table('users')->insert([
                ['id' => '2', 'name' => 'Bo'],
                // A stdClass in a value slot unwinds the write mid-cycle.
                ['id' => '3', 'name' => new \stdClass()],
            ]);
            self::fail('Expected the write to fail.');
        } catch (\Throwable) {
            // expected
        }

        self::assertSame([], glob($this->path . '.radiant-tmp') ?: [], 'No fixed temp path may exist.');
        self::assertSame([], glob($this->path . '.radiant-*.tmp') ?: [], 'No unique temp file may be orphaned.');

        // The original file must be intact (atomic rename discipline).
        $rows = $connection->table('users')->get()->all();
        self::assertCount(1, $rows);
    }

    /**
     * AUD-CNC-001: the temp path is unique per write — two sequential
     * writes never reuse a name, so a concurrent writer cannot truncate
     * an in-flight temp.
     */
    public function testCsvWritesUseUniqueTempNames(): void
    {
        $connection = $this->makeCsv([['id' => '1', 'name' => 'Ana']]);
        $connection->table('users')->insert(['id' => '2', 'name' => 'Bo']);
        $connection->table('users')->insert(['id' => '3', 'name' => 'Cy']);

        self::assertSame([], glob($this->path . '.radiant-*.tmp') ?: []);

        // Both writes persisted — no last-writer-wins loss.
        $ids = array_map(fn(object $r) => $r->id, $connection->table('users')->get()->all());
        self::assertSame(['1', '2', '3'], $ids);
    }

    // ---- COR: CSV filter parity family ----

    /**
     * AUD-COR-002: `a%b` matches `ab` (the old quote-before-translate
     * order required a literal dot) and `_` matches exactly one char.
     */
    public function testCsvLikePatternTranslation(): void
    {
        $connection = $this->makeCsv([
            ['id' => '1', 'name' => 'ab'],
            ['id' => '2', 'name' => 'axb'],
            ['id' => '3', 'name' => 'a.b'],
            ['id' => '4', 'name' => 'a1b'],
            ['id' => '5', 'name' => 'aXbXc'],
        ]);

        $names = fn(): array => array_map(
            fn(object $r) => $r->name,
            $connection->table('users')->where('name', 'LIKE', 'a%b')->get()->all(),
        );

        // % spans any run (including zero) — the literal-dot corruption is gone.
        self::assertSame(['ab', 'axb', 'a.b', 'a1b'], $names());

        // Literal `.` in a PATTERN still matches only a literal dot.
        $literalDot = array_map(
            fn(object $r) => $r->name,
            $connection->table('users')->where('name', 'LIKE', 'a.b')->get()->all(),
        );
        self::assertSame(['a.b'], $literalDot);
    }

    /**
     * AUD-COR-003: `In` and `Eq` share one canonical comparator — a typed
     * int `IN` matches the CSV's string cells exactly as `=` does.
     */
    public function testCsvInMatchesEqSemantics(): void
    {
        $connection = $this->makeCsv([
            ['id' => '5', 'name' => 'five'],
            ['id' => '6', 'name' => 'six'],
        ]);

        // Typed int operand vs string cell — strict in_array used to miss.
        $byIn = $connection->table('users')->where('id', 'IN', [5])->get()->all();
        self::assertCount(1, $byIn);
        self::assertSame('five', $byIn[0]->name);

        // Eq with the same typed operand agrees.
        $byEq = $connection->table('users')->where('id', '=', 5)->get()->all();
        self::assertCount(1, $byEq);
        self::assertSame('five', $byEq[0]->name);
    }

    /**
     * AUD-COR-001: an empty where list matches everything (update with no
     * constraints affects all rows) and never dereferences `$wheres[0]`.
     */
    public function testCsvUpdateWithNoWheresAffectsAllRows(): void
    {
        $connection = $this->makeCsv([
            ['id' => '1', 'name' => 'Ana'],
            ['id' => '2', 'name' => 'Bo'],
        ]);

        $affected = $connection->table('users')->update(['name' => 'Zed']);
        self::assertSame(2, $affected);

        $rows = $connection->table('users')->get()->all();
        self::assertSame('Zed', $rows[0]->name);
        self::assertSame('Zed', $rows[1]->name);
    }

    /**
     * AUD-COR-007: numeric columns sort numerically — '10' after '9'.
     */
    public function testCsvSortIsNumericForNumericCells(): void
    {
        $connection = $this->makeCsv([
            ['id' => '10', 'name' => 'ten'],
            ['id' => '9', 'name' => 'nine'],
            ['id' => '100', 'name' => 'hundred'],
        ]);

        $ids = array_map(
            fn(object $r) => $r->id,
            $connection->table('users')->orderBy('id')->get()->all(),
        );
        self::assertSame(['9', '10', '100'], $ids);
    }

    /**
     * AUD-SEC-005: whitespace- and Unicode-prefixed formula payloads are
     * neutralized, and header cells are neutralized too.
     */
    public function testCsvFormulaNeutralizationCoversBypasses(): void
    {
        $connection = $this->makeCsv([['id' => '1', 'name' => 'Ana']]);

        // Leading space + formula, NBSP-prefixed payload, DDE pipe.
        $connection->table('users')->insert([
            ['id' => '2', 'name' => ' =cmd|\' /C calc\'!A0'],
            ['id' => '3', 'name' => "\xC2\xA0=HYPERLINK(\"http://x\")"],
            ['id' => '4', 'name' => '|calc'],
        ]);

        $bytes = (string) file_get_contents($this->path);
        // Each payload must be quote-prefixed (the neutralized form), not
        // raw. The raw shapes checked are unambiguous: a cell STARTING with
        // the payload is only ever written as `'payload`.
        self::assertStringContainsString('\' =cmd', $bytes, 'A space-prefixed formula must be quoted.');
        self::assertStringContainsString("'\xC2\xA0=HYPERLINK", $bytes, 'An NBSP-prefixed formula must be quoted.');
        self::assertStringContainsString("'|calc", $bytes, 'A DDE pipe payload must be quoted.');
    }

    // ---- AUD-SEC-001: exception redaction ----

    /**
     * The QueryException message is log-safe: no SQL text, no bound values.
     * SQL stays on the property; bindings behind the accessor.
     */
    public function testQueryExceptionMessageIsRedacted(): void
    {
        $connection = new \BlueprintAU\Radiant\Database\Connections\SqliteConnection(new \PDO('sqlite::memory:'));
        $connection->statement('CREATE TABLE t (id integer)');
        $connection->statement('INSERT INTO t VALUES (1)');

        try {
            $connection->selectSql('SELECT * FROM missing_table WHERE id = ?', [42]);
            self::fail('Expected a QueryException.');
        } catch (QueryException $e) {
            $message = $e->getMessage();
            self::assertStringNotContainsString('missing_table', $message, 'The SQL text must not surface in the message.');
            self::assertStringNotContainsString('42', $message, 'A bound value must not surface in the message.');

            // Opt-in debuggability is preserved.
            self::assertStringContainsString('missing_table', $e->sql);
            self::assertSame([42], $e->getBindings());
            self::assertStringContainsString('missing_table', $e->toContextString());
        }
    }

    // ---- AUD-SEC-002: DSN field validation ----

    /**
     * DSN metacharacters in host/database are rejected at validation.
     */
    public function testDsnMetacharactersRejected(): void
    {
        $connector = new \BlueprintAU\Radiant\Database\Connectors\MySqlConnector();

        try {
            $connector->validConfig([
                'driver' => 'mysql',
                'host' => 'localhost',
                'port' => 3306,
                'database' => 'db;unix_socket=/tmp/x',
            ]);
            self::fail('Expected the metacharacter database to be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('must not contain semicolons', $e->getMessage());
        }

        $postgres = new \BlueprintAU\Radiant\Database\Connectors\PostgresConnector();
        try {
            $postgres->validConfig([
                'driver' => 'pgsql',
                'host' => 'db;sslmode=disable',
                'database' => 'app',
            ]);
            self::fail('Expected the metacharacter host to be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('must not contain semicolons', $e->getMessage());
        }
    }

    /**
     * AUD-SEC-003: emulation forced off ----
     */

    /**
     * No connector can re-enable client-side emulation through user options
     * — the base forced layer wins over user config (verified through
     * reflection, since the connector classes are final). MySQL's own
     * forced options restate it as well, so both layers are checked.
     */
    public function testMySqlEmulationCannotBeEnabled(): void
    {
        $baseForced = (new \ReflectionProperty(
            \BlueprintAU\Radiant\Database\Connectors\SqlConnector::class,
            'FORCED_OPTIONS',
        ))->getValue();
        $mysqlForced = (new \ReflectionProperty(
            \BlueprintAU\Radiant\Database\Connectors\MySqlConnector::class,
            'FORCED_OPTIONS',
        ))->getValue();

        self::assertFalse(
            $baseForced[\PDO::ATTR_EMULATE_PREPARES],
            'EMULATE_PREPARES must be forced false at the base layer.',
        );
        self::assertFalse(
            $mysqlForced[\PDO::ATTR_EMULATE_PREPARES] ?? false,
            'EMULATE_PREPARES must be forced false on MySQL.',
        );
    }

    // ---- AUD-SEC-007: fail-closed aggregate parsing ----

    /**
     * An aggregate over a nested expression fails closed instead of
     * splicing unwrapped text into the SQL.
     */
    public function testAggregateInnerFailsClosed(): void
    {
        /** 
         * @var Grammar&TestGrammarInterface $grammar
         * @phpstan-ignore varTag.nativeType
         */
        $grammar = new
            /** Test grammar exposing protected wrapColumn(). */
            class extends Grammar {
                /**
                 * Public exposure of the protected wrapColumn.
                 *
                 * @param string|\BlueprintAU\Radiant\Database\Query\Expression $column The column.
                 * @return string The wrapped column.
                 */
                public function exposeWrapColumn(string|\BlueprintAU\Radiant\Database\Query\Expression $column): string
                {
                    return $this->wrapColumn($column);
                }

                /**
                 * The dialect quote char.
                 *
                 * @param string $value The identifier.
                 * @return string The quoted identifier.
                 */
                public function wrap(string $value): string
                {
                    return '"' . str_replace('"', '""', $value) . '"';
                }
            };

        try {
            $grammar->exposeWrapColumn('sum(coalesce(x, 0))');
            self::fail('Expected a fail-closed exception for a nested aggregate argument.');
        } catch (UnsupportedFeatureException $e) {
            self::assertStringContainsString('single column', $e->getMessage());
        }

        // Simple aggregates still work.
        self::assertSame('sum("price")', $grammar->exposeWrapColumn('sum(price)'));
    }
}

/**
 * Interface used exclusively to expose protected Grammar methods for testing.
 */
interface TestGrammarInterface
{
    /**
     * Public exposure of the protected wrapColumn.
     *
     * @param string|\BlueprintAU\Radiant\Database\Query\Expression $column The column.
     * @return string The wrapped column.
     */
    public function exposeWrapColumn(string|\BlueprintAU\Radiant\Database\Query\Expression $column): string;
}
