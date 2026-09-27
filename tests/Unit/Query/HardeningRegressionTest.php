<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Query;

use BlueprintAU\Radiant\Database\Grammars\MySqlGrammar;
use BlueprintAU\Radiant\Database\Query\Enums\SortDirection;
use BlueprintAU\Radiant\Database\Query\QueryBuilder;
use BlueprintAU\Radiant\Tests\Support\NullConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for the query-builder hardening:
 *
 * - orderBy() direction is enum-gated.
 * - whereColumn()/join() operators are allowlisted.
 * - whereIn([]) fails fast instead of compiling `IN ()`.
 * - union bindings are captured at compile time, in order.
 */
final class HardeningRegressionTest extends TestCase
{
    /**
     * A builder whose SQL can be compiled without touching a server.
     *
     * @return QueryBuilder The builder.
     */
    private function builder(): QueryBuilder
    {
        return new QueryBuilder(new NullConnection(), 'users');
    }

    /**
     * Injection-shaped direction payloads — all must be rejected.
     *
     * @return iterable<string, array{0: string}> The payloads.
     */
    public static function injectionProvider(): iterable
    {
        yield 'stacked delete' => ['ASC; DROP TABLE users; --'];
        yield 'comment bleed' => ['desc --'];
        yield 'subquery payload' => ["ASC, (SELECT password FROM users)"];
        yield 'empty-ish garbage' => ['  '];
        yield 'lowercase attempt is fine but this is not' => ['asc;'];
    }

    /**
     * Any direction other than ASC/DESC throws — including stacked
     * statements, comments, and subquery payloads.
     *
     * @param string $direction The injection payload.
     */
    #[DataProvider('injectionProvider')]
    public function testOrderByDirectionRejectsNonAscDesc(string $direction): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Order direction must be ASC or DESC');
        $this->builder()->orderBy('name', $direction);
    }

    /**
     * Case-insensitive ASC/DESC are accepted and normalized to upper case.
     */
    public function testOrderByAcceptsCaseInsensitiveAscDesc(): void
    {
        $b = $this->builder()
            ->orderBy('name', 'desc')
            ->orderBy('id', 'AsC');
        $orders = $b->getOrders();
        $this->assertSame(SortDirection::Desc, $orders[0]['direction']);
        $this->assertSame(SortDirection::Asc, $orders[1]['direction']);
    }

    /**
     * Injection-shaped operator payloads — all must be rejected.
     *
     * @return iterable<string, array{0: string}> The payloads.
     */
    public static function columnOperatorProvider(): iterable
    {
        yield 'or injection' => ['= 1 OR 1=1 --'];
        yield 'stacked statement' => ['=; DROP TABLE users; --'];
        yield 'word operator not allowed' => ['LIKE'];
        yield 'empty' => [''];
    }

    /**
     * whereColumn() must reject anything that is not a comparison operator.
     *
     * @param string $operator The payload.
     */
    #[DataProvider('columnOperatorProvider')]
    public function testWhereColumnRejectsNonComparisonOperators(string $operator): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid column comparison operator');
        $this->builder()->whereColumn('a', $operator, 'b');
    }

    /**
     * Join conditions must reject anything that is not a comparison operator.
     *
     * @param string $operator The payload.
     */
    #[DataProvider('columnOperatorProvider')]
    public function testJoinsRejectNonComparisonOperators(string $operator): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid column comparison operator');
        $this->builder()->join('orders', 'users.id', $operator, 'orders.user_id');
    }

    /**
     * All six comparison operators are accepted.
     */
    public function testWhereColumnAcceptsAllSixOperators(): void
    {
        $b = $this->builder();
        foreach (['=', '!=', '<', '<=', '>', '>='] as $op) {
            $b = $b->whereColumn('a', $op, 'b');
        }
        $this->assertCount(6, $b->getWheres());
    }

    /**
     * An empty IN array throws instead of compiling invalid `IN ()` SQL.
     */
    public function testWhereInEmptyArrayThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('require a non-empty array of values');
        $this->builder()->where('status', 'IN', []);
    }

    /**
     * An empty NOT IN array throws the same way.
     */
    public function testWhereNotInEmptyArrayThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('require a non-empty array of values');
        $this->builder()->where('status', 'NOT IN', []);
    }

    /**
     * A non-array IN operand throws instead of passing through.
     */
    public function testWhereInNonArrayThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('require an array of values');
        $this->builder()->where('status', 'IN', 'active');
    }

    /**
     * Injection-shaped where-operator payloads — all must be rejected.
     *
     * @return iterable<string, array{0: string}> The payloads.
     */
    public static function whereOperatorProvider(): iterable
    {
        yield 'or injection' => ['= 1 OR 1=1 --'];
        yield 'stacked statement' => ['=; DROP TABLE users; --'];
        yield 'subquery payload' => ['= (SELECT password FROM users)'];
        yield 'empty' => [''];
        yield 'whitespace garbage' => ['  '];
        yield 'unknown word' => ['REGEXP'];
    }

    /**
     * where() must reject anything that is not a WhereOperator case —
     * with a clear InvalidArgumentException, not a bare ValueError.
     *
     * @param string $operator The payload.
     */
    #[DataProvider('whereOperatorProvider')]
    public function testWhereRejectsInvalidOperators(string $operator): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid where operator');
        $this->builder()->where('a', $operator, 1);
    }

    /**
     * having() resolves through the same checked path.
     */
    public function testHavingRejectsInvalidOperators(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid where operator');
        $this->builder()->having('count', '=>', 1);
    }

    /**
     * Case-insensitive operator strings are accepted and normalized.
     */
    public function testWhereAcceptsCaseInsensitiveOperators(): void
    {
        $b = $this->builder()
            ->where('a', 'like', '%x%')
            ->where('b', 'not in', [1, 2])
            ->where('c', 'between', [1, 10]);

        $sql = (new MySqlGrammar())->compileSelect($b);

        $this->assertStringContainsString('`a` LIKE', $sql);
        $this->assertStringContainsString('`b` NOT IN', $sql);
        $this->assertStringContainsString('`c` BETWEEN', $sql);
    }

    /**
     * Union bindings are captured EAGERLY at union() call time — the
     * sub-builder is a frozen value when captured (value semantics), so
     * its placeholders match its bindings in compiled order.
     */
    public function testUnionBindingsCapturedAtUnionTime(): void
    {
        $main = $this->builder()->select('id')->where('active', '=', 1);

        $sub = new QueryBuilder(new NullConnection(), 'admins');
        $sub = $sub->select('id')->where('level', '=', 9);
        // The sub-builder is FINAL when union() is called — a builder is a
        // value, so the captured snapshot IS the builder the union
        // references.
        $main = $main->union($sub);

        $grammar = new MySqlGrammar();
        $sql = $grammar->compileSelect($main);

        $this->assertStringContainsString('UNION', $sql);
        // 2 placeholders total (main where + sub where), and the flattened
        // binding list matches them in order: [1, 9].
        $this->assertSame(2, substr_count($sql, '?'));
        $this->assertSame([1, 9], $main->getBindings());
    }
}
