<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Connections;

use BlueprintAU\Radiant\Database\Connections\CsvConnection;
use BlueprintAU\Radiant\Database\Exceptions\UnsupportedFeatureException;
use BlueprintAU\Radiant\Database\Query\Aggregate;
use BlueprintAU\Radiant\Database\Query\Enums\WhereOperator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the CSV backend — the portable non-SQL implementation of
 * {@see ConnectionInterface}.
 */
final class CsvConnectionTest extends TestCase
{
    /**
     * The temp CSV file used by a test.
     *
     * @var string
     */
    private string $path;

    /**
     * Create a temp CSV file with a known dataset, and return a connection.
     *
     * @param list<array<string, string|int|float|null>> $rows The data rows.
     * @return CsvConnection The connection.
     */
    private function makeCsv(array $rows): CsvConnection
    {
        $this->path = tempnam(sys_get_temp_dir(), 'radiant_csv_') ?: sys_get_temp_dir() . '/radiant_csv_test';
        $handle = fopen($this->path, 'w');
        \assert($handle !== false);

        // Header.
        $header = array_keys($rows[0]);
        fputcsv($handle, $header, escape: '');
        foreach ($rows as $row) {
            fputcsv($handle, $row, escape: '');
        }
        fclose($handle);
        return new CsvConnection($this->path);
    }

    /**
     * Clean up the temp CSV file after each test.
     */
    protected function tearDown(): void
    {
        if (isset($this->path) && is_file($this->path)) {
            @unlink($this->path);
        }
        parent::tearDown();
    }

    // ---- select ----

    /**
     * A plain select returns every row.
     */
    public function testSelectAll(): void
    {
        $csv = $this->makeCsv([
            ['id' => 1, 'name' => 'Alice'],
            ['id' => 2, 'name' => 'Bob'],
        ]);
        $rows = $csv->table('users')->get();

        self::assertCount(2, $rows);
        self::assertNotNull($rows[0]);
        self::assertSame('1', $rows[0]->id);
        self::assertSame('Alice', $rows[0]->name);
    }

    /**
     * A where filters rows.
     */
    public function testWhereFilters(): void
    {
        $csv = $this->makeCsv([
            ['id' => 1, 'name' => 'Alice', 'age' => 30],
            ['id' => 2, 'name' => 'Bob', 'age' => 25],
            ['id' => 3, 'name' => 'Carol', 'age' => 40],
        ]);
        $rows = $csv->table('users')
            ->where('age', WhereOperator::Gt, 26)
            ->orderBy('age')
            ->get();

        self::assertCount(2, $rows);
        self::assertNotNull($rows[0]);
        self::assertNotNull($rows[1]);
        self::assertSame(['1', '3'], [$rows[0]->id, $rows[1]->id]);
    }

    /**
     * orderBy and limit/offset work.
     */
    public function testOrderAndLimit(): void
    {
        $csv = $this->makeCsv([
            ['id' => 1, 'name' => 'Alice', 'age' => 30],
            ['id' => 2, 'name' => 'Bob', 'age' => 25],
            ['id' => 3, 'name' => 'Carol', 'age' => 40],
        ]);
        $rows = $csv->table('users')
            ->orderBy('age', 'DESC')
            ->limit(1)
            ->offset(1)
            ->get();

        self::assertCount(1, $rows);
        self::assertNotNull($rows[0]);
        self::assertSame('1', $rows[0]->id); // second oldest = Alice (age 30)
    }

    /**
     * Aggregates work in PHP.
     */
    public function testAggregate(): void
    {
        $csv = $this->makeCsv([
            ['id' => 1, 'total' => 10],
            ['id' => 2, 'total' => 20],
            ['id' => 3, 'total' => 30],
        ]);
        $count = $csv->table('orders')->count();
        $sum = $csv->table('orders')->sum('total');

        self::assertSame(3, $count);
        self::assertSame('60', (string) $sum);
    }

    /**
     * selectColumn() returns the first selected column's values positionally.
     */
    public function testSelectColumn(): void
    {
        $csv = $this->makeCsv([
            ['id' => 1, 'name' => 'Alice', 'age' => 30],
            ['id' => 2, 'name' => 'Bob', 'age' => 25],
            ['id' => 3, 'name' => 'Carol', 'age' => 40],
        ]);

        $values = $csv->selectColumn(
            $csv->table('users')->select('name')->orderBy('age', 'DESC'),
        );
        self::assertSame(['Carol', 'Alice', 'Bob'], $values->all());
    }

    /**
     * selectColumn() applies wheres and limit like select() does.
     */
    public function testSelectColumnAppliesWheresAndLimit(): void
    {
        $csv = $this->makeCsv([
            ['id' => 1, 'name' => 'Alice', 'age' => 30],
            ['id' => 2, 'name' => 'Bob', 'age' => 25],
            ['id' => 3, 'name' => 'Carol', 'age' => 40],
        ]);

        $values = $csv->selectColumn(
            $csv->table('users')->select('name')->where('age', WhereOperator::Gt, 26)->limit(1),
        );
        self::assertSame(['Alice'], $values->all());
    }

    /**
     * selectColumn() over an aggregate reads the aggregate's values.
     */
    public function testSelectColumnOverAggregate(): void
    {
        $csv = $this->makeCsv([
            ['id' => 1, 'name' => 'Alice', 'age' => 30],
            ['id' => 2, 'name' => 'Bob', 'age' => 25],
            ['id' => 3, 'name' => 'Carol', 'age' => 40],
        ]);

        $values = $csv->selectColumn(
            $csv->table('users')->select(new Aggregate('count', '*', 'radiant_scalar')),
        );
        self::assertSame([3], $values->all());
    }

    /**
     * selectColumn() rejects a wildcard select — no single column to read.
     */
    public function testSelectColumnRejectsWildcard(): void
    {
        $csv = $this->makeCsv([
            ['id' => 1, 'name' => 'Alice'],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('selectColumn() requires a single named column');

        $csv->selectColumn($csv->table('users')->select('*'));
    }

    /**
     * pluck() works on the CSV backend — the bare column name resolves
     * against the file's fields (a trailing `as alias` would not).
     */
    public function testPluckResolvesBareColumn(): void
    {
        $csv = $this->makeCsv([
            ['id' => 1, 'name' => 'Alice', 'age' => 30],
            ['id' => 2, 'name' => 'Bob', 'age' => 25],
        ]);

        $values = $csv->table('users')->orderBy('age', 'DESC')->pluck('name');
        self::assertSame(['Alice', 'Bob'], $values->all());
    }

    /**
     * pluck() honors a user-supplied alias — the alias is stripped, the
     * bare expression is what the backend resolves.
     */
    public function testPluckWithUserAlias(): void
    {
        $csv = $this->makeCsv([
            ['id' => 1, 'name' => 'Alice', 'age' => 30],
            ['id' => 2, 'name' => 'Bob', 'age' => 25],
        ]);

        $values = $csv->table('users')->orderBy('age', 'DESC')->pluck('name as who');
        self::assertSame(['Alice', 'Bob'], $values->all());
    }

    // ---- cursor ----

    /**
     * cursor() streams every matching row — the same rows get() returns.
     */
    public function testCursorMatchesGet(): void
    {
        $csv = $this->makeCsv([
            ['id' => 1, 'name' => 'Alice', 'age' => 30],
            ['id' => 2, 'name' => 'Bob', 'age' => 25],
            ['id' => 3, 'name' => 'Carol', 'age' => 40],
        ]);

        $fromGet = array_map(fn ($r) => $r->name, $csv->table('users')->orderBy('age')->get()->all());
        $fromCursor = [];
        foreach ($csv->table('users')->orderBy('age')->cursor() as $row) {
            $fromCursor[] = $row->name;
        }

        self::assertSame($fromGet, $fromCursor);
        self::assertSame(['Bob', 'Alice', 'Carol'], $fromCursor);
    }

    // ---- insert / update / delete ----

    /**
     * insert adds a row.
     */
    public function testInsert(): void
    {
        $csv = $this->makeCsv([['id' => 1, 'name' => 'Alice']]);
        $affected = $csv->table('users')->insert(['id' => 2, 'name' => 'Bob']);

        self::assertSame(1, $affected);
        $rows = $csv->table('users')->get();
        self::assertCount(2, $rows);
        self::assertNotNull($rows[1]);
        self::assertSame('Bob', $rows[1]->name);
    }

    /**
     * update changes matching rows and returns the count.
     */
    public function testUpdate(): void
    {
        $csv = $this->makeCsv([
            ['id' => 1, 'name' => 'Alice', 'age' => 30],
            ['id' => 2, 'name' => 'Bob', 'age' => 25],
        ]);
        $affected = $csv->table('users')
            ->where('id', WhereOperator::Eq, 2)
            ->update(['age' => 26]);

        self::assertSame(1, $affected);
        $rows = $csv->table('users')->orderBy('id')->get();
        self::assertNotNull($rows[1]);
        self::assertSame('26', $rows[1]->age);
    }

    /**
     * delete removes matching rows and returns the count.
     */
    public function testDelete(): void
    {
        $csv = $this->makeCsv([
            ['id' => 1, 'name' => 'Alice'],
            ['id' => 2, 'name' => 'Bob'],
        ]);
        $affected = $csv->table('users')->where('id', WhereOperator::Eq, 2)->delete();

        self::assertSame(1, $affected);
        self::assertCount(1, $csv->table('users')->get());
    }

    // ---- fail-fast on SQL-only features ----

    /**
     * joins throw on a CSV backend.
     */
    public function testJoinFails(): void
    {
        $csv = $this->makeCsv([
            ['id' => 1, 'name' => 'Alice'],
        ]);

        $this->expectException(UnsupportedFeatureException::class);
        $this->expectExceptionMessageIsOrContains('[joins]');
        $csv->table('users')->join('orders', 'users.id', '=', 'orders.user_id')->get();
    }

    /**
     * Transaction methods are not part of the portable interface — a raw SQL
     * call through the SQL facade is the SQL-only path, so nothing to test
     * here beyond an explicit exception.
     */
    public function testRawSelectFails(): void
    {
        $csv = $this->makeCsv([
            ['id' => 1, 'name' => 'Alice'],
        ]);
        // CsvConnection has no selectSql — the SQL-only raw path is gated
        // by the facade. This asserts the where-level fail-fast instead.
        $this->expectException(UnsupportedFeatureException::class);
        $this->expectExceptionMessageIsOrContains('[raw-sql]');
        $csv->table('users')->whereRaw('1 = 1')->get();
    }

    /**
     * A read-only CSV connection rejects writes.
     */
    public function testReadOnlyRejectsWrites(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'radiant_csv_') ?: sys_get_temp_dir() . '/radiant_csv_test';
        file_put_contents($this->path, "id,name\n1,Alice\n");
        $csv = new CsvConnection($this->path, readOnly: true);

        $this->expectException(UnsupportedFeatureException::class);
        $this->expectExceptionMessageIsOrContains('read-only');
        $csv->table('users')->insert(['id' => 2, 'name' => 'Bob']);
    }

    /**
     * An EXISTS where clause is gated at the feature assert — the CSV
     * engine never sees the clause.
     */
    public function testWhereExistsFails(): void
    {
        $csv = $this->makeCsv([
            ['id' => 1, 'name' => 'Alice'],
        ]);
        $sub = $csv->table('orders')->select('*');

        $this->expectException(UnsupportedFeatureException::class);
        $this->expectExceptionMessageIsOrContains('[subquery-where]');
        $csv->table('users')->whereExists($sub)->get();
    }

    /**
     * A subquery select column is gated at the feature assert.
     */
    public function testSelectSubFails(): void
    {
        $csv = $this->makeCsv([
            ['id' => 1, 'name' => 'Alice'],
        ]);
        $sub = $csv->table('orders')->select('*');

        $this->expectException(UnsupportedFeatureException::class);
        $this->expectExceptionMessageIsOrContains('[subquery-select]');
        $csv->table('users')->select(new \BlueprintAU\Radiant\Database\Query\SubquerySelect($sub, 'cnt'))->get();
    }

    /**
     * An IN-subquery where clause is gated at the feature assert.
     */
    public function testWhereInQueryFails(): void
    {
        $csv = $this->makeCsv([
            ['id' => 1, 'name' => 'Alice'],
        ]);
        $sub = $csv->table('orders')->select('user_id');

        $this->expectException(UnsupportedFeatureException::class);
        $this->expectExceptionMessageIsOrContains('[subquery-where]');
        $csv->table('users')->whereInQuery('id', $sub)->get();
    }
}