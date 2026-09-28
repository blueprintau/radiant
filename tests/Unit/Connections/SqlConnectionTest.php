<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Connections;

use BlueprintAU\Radiant\Database\Connections\SqliteConnection;
use BlueprintAU\Radiant\Database\Query\Aggregate;
use BlueprintAU\Radiant\Database\Query\Enums\WhereOperator;
use BlueprintAU\Radiant\Database\Query\Expression;
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
        $first = $rows->first();
        self::assertNotNull($first);
        self::assertSame('Alice', $first->name);
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
        $first = $rows->first();
        self::assertNotNull($first);
        self::assertSame('Alice', $first->name);
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
        $this->expectExceptionMessageIsOrContains('Binding must be a scalar');
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

    /**
     * Aggregates select under a stable alias — portable across dialects.
     */
    public function testAggregates(): void
    {
        $this->connection->table('users')->insert([
            ['name' => 'Alice', 'email' => 'a@example.com', 'age' => 30],
            ['name' => 'Bob', 'email' => 'b@example.com', 'age' => 25],
            ['name' => 'Carol', 'email' => 'c@example.com', 'age' => 40],
        ]);

        self::assertSame(3, $this->connection->table('users')->count());
        self::assertSame(95, (int) $this->connection->table('users')->sum('age'));
        self::assertSame(40, (int) $this->connection->table('users')->max('age'));
        self::assertSame(25, (int) $this->connection->table('users')->min('age'));
    }

    /**
     * countBy() groups the rows per column with int counts.
     */
    public function testCountByGroupsRows(): void
    {
        $this->connection->table('users')->insert([
            ['name' => 'Alice', 'email' => 'a@example.com', 'age' => 30],
            ['name' => 'Bob', 'email' => 'b@example.com', 'age' => 25],
            ['name' => 'Bob', 'email' => 'b2@example.com', 'age' => 40],
        ]);

        $counts = $this->connection->table('users')->countBy('name');

        self::assertSame(1, $counts['Alice']);
        self::assertSame(2, $counts['Bob']);
        self::assertCount(2, $counts);
    }

    /**
     * The countBy() seed is additive: absent seeded groups become 0 and
     * unlisted database values still appear.
     */
    public function testCountBySeedIsAdditive(): void
    {
        $this->connection->table('users')->insert(['name' => 'Alice', 'email' => 'a@example.com', 'age' => 30]);

        $counts = $this->connection->table('users')->countBy('name', ['Alice', 'Bob', 'Carol']);

        self::assertSame(1, $counts['Alice']);
        self::assertSame(0, $counts['Bob']);
        self::assertSame(0, $counts['Carol']);
        self::assertCount(3, $counts);
    }

    /**
     * aggregateBy() sums per group; a group whose values are all NULL
     * sums to SQL NULL.
     */
    public function testAggregateBySumsPerGroup(): void
    {
        $this->connection->statement('ALTER TABLE users ADD COLUMN points INTEGER');
        $this->connection->table('users')->insert([
            ['name' => 'Alice', 'email' => 'a@example.com', 'age' => 30, 'points' => 10],
            ['name' => 'Bob', 'email' => 'b@example.com', 'age' => 25, 'points' => 5],
            ['name' => 'Carol', 'email' => 'c@example.com', 'age' => 40, 'points' => null],
        ]);

        $sums = $this->connection->table('users')->aggregateBy(Aggregate::sum('points'), 'name');

        self::assertSame(10, $sums['Alice']);
        self::assertSame(5, $sums['Bob']);
        self::assertNull($sums['Carol'], 'sum over an all-NULL group is SQL NULL');
    }

    /**
     * value() honors a user-supplied alias on the aggregate.
     */
    public function testValueWithUserAlias(): void
    {
        $this->connection->table('users')->insert(['name' => 'Alice', 'email' => 'a@example.com', 'age' => 30]);

        $value = $this->connection->table('users')->value(Aggregate::sum('age', 'total'));
        self::assertSame(30, (int) $value);
    }

    /**
     * pluck() honors a user-supplied alias on the column expression.
     */
    public function testPluckWithUserAlias(): void
    {
        $this->connection->table('users')->insert([
            ['name' => 'Alice', 'email' => 'a@example.com', 'age' => 30],
            ['name' => 'Bob', 'email' => 'b@example.com', 'age' => 25],
        ]);

        $values = $this->connection->table('users')->orderBy('age', 'DESC')->pluck('age as ages');
        self::assertSame([30, 25], array_map('intval', $values->all()));
    }

    /**
     * pluck() without an alias falls back to the stable internal alias.
     */
    public function testPluckWithoutAlias(): void
    {
        $this->connection->table('users')->insert([
            ['name' => 'Alice', 'email' => 'a@example.com', 'age' => 30],
            ['name' => 'Bob', 'email' => 'b@example.com', 'age' => 25],
        ]);

        $values = $this->connection->table('users')->orderBy('age', 'DESC')->pluck('age');
        self::assertSame([30, 25], array_map('intval', $values->all()));
    }

    /**
     * selectColumn() returns the first selected column's values positionally.
     */
    public function testSelectColumnReturnsPositionalValues(): void
    {
        $this->connection->table('users')->insert([
            ['name' => 'Alice', 'email' => 'a@example.com', 'age' => 30],
            ['name' => 'Bob', 'email' => 'b@example.com', 'age' => 25],
        ]);

        $values = $this->connection->selectColumn(
            $this->connection->table('users')->select('name')->orderBy('age', 'DESC'),
        );
        self::assertSame(['Alice', 'Bob'], $values->all());
    }

    /**
     * selectColumn() applies wheres and limit like select() does.
     */
    public function testSelectColumnAppliesWheresAndLimit(): void
    {
        $this->connection->table('users')->insert([
            ['name' => 'Alice', 'email' => 'a@example.com', 'age' => 30],
            ['name' => 'Bob', 'email' => 'b@example.com', 'age' => 25],
            ['name' => 'Carol', 'email' => 'c@example.com', 'age' => 40],
        ]);

        $values = $this->connection->selectColumn(
            $this->connection->table('users')->select('name')->where('age', '>', 26)->limit(1),
        );
        self::assertSame(['Alice'], $values->all());
    }

    /**
     * selectColumn() on an empty result returns an empty list.
     */
    public function testSelectColumnEmptyResult(): void
    {
        $values = $this->connection->selectColumn(
            $this->connection->table('users')->select('name')->where('age', '>', 99),
        );
        self::assertSame([], $values->all());
    }

    /**
     * selectColumnSql() is the raw-SQL counterpart of selectColumn().
     */
    public function testSelectColumnSql(): void
    {
        $this->connection->table('users')->insert([
            ['name' => 'Alice', 'email' => 'a@example.com', 'age' => 30],
            ['name' => 'Bob', 'email' => 'b@example.com', 'age' => 25],
        ]);

        $values = $this->connection->selectColumnSql('SELECT name FROM users ORDER BY age DESC');
        self::assertSame(['Alice', 'Bob'], $values->all());
    }

    // ---- Streaming cursors ----

    /**
     * cursor() streams every matching row, in query order.
     */
    public function testCursorStreamsAllRows(): void
    {
        $this->connection->table('users')->insert([
            ['name' => 'Alice', 'email' => 'a@example.com', 'age' => 30],
            ['name' => 'Bob', 'email' => 'b@example.com', 'age' => 25],
            ['name' => 'Carol', 'email' => 'c@example.com', 'age' => 40],
        ]);

        $names = [];
        foreach ($this->connection->table('users')->orderBy('age')->cursor() as $row) {
            self::assertInstanceOf(\stdClass::class, $row);
            $names[] = $row->name;
        }

        self::assertSame(['Bob', 'Alice', 'Carol'], $names);
    }

    /**
     * cursor() honors wheres and limit like get() does.
     */
    public function testCursorAppliesWheresAndLimit(): void
    {
        $this->connection->table('users')->insert([
            ['name' => 'Alice', 'email' => 'a@example.com', 'age' => 30],
            ['name' => 'Bob', 'email' => 'b@example.com', 'age' => 25],
        ]);

        $rows = iterator_to_array(
            $this->connection->table('users')->where('age', WhereOperator::Gt, 26)->limit(1)->cursor(),
            false,
        );

        self::assertCount(1, $rows);
        self::assertSame('Alice', $rows[0]->name);
    }

    /**
     * cursor() and get() produce the same rows for the same query.
     */
    public function testCursorMatchesGet(): void
    {
        $this->connection->table('users')->insert([
            ['name' => 'Alice', 'email' => 'a@example.com', 'age' => 30],
            ['name' => 'Bob', 'email' => 'b@example.com', 'age' => 25],
        ]);

        $query = fn() => $this->connection->table('users')->orderBy('name');
        $fromGet = array_map(fn($r) => $r->name, $query()->get()->all());
        $fromCursor = [];
        foreach ($query()->cursor() as $row) {
            $fromCursor[] = $row->name;
        }

        self::assertSame($fromGet, $fromCursor);
    }

    /**
     * cursorSql() yields rows one at a time and releases the statement on
     * full consumption — a follow-up query on the same connection works.
     */
    public function testCursorSqlFollowUpQueryWorks(): void
    {
        $this->connection->table('users')->insert([
            ['name' => 'Alice', 'email' => 'a@example.com', 'age' => 30],
            ['name' => 'Bob', 'email' => 'b@example.com', 'age' => 25],
        ]);

        $names = [];
        foreach ($this->connection->cursorSql('SELECT * FROM users ORDER BY name') as $row) {
            $names[] = $row->name;
        }

        self::assertSame(['Alice', 'Bob'], $names);

        // The cursor is drained (closeCursor ran) — the connection must be
        // usable for the next query.
        self::assertSame(2, $this->connection->table('users')->count());
    }

    /**
     * Abandoning a cursor early (break) must not poison the connection —
     * the finally block releases the statement.
     */
    public function testAbandonedCursorReleasesStatement(): void
    {
        $this->connection->table('users')->insert([
            ['name' => 'Alice', 'email' => 'a@example.com', 'age' => 30],
            ['name' => 'Bob', 'email' => 'b@example.com', 'age' => 25],
        ]);

        foreach ($this->connection->cursorSql('SELECT * FROM users') as $row) {
            break; // abandon after the first row
        }

        // Force destruction of the abandoned generator, then use the
        // connection again — this would fail if the statement were left
        // holding an undrained result set (unbuffered mode).
        gc_collect_cycles();

        $row = $this->connection->table('users')->where('name', '=', 'Bob')->first();
        self::assertNotNull($row);
        self::assertSame(25, $row->age);
    }
}
