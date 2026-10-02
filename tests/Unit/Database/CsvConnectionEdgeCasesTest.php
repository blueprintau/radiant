<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Database;

use BlueprintAU\Radiant\Database\Connections\CsvConnection;
use BlueprintAU\Radiant\Database\Query\Aggregate;
use BlueprintAU\Radiant\Database\Query\Enums\WhereBoolean;
use BlueprintAU\Radiant\Database\Query\Enums\WhereOperator;
use BlueprintAU\Radiant\Database\Query\Expression;
use BlueprintAU\Radiant\Database\Exceptions\UnsupportedFeatureException;
use BlueprintAU\Radiant\Tests\Support\Expectation;
use PHPUnit\Framework\Attributes\DoesNotPerformAssertions;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use PHPUnit\Framework\TestCase;

/**
 * Exercise the CsvConnection edge arms — filter operators, aggregate
 * computation, projection guards, file I/O failures and lock release.
 */
final class CsvConnectionEdgeCasesTest extends TestCase
{
    /**
     * The path of the temp CSV file backing the connection.
     *
     * @var string
     */
    private string $path;

    /**
     * The dedicated directory holding this test's files — created fresh
     * per test so the permission test can chmod it without touching the
     * shared temp dir.
     *
     * @var string|null
     */
    private ?string $dir = null;

    /**
     * Build a CSV connection over the given rows.
     *
     * @param  list<array<string, int|string|null>>  $rows
     * @return CsvConnection
     */
    private function makeCsv(array $rows): CsvConnection
    {
        $this->path = tempnam(sys_get_temp_dir(), 'radiant_csv_')
            ?: sys_get_temp_dir() . '/radiant_csv_test';
        $handle = fopen($this->path, 'w');
        \assert($handle !== false);

        if ($rows !== []) {
            fputcsv($handle, array_keys($rows[0]), escape: '');
            foreach ($rows as $row) {
                fputcsv($handle, array_values($row), escape: '');
            }
        }

        fclose($handle);

        return new CsvConnection($this->path);
    }

    /**
     * Remove the temp file and any lock/temp residue — including the
     * dedicated directory the permission test created (its mode is
     * restored first so the rmdir can succeed).
     */
    protected function tearDown(): void
    {
        if ($this->dir !== null) {
            chmod($this->dir, 0700);
            foreach (glob($this->dir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->dir);
            $this->dir = null;
        }

        if (isset($this->path)) {
            foreach ([$this->path, $this->path . '.lock'] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
            foreach (glob($this->path . '.radiant-*.tmp') ?: [] as $tmp) {
                @unlink($tmp);
            }
        }

        parent::tearDown();
    }

    /**
     * Seed rows for the filter tests.
     *
     * @return CsvConnection
     */
    private function seedUsers(): CsvConnection
    {
        return $this->makeCsv([
            ['id' => 1, 'name' => 'Alice', 'email' => 'alice@example.com', 'age' => 25],
            ['id' => 2, 'name' => 'Bob', 'email' => 'bob@example.org', 'age' => 40],
            ['id' => 3, 'name' => 'Carol', 'email' => 'carol@example.com', 'age' => 30],
        ]);
    }

    // ---- Filter operators ----

    /**
     * An OR boolean between two wheres matches either branch.
     */
    public function testOrBooleanMatchesEitherBranch(): void
    {
        $db = $this->seedUsers();

        $rows = $db->table('users')
            ->where('id', WhereOperator::Eq, 1)
            ->where('id', WhereOperator::Eq, 2, WhereBoolean::Or)
            ->orderBy('id')
            ->get();

        self::assertCount(2, $rows);
    }

    /**
     * BETWEEN matches the inclusive range.
     */
    public function testBetweenMatchesInclusiveRange(): void
    {
        $db = $this->seedUsers();

        $rows = $db->table('users')
            ->where('age', WhereOperator::Between, [25, 30])
            ->orderBy('id')
            ->get();

        self::assertCount(2, $rows);
    }

    /**
     * NOT BETWEEN excludes the range.
     */
    public function testNotBetweenExcludesRange(): void
    {
        $db = $this->seedUsers();

        $rows = $db->table('users')
            ->where('age', WhereOperator::NotBetween, [25, 30])
            ->get();

        self::assertCount(1, $rows);
    }

    /**
     * An unsupported operator (IS) fails fast.
     */
    public function testUnsupportedOperatorThrows(): void
    {
        $db = $this->seedUsers();

        $this->expectException(UnsupportedFeatureException::class);
        $this->expectExceptionMessageIsOrContains(
            'This connection does not support the IS operator.',
        );

        $db->table('users')->where('name', WhereOperator::Is, 'NULL')->get();
    }

    /**
     * A null operand inside whereIn matches nothing — the strict null arm
     * of valuesEqual.
     */
    public function testWhereInWithNullMatchesNothing(): void
    {
        $db = $this->seedUsers();

        $rows = $db->table('users')
            ->where('id', WhereOperator::In, [null])
            ->get();

        self::assertCount(0, $rows);
    }

    /**
     * A boolean operand never equals a string cell — the strict bool arm
     * of valuesEqual (no `1 == true` juggling).
     */
    public function testBooleanOperandNeverEqualsStringCell(): void
    {
        $db = $this->seedUsers();

        $rows = $db->table('users')
            ->where('id', WhereOperator::Eq, true)
            ->get();

        self::assertCount(0, $rows);
    }

    /**
     * An array operand inside whereIn matches nothing — the strict array
     * arm of valuesEqual.
     */
    public function testArrayOperandMatchesNothing(): void
    {
        $db = $this->seedUsers();

        $rows = $db->table('users')
            ->where('id', WhereOperator::In, [[1]])
            ->get();

        self::assertCount(0, $rows);
    }

    /**
     * LIKE escapes regex metacharacters in the pattern — a dot matches
     * only a dot.
     */
    public function testLikeEscapesRegexMetacharacters(): void
    {
        $db = $this->seedUsers();

        $rows = $db->table('users')
            ->where('email', WhereOperator::Like, '%@example.com')
            ->orderBy('id')
            ->get();

        self::assertCount(2, $rows, 'the dot must match literally, not as a wildcard');
    }

    /**
     * NOT LIKE negates the pattern match.
     */
    public function testNotLikeNegatesMatch(): void
    {
        $db = $this->seedUsers();

        $rows = $db->table('users')
            ->where('email', WhereOperator::NotLike, '%@example.com')
            ->get();

        self::assertCount(1, $rows);
    }

    /**
     * The underscore wildcard matches exactly one character.
     */
    public function testUnderscoreWildcardMatchesOneCharacter(): void
    {
        $db = $this->seedUsers();

        $rows = $db->table('users')
            ->where('name', WhereOperator::Like, 'B_b')
            ->get();

        self::assertCount(1, $rows);
    }

    // ---- Aggregates ----

    /**
     * An aggregate over an empty table seeds a zero row — count() is 0,
     * not an error.
     */
    public function testAggregateOverEmptyTableSeedsZeroRow(): void
    {
        $db = $this->makeCsv([]);

        self::assertSame(0, $db->table('users')->count());
    }

    /**
     * avg() over an empty table reports null — the empty-seed arm.
     */
    public function testAvgOverEmptyTableReportsNull(): void
    {
        $db = $this->makeCsv([]);

        self::assertNull($db->table('users')->avg('age'));
    }

    /**
     * sum() computes over the column values.
     */
    public function testSumComputesColumnTotal(): void
    {
        $db = $this->seedUsers();

        self::assertSame(95, $db->table('users')->sum('age'));
    }

    /**
     * An unsupported aggregate function fails fast.
     */
    public function testUnsupportedAggregateThrows(): void
    {
        $db = $this->seedUsers();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'Unsupported aggregate [median] on a CSV connection.',
        );

        $db->table('users')->select(new Aggregate('median', 'age'))->get();
    }

    /**
     * An aggregate over a raw Expression fails fast — the builder's
     * raw-sql gate does not flag Aggregate-wrapped expressions.
     */
    public function testAggregateOverExpressionThrows(): void
    {
        $db = $this->seedUsers();

        $this->expectException(UnsupportedFeatureException::class);
        $this->expectExceptionMessageIsOrContains(
            'This connection cannot compute an aggregate over a raw Expression argument.',
        );

        $db->table('users')->select(new Aggregate('count', new Expression('id')))->get();
    }

    /**
     * Aggregates GROUPED by a column carry the group values alongside —
     * one row per group, the SQL shape.
     */
    public function testGroupedAggregatesCarryGroupValues(): void
    {
        $db = $this->seedUsers();

        $rows = $db->table('users')
            ->groupBy('email')
            ->select('email', new Aggregate('count', 'id'))
            ->get();

        // Three distinct emails → three groups.
        self::assertCount(3, $rows);

        $byEmail = [];
        foreach ($rows as $row) {
            $byEmail[$row->email] = $row->{'count(id)'};
        }

        self::assertSame(1, $byEmail['alice@example.com']);
        self::assertSame(1, $byEmail['bob@example.org']);
        self::assertSame(1, $byEmail['carol@example.com']);
    }

    /**
     * Projecting an unknown column fails fast.
     */
    public function testProjectUnknownColumnViaSelectListThrows(): void
    {
        $db = $this->seedUsers();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Unknown column [ghost] on CSV connection.');

        $db->table('users')->select('ghost')->get();
    }

    /**
     * max()/min() over an empty table report null — the empty-column arm.
     */
    public function testMaxAndMinOverEmptyTableReportNull(): void
    {
        $db = $this->makeCsv([]);

        self::assertNull($db->table('users')->max('age'));
        self::assertNull($db->table('users')->min('age'));
    }

    /**
     * An aggregate query with limit(0) has no rows to aggregate — the
     * empty-select-list arm of selectColumn.
     */
    public function testSelectColumnOverLimitZeroThrows(): void
    {
        $db = $this->seedUsers();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'selectColumn() requires a single named column; got an empty select list.',
        );

        $db->selectColumn($db->table('users')->select(Aggregate::count())->limit(0));
    }

    /**
     * selectColumn() on an unknown column fails fast.
     */
    public function testSelectColumnUnknownColumnThrows(): void
    {
        $db = $this->seedUsers();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'Unknown column [nope] on CSV connection.',
        );

        $db->selectColumn($db->table('users')->select('nope'));
    }

    // ---- Projection & ordering ----

    /**
     * A select of an unknown column fails fast — the project() guard.
     */
    public function testProjectUnknownColumnThrows(): void
    {
        $db = $this->seedUsers();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'Unknown column [nope] on CSV connection.',
        );

        $db->table('users')->select('nope')->get();
    }

    /**
     * Ordering by a non-numeric column falls back to string comparison.
     */
    public function testOrderByTextColumnUsesStringComparison(): void
    {
        $db = $this->seedUsers();

        $rows = $db->table('users')->orderBy('name')->get();

        self::assertCount(3, $rows);
        $first = $rows->first();
        self::assertNotNull($first);
        self::assertSame('Alice', $first->name);
    }

    // ---- File I/O ----

    /**
     * A missing file reports a clear open failure.
     *
     * The fopen() warning inside readRowsUnlocked() is expected — the
     * RuntimeException it produces is the assertion's subject.
     */
    #[WithoutErrorHandler]
    public function testMissingFileThrowsOnRead(): void
    {
        $missing = sys_get_temp_dir() . '/radiant_csv_missing_' . uniqid() . '.csv';

        $db = new CsvConnection($missing);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageIsOrContains(
            "Could not open CSV file [{$missing}].",
        );

        $db->table('users')->get();
    }

    /**
     * An empty file reads as zero rows — no header, no error.
     */
    public function testEmptyFileReadsAsZeroRows(): void
    {
        $db = $this->makeCsv([]);

        $rows = $db->table('users')->get();

        self::assertCount(0, $rows);
    }

    /**
     * A failed write releases the held lock — another handle can take the
     * exclusive lock afterwards, and no temp residue remains.
     *
     * The CSV lives in a dedicated, freshly created directory so the
     * read-only chmod is deterministic — chmodding the shared temp dir
     * would depend on it being user-owned and on mode bits being
     * enforced at all. The is_writable() guard turns an environment that
     * ignores mode bits (root, capability-bearing containers) into a
     * skip instead of a false pass.
     *
     * The sidecar lock file is pre-created: with the directory read-only,
     * creating it would fail inside acquireLock() BEFORE the write is
     * attempted, and the held-lock release arm would never run.
     *
     * The fopen() warning inside writeRows() is expected — the
     * RuntimeException it produces is the assertion's subject.
     */
    #[WithoutErrorHandler]
    public function testFailedWriteReleasesLockAndLeavesNoResidue(): void
    {
        $dir = sys_get_temp_dir() . '/radiant_csv_ro_' . uniqid();
        self::assertTrue(mkdir($dir, 0700, true));
        $this->dir = $dir;
        $this->path = $dir . '/users.csv';

        $handle = fopen($this->path, 'w');
        \assert($handle !== false);
        fputcsv($handle, ['id', 'name', 'email', 'age'], escape: '');
        fputcsv($handle, [1, 'Alice', 'alice@example.com', 25], escape: '');
        fputcsv($handle, [2, 'Bob', 'bob@example.org', 40], escape: '');
        fputcsv($handle, [3, 'Carol', 'carol@example.com', 30], escape: '');
        fclose($handle);

        $db = new CsvConnection($this->path);

        // Pre-create the sidecar so acquireLock() can open (not create) it
        // while the directory is read-only.
        $lockHandle = fopen($this->path . '.lock', 'c');
        \assert($lockHandle !== false);
        fclose($lockHandle);

        chmod($dir, 0500);
        if (is_writable($dir)) {
            chmod($dir, 0700);
            self::markTestSkipped('directory mode bits are not enforced in this environment');
        }
        try {
            Expectation::throwsWithMessage(
                fn () => $db->table('users')->where('id', WhereOperator::Eq, 1)->update(['name' => 'X']),
                \RuntimeException::class,
                'Could not write CSV file',
            );
        } finally {
            chmod($dir, 0700);
        }

        // The lock was released: another handle wins a non-blocking
        // exclusive lock on the sidecar.
        $other = fopen($this->path . '.lock', 'r');
        \assert($other !== false);
        self::assertTrue(
            flock($other, LOCK_EX | LOCK_NB),
            'the lock must be released after a failed mutation',
        );
        flock($other, LOCK_UN);
        fclose($other);

        // The data file is untouched and no temp residue remains.
        self::assertSame(
            "id,name,email,age\n1,Alice,alice@example.com,25\n"
            . "2,Bob,bob@example.org,40\n3,Carol,carol@example.com,30\n",
            (string) file_get_contents($this->path),
        );
        self::assertSame([], glob($this->path . '.radiant-*.tmp'));
    }

    // ---- Staleness ----

    /**
     * A CSV connection is never stale — there is no server to lose.
     */
    public function testIsStaleAlwaysFalse(): void
    {
        $db = $this->seedUsers();

        self::assertFalse($db->isStale());
    }

    /**
     * markStale() is a no-op — staleness stays false.
     */
    #[DoesNotPerformAssertions]
    public function testMarkStaleIsANoOp(): void
    {
        $db = $this->seedUsers();

        $db->markStale();
    }
}
