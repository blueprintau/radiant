<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Query;

use BlueprintAU\Radiant\Database\Connections\SqliteConnection;
use BlueprintAU\Radiant\Database\Query\Aggregate;
use BlueprintAU\Radiant\Database\Query\Enums\WhereOperator;
use BlueprintAU\Radiant\Database\Query\Expression;
use BlueprintAU\Radiant\Database\Query\QueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Exercise the QueryBuilder edge arms — join guards, list homogeneity,
 * null/list guards, the mutation-callback contract and aggregate reads.
 */
final class QueryBuilderEdgeCasesTest extends TestCase
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
        $this->connection->statement(
            'CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, age INTEGER)',
        );
        $this->connection->statement(
            'CREATE TABLE teams (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)',
        );
        $this->connection->statement("INSERT INTO users (name, age) VALUES ('Alice', 30)");
        $this->connection->statement("INSERT INTO users (name, age) VALUES ('Bob', 40)");
    }

    // ---- Joins ----

    /**
     * rightJoin() compiles a RIGHT JOIN.
     */
    public function testRightJoinCompiles(): void
    {
        $sql = $this->connection->grammar->compileSelect(
            $this->connection->table('users')
                ->rightJoin('teams', 'users.team_id', '=', 'teams.id'),
        );

        self::assertStringContainsString('RIGHT JOIN', $sql);
    }

    /**
     * on() before any join fails fast — an ON condition belongs to the
     * join it follows.
     */
    public function testOnBeforeJoinThrows(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains(
            'Cannot call on()/orOn() before a join: an ON condition belongs to the join it follows.',
        );

        $this->connection->table('users')->on('users.team_id', '=', 'teams.id');
    }

    // ---- List guards ----

    /**
     * A mixed plain/Expression list fails the homogeneity guard.
     */
    public function testWhereInRejectsMixedList(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'whereIn()/whereNotIn() require a list of ALL plain values or ALL raw SQL expressions',
        );

        $this->connection->table('users')
            ->where('id', WhereOperator::In, [1, new Expression('2')])
            ->get();
    }

    /**
     * A mixed plain/Expression range fails the homogeneity guard.
     */
    public function testWhereBetweenRejectsMixedList(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'whereBetween()/whereNotBetween() require a list of ALL plain values or ALL raw SQL expressions',
        );

        $this->connection->table('users')
            ->where('age', WhereOperator::Between, [20, new Expression('30')])
            ->get();
    }

    /**
     * A non-array value with the Between operator fails the shape guard.
     */
    public function testWhereBetweenRejectsNonArray(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'whereBetween()/whereNotBetween() require a two-value [min, max] array; got string.',
        );

        $this->connection->table('users')
            ->where('age', WhereOperator::Between, '20')
            ->get();
    }

    /**
     * A wrong-arity range fails the shape guard.
     */
    public function testWhereBetweenRejectsWrongArity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'require a two-value [min, max] array; got array.',
        );

        $this->connection->table('users')
            ->where('age', WhereOperator::Between, [20, 30, 40])
            ->get();
    }

    /**
     * A null value with a comparison operator fails — SQL NULL comparisons
     * are UNKNOWN.
     */
    public function testWhereRejectsNullWithComparisonOperator(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            "where('age', '=', null) can never match — SQL comparisons against NULL"
            . " are UNKNOWN. Use whereNull('age') or whereNotNull('age') instead.",
        );

        $this->connection->table('users')->where('age', WhereOperator::Eq, null);
    }

    /**
     * A list value with a comparison operator fails — lists belong to
     * whereIn/whereBetween.
     */
    public function testWhereRejectsListWithComparisonOperator(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            "where('age', '=', list) — list values belong to whereIn()/whereNotIn()"
            . ' or whereBetween()/whereNotBetween().',
        );

        $this->connection->table('users')->where('age', WhereOperator::Eq, [20, 30]);
    }

    // ---- whereNested callback contract ----

    /**
     * A mutation-style whereNested callback (returns nothing) fails fast.
     */
    public function testWhereNestedRejectsMutationCallback(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'whereNested() callback must RETURN the WhereBuilder it received',
        );

        $this->connection->table('users')->whereNested(
            /** @phpstan-ignore argument.type (deliberately null-returning — the rejection is the test) */
            function (\BlueprintAU\Radiant\Database\Query\WhereBuilder $builder) {
                $builder->where('age', WhereOperator::Gt, 20); // not returned

                return null; // mutation-style — the RETURN check fires first
            },
        );
    }

    // ---- Aggregates & scalar reads ----

    /**
     * value() with an Aggregate reads the aliased scalar.
     */
    public function testValueWithAggregate(): void
    {
        $avg = $this->connection->table('users')->value(Aggregate::avg('age'));

        self::assertSame(35.0, $avg);
    }

    /**
     * avg() computes over the column.
     */
    public function testAvgComputes(): void
    {
        self::assertSame(35.0, $this->connection->table('users')->avg('age'));
    }

    /**
     * exists() probes with a limit-1 select.
     */
    public function testExistsProbes(): void
    {
        self::assertTrue($this->connection->table('users')->where('age', WhereOperator::Gt, 35)->exists());
        self::assertFalse($this->connection->table('users')->where('age', WhereOperator::Gt, 100)->exists());
    }

    /**
     * aggregates() over a GROUPED query matching no rows throws — a
     * grouped aggregate yields no row at all (an ungrouped COUNT seeds a
     * zero row instead).
     */
    public function testAggregatesOverNoRowsThrows(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains(
            'aggregates() cannot run — the query matched no rows to aggregate',
        );

        $this->connection->table('users')
            ->where('age', WhereOperator::Gt, 100)
            ->groupBy('age')
            ->aggregates(Aggregate::count());
    }

    // ---- fromSub ----

    /**
     * fromSub() twice fails — the from is already set.
     */
    public function testFromSubTwiceThrows(): void
    {
        $sub = $this->connection->table('users')->select('id');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains(
            'The query from is already set and cannot be changed.',
        );

        $this->connection->table('teams')
            ->fromSub($sub, 'u')
            ->fromSub($sub, 'u2');
    }
}
