<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Grammars;

use BlueprintAU\Radiant\Database\Exceptions\UnsupportedFeatureException;
use BlueprintAU\Radiant\Database\Grammars\MySqlGrammar;
use BlueprintAU\Radiant\Database\Grammars\PostgresGrammar;
use BlueprintAU\Radiant\Database\Grammars\SqliteGrammar;
use BlueprintAU\Radiant\Database\Query\Enums\BindingCategory;
use BlueprintAU\Radiant\Database\Query\Expression;
use BlueprintAU\Radiant\Database\Query\WhereBuilder;
use BlueprintAU\Radiant\Database\Query\QueryBuilder;
use BlueprintAU\Radiant\Database\Query\ToSqlValue;
use BlueprintAU\Radiant\Database\Query\Enums\WhereOperator;
use BlueprintAU\Radiant\Tests\Support\NullConnection;
use PHPUnit\Framework\TestCase;

/**
 * Compile-only tests for the SQL Grammar chain.
 *
 * These assert the exact SQL each fragment and root produces, per dialect,
 * without touching a database. The builder is constructed with a null
 * connection because compilation never touches the connection.
 */
final class GrammarTest extends TestCase
{
    /**
     * The most recently built builder (for binding-order assertions).
     *
     * @var QueryBuilder
     */
    private QueryBuilder $lastBuilder;

    /**
     * Build a query builder with a null connection (compilation never uses it).
     *
     * @param string $table The table to query.
     * @return QueryBuilder The builder.
     */
    private function builder(string $table = 'users'): QueryBuilder
    {
        return $this->lastBuilder = new QueryBuilder(new NullConnection(), $table);
    }

    // ---- Identifier wrapping ----

    /**
     * A plain select wraps the table and columns.
     */
    public function testPlainSelect(): void
    {
        $sql = (new SqliteGrammar())->compileSelect($this->builder());
        self::assertSame('SELECT * FROM "users"', $sql);
    }

    /**
     * Qualified identifiers are quoted per segment.
     */
    public function testQualifiedIdentifiers(): void
    {
        $sql = (new SqliteGrammar())->compileSelect(
            $this->builder('app.users')->select('users.id', 'users.name'),
        );
        self::assertSame('SELECT "users"."id", "users"."name" FROM "app"."users"', $sql);
    }

    /**
     * MySQL uses backticks.
     */
    public function testMySqlBackticks(): void
    {
        $sql = (new MySqlGrammar())->compileSelect($this->builder('users')->select('users.id'));
        self::assertSame('SELECT `users`.`id` FROM `users`', $sql);
    }

    /**
     * An embedded quote in an identifier is doubled.
     */
    public function testEmbeddedQuoteEscaping(): void
    {
        $sql = (new SqliteGrammar())->compileSelect($this->builder('weird"table'));
        self::assertSame('SELECT * FROM "weird""table"', $sql);
    }

    // ---- Select fragments ----

    /**
     * Distinct renders after select.
     */
    public function testDistinct(): void
    {
        $sql = (new SqliteGrammar())->compileSelect($this->builder()->distinct());
        self::assertSame('SELECT DISTINCT * FROM "users"', $sql);
    }

    /**
     * Aggregate expressions pass through with wrapped inner columns and aliases.
     */
    public function testAggregateColumns(): void
    {
        $sql = (new SqliteGrammar())->compileSelect(
            $this->builder()->select('count(*)', 'sum(price) as total'),
        );
        self::assertSame('SELECT count(*), sum("price") AS "total" FROM "users"', $sql);
    }

    /**
     * A raw expression in the select list is spliced verbatim.
     */
    public function testSelectRaw(): void
    {
        $sql = (new SqliteGrammar())->compileSelect(
            $this->builder()->selectRaw('lower(email) as email_lower'),
        );
        self::assertSame('SELECT lower(email) as email_lower FROM "users"', $sql);
    }

    /**
     * A bare star mixed with other columns passes through unquoted.
     */
    public function testStarWithOtherColumns(): void
    {
        $sql = (new SqliteGrammar())->compileSelect(
            $this->builder()->select('*', 'extra_field'),
        );
        self::assertSame('SELECT *, "extra_field" FROM "users"', $sql);
    }

    /**
     * A qualified star and an aggregate with alias compile correctly.
     */
    public function testQualifiedStarAndAggregate(): void
    {
        $sql = (new SqliteGrammar())->compileSelect(
            $this->builder()->select('schema.*', 'count(*) as total'),
        );
        self::assertSame('SELECT "schema".*, count(*) AS "total" FROM "users"', $sql);
    }

    /**
     * An aggregate over a column wraps the inner column.
     */
    public function testAggregateWithColumn(): void
    {
        $sql = (new SqliteGrammar())->compileSelect(
            $this->builder()->select('SUM(total)', 'SUM(total) as grand_total'),
        );
        self::assertSame('SELECT SUM("total"), SUM("total") AS "grand_total" FROM "users"', $sql);
    }

    /**
     * A distinct aggregate wraps only the column, not the DISTINCT keyword.
     */
    public function testDistinctAggregate(): void
    {
        $sql = (new SqliteGrammar())->compileSelect(
            $this->builder()->select('COUNT(DISTINCT user_id) as cnt'),
        );
        self::assertSame('SELECT COUNT(DISTINCT "user_id") AS "cnt" FROM "users"', $sql);
    }

    /**
     * A from subquery is wrapped in parentheses with an alias.
     */
    public function testFromSub(): void
    {
        $sub = $this->builder('orders')->select('user_id')->where('total', WhereOperator::Gt, 100);
        $sql = (new SqliteGrammar())->compileSelect($this->builder()->fromSub($sub, 'o'));
        self::assertSame('SELECT * FROM (SELECT "user_id" FROM "orders" WHERE "total" > ?) AS "o"', $sql);
    }

    /**
     * Joins render with their on conditions.
     */
    public function testJoins(): void
    {
        $sql = (new SqliteGrammar())->compileSelect(
            $this->builder()
                ->join('posts', 'posts.user_id', '=', 'users.id')
                ->leftJoin('comments', 'comments.post_id', '=', 'posts.id'),
        );
        self::assertSame(
            'SELECT * FROM "users" INNER JOIN "posts" ON "posts"."user_id" = "users"."id" LEFT JOIN "comments" ON "comments"."post_id" = "posts"."id"',
            $sql,
        );
    }

    /**
     * A cross join has no on condition.
     */
    public function testCrossJoin(): void
    {
        $sql = (new SqliteGrammar())->compileSelect($this->builder()->crossJoin('roles'));
        self::assertSame('SELECT * FROM "users" CROSS JOIN "roles"', $sql);
    }

    /**
     * on() appends an AND-connected condition to the most recent join.
     */
    public function testOnAppendsCondition(): void
    {
        $sql = (new SqliteGrammar())->compileSelect(
            $this->builder()
                ->join('posts', 'posts.user_id', '=', 'users.id')
                ->on('posts.active', '=', 'users.active'),
        );
        self::assertSame(
            'SELECT * FROM "users" INNER JOIN "posts" ON "posts"."user_id" = "users"."id" AND "posts"."active" = "users"."active"',
            $sql,
        );
    }

    /**
     * orOn() renders an OR connector between join conditions.
     */
    public function testOrOn(): void
    {
        $sql = (new SqliteGrammar())->compileSelect(
            $this->builder()
                ->leftJoin('posts', 'posts.user_id', '=', 'users.id')
                ->on('posts.active', '=', 'users.active')
                ->orOn('posts.visible', '=', 'users.admin'),
        );
        self::assertSame(
            'SELECT * FROM "users" LEFT JOIN "posts" ON "posts"."user_id" = "users"."id" AND "posts"."active" = "users"."active" OR "posts"."visible" = "users"."admin"',
            $sql,
        );
    }

    /**
     * on() targets only the join it follows — an earlier join keeps its own conditions.
     */
    public function testOnTargetsLastJoin(): void
    {
        $sql = (new SqliteGrammar())->compileSelect(
            $this->builder()
                ->join('posts', 'posts.user_id', '=', 'users.id')
                ->leftJoin('comments', 'comments.post_id', '=', 'posts.id')
                ->on('comments.approved', '=', 'posts.approved'),
        );
        self::assertSame(
            'SELECT * FROM "users" INNER JOIN "posts" ON "posts"."user_id" = "users"."id" LEFT JOIN "comments" ON "comments"."post_id" = "posts"."id" AND "comments"."approved" = "posts"."approved"',
            $sql,
        );
    }

    /**
     * on() before any join is a LogicException — an ON belongs to the join it follows.
     */
    public function testOnWithoutJoinThrows(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Cannot call on()/orOn() before a join');
        $this->builder()->on('a.id', '=', 'b.id');
    }

    /**
     * A non-column operator in on() is rejected like the join condition itself.
     */
    public function testOnRejectsNonColumnOperator(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->builder()
            ->join('posts', 'posts.user_id', '=', 'users.id')
            ->on('posts.user_id', 'IN', 'users.id');
    }

    /**
     * Basic, in, null, between, raw, column, and nested wheres compile.
     */
    public function testWheres(): void
    {
        $sql = (new SqliteGrammar())->compileSelect(
            $this->builder()
                ->where('active', WhereOperator::Eq, 1)
                ->whereIn('role', ['admin', 'editor'])
                ->whereNull('deleted_at')
                ->whereBetween('age', [18, 65])
                ->whereRaw('lower(email) = ?', ['a@b.c'])
                ->whereColumn('updated_at', '>', 'created_at')
                ->whereNested(function (WhereBuilder $q): void {
                    $q->where('a', WhereOperator::Eq, 1)->orWhere('b', WhereOperator::Eq, 2);
                }),
        );
        self::assertSame(
            'SELECT * FROM "users" WHERE "active" = ? AND "role" IN (?, ?) AND "deleted_at" IS NULL AND "age" BETWEEN ? AND ? AND lower(email) = ? AND "updated_at" > "created_at" AND ("a" = ? OR "b" = ?)',
            $sql,
        );
    }

    /**
     * orWhereNested() appends OR-connected parenthesized groups — the
     * tuple-match shape: (a = ? AND b = ?) OR (a = ? AND b = ?).
     */
    public function testOrWhereNested(): void
    {
        $sql = (new SqliteGrammar())->compileSelect(
            $this->builder()
                ->where('tenant', WhereOperator::Eq, 7)
                ->orWhereNested(function (WhereBuilder $q): void {
                    $q->where('region_id', WhereOperator::Eq, 1)->where('country', WhereOperator::Eq, 'US');
                })
                ->orWhereNested(function (WhereBuilder $q): void {
                    $q->where('region_id', WhereOperator::Eq, 2)->where('country', WhereOperator::Eq, 'DE');
                }),
        );

        self::assertSame(
            'SELECT * FROM "users" WHERE "tenant" = ? OR ("region_id" = ? AND "country" = ?) OR ("region_id" = ? AND "country" = ?)',
            $sql,
        );
        self::assertSame([7, 1, 'US', 2, 'DE'], $this->lastBuilder->getBindings());
    }

    /**
     * Group by and having compile with aggregate columns.
     */
    public function testGroupByHaving(): void
    {
        $sql = (new SqliteGrammar())->compileSelect(
            $this->builder()
                ->select('status', 'count(*) as total')
                ->groupBy('status')
                ->having('count(*)', WhereOperator::Gt, 5),
        );
        self::assertSame(
            'SELECT "status", count(*) AS "total" FROM "users" GROUP BY "status" HAVING count(*) > ?',
            $sql,
        );
    }

    /**
     * Order by compiles multiple clauses with directions.
     */
    public function testOrders(): void
    {
        $sql = (new SqliteGrammar())->compileSelect(
            $this->builder()->orderBy('name')->orderBy('created_at', 'DESC'),
        );
        self::assertSame('SELECT * FROM "users" ORDER BY "name" ASC, "created_at" DESC', $sql);
    }

    /**
     * A raw order-by expression is spliced verbatim.
     */
    public function testOrderByRaw(): void
    {
        $sql = (new SqliteGrammar())->compileSelect(
            $this->builder()->orderByRaw('FIELD(status, \'new\', \'done\')'),
        );
        self::assertSame("SELECT * FROM \"users\" ORDER BY FIELD(status, 'new', 'done')", $sql);
    }

    /**
     * Limit and offset compile.
     */
    public function testLimitOffset(): void
    {
        $sql = (new SqliteGrammar())->compileSelect($this->builder()->limit(10)->offset(20));
        self::assertSame('SELECT * FROM "users" LIMIT 10 OFFSET 20', $sql);
    }

    /**
     * MySQL pads a bare offset with the unsigned-bigint maximum.
     */
    public function testMySqlBareOffsetPadded(): void
    {
        $sql = (new MySqlGrammar())->compileSelect($this->builder()->offset(20));
        self::assertSame('SELECT * FROM `users` LIMIT 18446744073709551615 OFFSET 20', $sql);
    }

    /**
     * Postgres allows a bare offset without padding.
     */
    public function testPostgresBareOffset(): void
    {
        $sql = (new PostgresGrammar())->compileSelect($this->builder()->offset(20));
        self::assertSame('SELECT * FROM "users" OFFSET 20', $sql);
    }

    /**
     * Unions append after the select.
     */
    public function testUnion(): void
    {
        $first = $this->builder('users')->select('name');
        $second = $this->builder('archived_users')->select('name');
        $sql = (new SqliteGrammar())->compileSelect($first->union($second));
        self::assertSame(
            'SELECT "name" FROM "users" UNION (SELECT "name" FROM "archived_users")',
            $sql,
        );
    }

    /**
     * Union all renders the ALL keyword.
     */
    public function testUnionAll(): void
    {
        $first = $this->builder('users')->select('name');
        $second = $this->builder('archived_users')->select('name');
        $sql = (new SqliteGrammar())->compileSelect($first->union($second, true));
        self::assertSame(
            'SELECT "name" FROM "users" UNION ALL (SELECT "name" FROM "archived_users")',
            $sql,
        );
    }

    // ---- Locks ----

    /**
     * MySQL renders for update and lock in share mode.
     */
    public function testMySqlLocks(): void
    {
        self::assertSame(
            'SELECT * FROM `users` FOR UPDATE',
            (new MySqlGrammar())->compileSelect($this->builder()->lockForUpdate()),
        );
        self::assertSame(
            'SELECT * FROM `users` LOCK IN SHARE MODE',
            (new MySqlGrammar())->compileSelect($this->builder()->sharedLock()),
        );
    }

    /**
     * Postgres renders for update and for share.
     */
    public function testPostgresLocks(): void
    {
        self::assertSame(
            'SELECT * FROM "users" FOR UPDATE',
            (new PostgresGrammar())->compileSelect($this->builder()->lockForUpdate()),
        );
        self::assertSame(
            'SELECT * FROM "users" FOR SHARE',
            (new PostgresGrammar())->compileSelect($this->builder()->sharedLock()),
        );
    }

    /**
     * SQLite has no row locks — a lock request fails fast.
     */
    public function testSqliteLockThrows(): void
    {
        $this->expectException(UnsupportedFeatureException::class);
        $this->expectExceptionMessage('does not support row locks');
        (new SqliteGrammar())->compileSelect($this->builder()->lockForUpdate());
    }

    // ---- Insert ----

    /**
     * A single-row insert compiles with placeholders.
     */
    public function testInsertSingleRow(): void
    {
        $sql = (new SqliteGrammar())->compileInsert($this->builder(), ['name' => 'Alice', 'active' => 1]);
        self::assertSame('INSERT INTO "users" ("name", "active") VALUES (?, ?)', $sql);
    }

    /**
     * A multi-row insert compiles one placeholder group per row.
     */
    public function testInsertMultiRow(): void
    {
        $sql = (new SqliteGrammar())->compileInsert($this->builder(), [
            ['name' => 'Alice'],
            ['name' => 'Bob'],
        ]);
        self::assertSame('INSERT INTO "users" ("name") VALUES (?), (?)', $sql);
    }

    /**
     * SQLite appends RETURNING when the PK is known.
     */
    public function testInsertReturningSqlite(): void
    {
        $sql = (new SqliteGrammar())->compileInsert($this->builder(), ['name' => 'Alice'], 'id');
        self::assertSame('INSERT INTO "users" ("name") VALUES (?) RETURNING "id"', $sql);
    }

    /**
     * MySQL does not append RETURNING.
     */
    public function testInsertNoReturningMySql(): void
    {
        $sql = (new MySqlGrammar())->compileInsert($this->builder(), ['name' => 'Alice'], 'id');
        self::assertSame('INSERT INTO `users` (`name`) VALUES (?)', $sql);
    }

    /**
     * An EMPTY row compiles to the SQL-standard DEFAULT VALUES form on
     * dialects that support it — the degenerate `() VALUES ()` is invalid
     * SQL on SQLite/Postgres.
     */
    public function testInsertEmptyRowUsesDefaultValues(): void
    {
        $sql = (new SqliteGrammar())->compileInsert($this->builder(), [[]]);
        self::assertSame('INSERT INTO "users" DEFAULT VALUES', $sql);
    }

    /**
     * MySQL has no DEFAULT VALUES form — the one-row `VALUES ()` fallback,
     * which MySQL accepts and applies the column defaults to.
     */
    public function testInsertEmptyRowMySqlFallsBackToValues(): void
    {
        $sql = (new MySqlGrammar())->compileInsert($this->builder(), [[]]);
        self::assertSame('INSERT INTO `users` () VALUES ()', $sql);
    }

    // ---- Update / Delete ----

    /**
     * An update compiles its set list and wheres.
     */
    public function testUpdate(): void
    {
        $sql = (new SqliteGrammar())->compileUpdate(
            $this->builder()->where('id', WhereOperator::Eq, 1),
            ['name' => 'Alicia'],
        );
        self::assertSame('UPDATE "users" SET "name" = ? WHERE "id" = ?', $sql);
    }

    /**
     * A delete compiles its wheres.
     */
    public function testDelete(): void
    {
        $sql = (new SqliteGrammar())->compileDelete(
            $this->builder()->where('id', WhereOperator::Eq, 1),
        );
        self::assertSame('DELETE FROM "users" WHERE "id" = ?', $sql);
    }

    /**
     * An update without wheres compiles without a where clause.
     */
    public function testUpdateWithoutWheres(): void
    {
        $sql = (new SqliteGrammar())->compileUpdate($this->builder(), ['active' => 0]);
        self::assertSame('UPDATE "users" SET "active" = ?', $sql);
    }

    // ---- Values ----

    /**
     * A ToSqlValue is inlined as a quoted literal, not a placeholder.
     */
    public function testToSqlValueInlined(): void
    {
        $value = new /** A value object that renders as a SQL scalar. */ class implements ToSqlValue {
            /**
             * The scalar this value represents.
             *
             * @return string The scalar.
             */
            public function toSqlValue(): string
            {
                return 'abc-123';
            }
        };
        $sql = (new SqliteGrammar())->compileSelect(
            $this->builder()->where('uuid', WhereOperator::Eq, $value),
        );
        self::assertSame("SELECT * FROM \"users\" WHERE \"uuid\" = 'abc-123'", $sql);
    }

    /**
     * An Expression value is spliced verbatim.
     */
    public function testExpressionValueSpliced(): void
    {
        $sql = (new SqliteGrammar())->compileSelect(
            $this->builder()->where('created_at', WhereOperator::Gt, new Expression('now()')),
        );
        self::assertSame('SELECT * FROM "users" WHERE "created_at" > now()', $sql);
    }

    /**
     * An Expression value is inlined and never added to the bindings.
     */
    public function testExpressionNotInBindings(): void
    {
        $builder = $this->builder()->where('created_at', WhereOperator::Gt, new Expression('now()'));
        self::assertSame([], $builder->getBindings());
    }

    /**
     * A ToSqlValue is inlined and never added to the bindings.
     */
    public function testToSqlValueNotInBindings(): void
    {
        $value = new /** A value object that renders as a SQL scalar. */ class implements ToSqlValue {
            /**
             * The scalar this value represents.
             *
             * @return string The scalar.
             */
            public function toSqlValue(): string
            {
                return 'abc-123';
            }
        };
        $builder = $this->builder()->where('uuid', WhereOperator::Eq, $value);
        self::assertSame([], $builder->getBindings());
    }

    /**
     * A from subquery cannot be replaced once set.
     */
    public function testFromSubSetOnce(): void
    {
        $sub = $this->builder('orders');
        $builder = $this->builder()->fromSub($sub, 'o');
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('already set and cannot be changed');
        $builder->fromSub($this->builder('other'), 'x');
    }

    // ---- Bindings ----

    /**
     * Bindings are stored per category and flattened in canonical order.
     */
    public function testBindingsFlattenedInOrder(): void
    {
        $builder = $this->builder()
            ->where('active', WhereOperator::Eq, 1)
            ->whereIn('role', ['admin', 'editor'])
            ->whereBetween('age', [18, 65])
            ->whereRaw('lower(email) = ?', ['a@b.c'])
            ->whereNested(function (WhereBuilder $q): void {
                $q->where('a', WhereOperator::Eq, 1)->orWhere('b', WhereOperator::Eq, 2);
            });

        self::assertSame([1, 'admin', 'editor', 18, 65, 'a@b.c', 1, 2], $builder->getBindings());
    }

    /**
     * Only the requested binding categories are flattened.
     */
    public function testBindingsFilteredByCategory(): void
    {
        $builder = $this->builder()
            ->where('active', WhereOperator::Eq, 1)
            ->having('count(*)', WhereOperator::Gt, 5);

        self::assertSame([1], $builder->getBindings([BindingCategory::Where]));
        self::assertSame([5], $builder->getBindings([BindingCategory::Having]));
    }

    /**
     * An update flattens only the join and where bindings, plus the values.
     */
    public function testUpdateBindings(): void
    {
        $builder = $this->builder()
            ->where('id', WhereOperator::Eq, 1)
            ->having('count(*)', WhereOperator::Gt, 5);

        $sql = (new SqliteGrammar())->compileUpdate($builder, ['name' => 'Alicia']);
        self::assertSame('UPDATE "users" SET "name" = ? WHERE "id" = ?', $sql);
        self::assertSame(['Alicia', 1], array_merge(array_values(['name' => 'Alicia']), $builder->getBindings([BindingCategory::Join, BindingCategory::Where])));
    }
}