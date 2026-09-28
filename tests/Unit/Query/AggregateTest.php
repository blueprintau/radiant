<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Query;

use BlueprintAU\Radiant\Database\Query\Aggregate;
use BlueprintAU\Radiant\Database\Query\Expression;
use PHPUnit\Framework\TestCase;

/**
 * {@see Aggregate} — the typed aggregate value object: its factories,
 * its declaration-time validation (function / column / alias), and the
 * Expression escape hatch that skips column validation by contract.
 */
final class AggregateTest extends TestCase
{
    /**
     * The five universal factories produce the expected function/column/
     * alias triple; a null alias stays null (the caller derives it).
     *
     * @return iterable<string, array{Aggregate, string, string, string|null}>
     */
    public static function factoryProvider(): iterable
    {
        yield 'count all' => [Aggregate::count(), 'count', '*', null];
        yield 'count column' => [Aggregate::count('views'), 'count', 'views', null];
        yield 'count aliased' => [Aggregate::count('*', 'total'), 'count', '*', 'total'];
        yield 'max' => [Aggregate::max('age'), 'max', 'age', null];
        yield 'min' => [Aggregate::min('age'), 'min', 'age', null];
        yield 'sum' => [Aggregate::sum('views'), 'sum', 'views', null];
        yield 'avg' => [Aggregate::avg('views'), 'avg', 'views', null];
        yield 'avg aliased' => [Aggregate::avg('views', 'mean_views'), 'avg', 'views', 'mean_views'];
    }

    /**
     * Every factory reports its function, column and alias verbatim.
     *
     * @param  Aggregate  $aggregate
     * @param  string  $function
     * @param  string  $column
     * @param  string|null  $alias
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('factoryProvider')]
    public function testFactories(Aggregate $aggregate, string $function, string $column, ?string $alias): void
    {
        self::assertSame($function, $aggregate->function);
        self::assertSame($column, $aggregate->column);
        self::assertSame($alias, $aggregate->alias);
    }

    /**
     * Qualified column paths are valid: `users.age` and `users.*`.
     */
    public function testQualifiedColumnPathsPass(): void
    {
        $qualified = new Aggregate('sum', 'users.age');
        self::assertSame('users.age', $qualified->column);

        $wildcardTable = new Aggregate('count', 'users.*');
        self::assertSame('users.*', $wildcardTable->column);
    }

    /**
     * `distinct <path>` is a validated declaration shape.
     */
    public function testDistinctColumnPasses(): void
    {
        $distinct = new Aggregate('count', 'distinct region_id');
        self::assertSame('distinct region_id', $distinct->column);
    }

    /**
     * An Expression column is raw SQL by contract — it passes through
     * unvalidated.
     */
    public function testExpressionColumnPassesUnvalidated(): void
    {
        $expression = new Expression('coalesce(region_id, 0)');
        $aggregate = new Aggregate('sum', $expression);

        self::assertSame($expression, $aggregate->column);
    }

    /**
     * A function that is not a bare SQL identifier rejects — it would be
     * spliced into compiled SQL verbatim.
     */
    public function testInvalidFunctionThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'Aggregate function must be a bare SQL identifier; got [sum(; drop table users)].',
        );

        new Aggregate('sum(; drop table users)', 'age');
    }

    /**
     * An empty function name rejects too (not a bare identifier).
     */
    public function testEmptyFunctionThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'Aggregate function must be a bare SQL identifier; got [].',
        );

        new Aggregate('', 'age');
    }

    /**
     * A column that is not *, an identifier path, `distinct <path>`, or an
     * Expression rejects — free text would be spliced into compiled SQL.
     */
    public function testInvalidColumnThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'Aggregate column must be *, an identifier path, `distinct <path>`, or an '
            . 'Expression; got [age; drop table users].',
        );

        new Aggregate('sum', 'age; drop table users');
    }

    /**
     * An alias that is not a bare identifier rejects.
     */
    public function testInvalidAliasThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'Aggregate alias must be a bare identifier; got [mean views].',
        );

        new Aggregate('avg', 'views', 'mean views');
    }
}
