<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Concerns;

use BlueprintAU\Radiant\Database\Query\Enums\ColumnOperator;
use BlueprintAU\Radiant\Database\Query\Expression;
use BlueprintAU\Radiant\Database\Query\WhereBuilder;
use BlueprintAU\Radiant\Tests\Support\DatabaseTestCase;
use BlueprintAU\Radiant\Tests\Unit\Concerns\Fixtures\FvPost;
use BlueprintAU\Radiant\Tests\Unit\Concerns\Fixtures\FvUser;

/**
 * The filter-vocabulary sugar methods not exercised by
 * {@see FilterVocabularyTest}: the LIKE family, the nested-group aliases,
 * and the static side's remaining one-line forwarders.
 *
 * Static side ({@see \BlueprintAU\Radiant\Concerns\FiltersStaticQuery}) via
 * `FvUser::method()`; instance side ({@see \BlueprintAU\Radiant\Concerns\FiltersWhere})
 * via `$user->posts()->method()`; the WhereBuilder facade via
 * `whereNested()` closures. Results are asserted against live SQLite.
 */
final class FilterSugarTest extends DatabaseTestCase
{
    /**
     * Create the fixture tables and seed three users + four posts.
     */
    protected function setUpDatabase(): void
    {
        $this->createTables(FvUser::class, FvPost::class);

        foreach ([['alicia', 30], ['ben', 40], ['cara', null]] as [$name, $age]) {
            $user = new FvUser();
            $user->name = $name;
            $user->age = $age;
            $user->save();
        }

        $seed = [
            ['alicia', 'Alpha one', 100], ['alicia', 'alpha two', 200],
            ['ben', 'Beta x.y', 300], ['cara', 'gamma?!', 400],
        ];
        foreach ($seed as [$author, $title, $views]) {
            $user = FvUser::where('name', '=', $author)->first();
            self::assertNotNull($user);
            $post = new FvPost();
            $post->userId = $user->id;
            $post->title = $title;
            $post->views = $views;
            $post->save();
        }
    }

    /**
     * Collect one column's values in insertion (id) order.
     *
     * @param  iterable<mixed> $rows
     * @param  string  $property
     * @return list<mixed>
     */
    private function columnValues(iterable $rows, string $property): array
    {
        $values = [];

        foreach ($rows as $row) {
            $values[] = $row->{$property};
        }

        return $values;
    }

    // ---- Static side: LIKE family ----

    /**
     * Static orWhereEq() called STATICALLY (not chained off a builder) —
     * the trait's own forwarder into the where() sink with the OR boolean.
     */
    public function testStaticOrWhereEq(): void
    {
        $rows = FvUser::orWhereEq('name', 'ben')->orderBy('id')->get();

        self::assertSame(['ben'], $this->columnValues($rows, 'name'));
    }

    /**
     * Static orWhere() called STATICALLY — the generic OR forwarder.
     */
    public function testStaticOrWhere(): void
    {
        $rows = FvUser::orWhere('age', '>', 35)->orderBy('id')->get();

        self::assertSame(['ben'], $this->columnValues($rows, 'name'));
    }

    /**
     * Static orWhereNested() called STATICALLY — the OR group alias.
     */
    public function testStaticOrWhereNestedAlias(): void
    {
        $rows = FvUser::orWhereNested(function (WhereBuilder $nested): WhereBuilder {
            return $nested->whereEq('name', 'ben')->whereNotNull('age');
        })->orderBy('id')->get();

        self::assertSame(['ben'], $this->columnValues($rows, 'name'));
    }

    /**
     * Static orWhereLike() called STATICALLY — the OR LIKE alias.
     */
    public function testStaticOrWhereLikeAlias(): void
    {
        $rows = FvUser::orWhereLike('name', '%ra')->orderBy('id')->get();

        self::assertSame(['cara'], $this->columnValues($rows, 'name'));
    }

    /**
     * Static whereLike() matches the bound pattern; the pattern is a
     * binding, not spliced SQL.
     */
    public function testStaticWhereLike(): void
    {
        $rows = FvUser::whereLike('name', 'ali%')->orderBy('id')->get();

        self::assertSame(['alicia'], $this->columnValues($rows, 'name'));
    }

    /**
     * Static orWhereLike() composes at the edges. The `%` wildcard keeps
     * the match inside the OR arm (a plain prefix would also do — the
     * point is the OR composition, not the wildcard).
     */
    public function testStaticOrWhereLike(): void
    {
        $rows = FvUser::whereEq('name', 'ben')
            ->orWhereLike('name', '%ra')
            ->orderBy('id')
            ->get();

        self::assertSame(['ben', 'cara'], $this->columnValues($rows, 'name'));
    }

    /**
     * Static whereNotLike() excludes the pattern.
     */
    public function testStaticWhereNotLike(): void
    {
        $rows = FvUser::whereNotLike('name', '%a%')->orderBy('id')->get();

        self::assertSame(['ben'], $this->columnValues($rows, 'name'));
    }

    // ---- Static side: nested-group aliases ----

    /**
     * Static whereNestedGroup() is the AND default of whereNested() — the
     * group is one constraint unit. The callback RETURNS the builder it
     * received (the immutable-builder contract the runtime enforces).
     */
    public function testStaticWhereNestedGroup(): void
    {
        $rows = FvUser::whereNestedGroup(function (WhereBuilder $nested): WhereBuilder {
            return $nested->whereEq('name', 'alicia');
        })->orderBy('id')->get();

        self::assertSame(['alicia'], $this->columnValues($rows, 'name'));
    }

    /**
     * Static orWhereNested() prefixes the GROUP with OR — the columns
     * inside stay AND-bound.
     */
    public function testStaticOrWhereNested(): void
    {
        $rows = FvUser::whereEq('name', 'alicia')
            ->orWhereNested(function (WhereBuilder $nested): WhereBuilder {
                return $nested->whereEq('name', 'ben')->whereNotNull('age');
            })
            ->orderBy('id')
            ->get();

        self::assertSame(['alicia', 'ben'], $this->columnValues($rows, 'name'));
    }

    // ---- Instance side: LIKE family + nested aliases ----

    /**
     * Instance whereLike()/whereNotLike() on a relation. SQLite's LIKE is
     * case-insensitive for ASCII — the NOT-LIKE arm must exclude both
     * spellings.
     */
    public function testInstanceLikeFamily(): void
    {
        $user = FvUser::where('name', '=', 'alicia')->first();
        self::assertNotNull($user);

        self::assertSame(
            ['Alpha one', 'alpha two'],
            $this->columnValues($user->posts()->whereLike('title', 'Alpha%')->get(), 'title'),
        );

        self::assertSame(
            [],
            $this->columnValues($user->posts()->whereNotLike('title', 'alpha%')->get(), 'title'),
        );

        $ben = FvUser::where('name', '=', 'ben')->first();
        self::assertNotNull($ben);
        self::assertSame(
            [],
            $this->columnValues($ben->posts()->whereNotLike('title', 'Beta%')->get(), 'title'),
        );
    }

    /**
     * Instance whereNestedGroup()/orWhereNested() on a relation — the OR
     * prefixes the group.
     */
    public function testInstanceNestedGroupAliases(): void
    {
        $user = FvUser::where('name', '=', 'alicia')->first();
        self::assertNotNull($user);

        $andGroup = $user->posts()->whereNestedGroup(
            function (WhereBuilder $nested): WhereBuilder {
                return $nested->whereEq('title', 'Alpha one');
            },
        )->get();

        self::assertSame(['Alpha one'], $this->columnValues($andGroup, 'title'));

        $ben = FvUser::where('name', '=', 'ben')->first();
        self::assertNotNull($ben);

        $orGroup = $ben->posts()->whereEq('title', 'no match')
            ->orWhereNested(function (WhereBuilder $nested): WhereBuilder {
                return $nested->whereLike('title', 'Beta%');
            })
            ->get();

        self::assertSame(['Beta x.y'], $this->columnValues($orGroup, 'title'));
    }

    // ---- WhereBuilder facade: whereRaw / whereColumn / getWheres ----

    /**
     * The WhereBuilder facade exposes whereRaw() and whereColumn() inside
     * nested groups; getWheres() reads the owning query's clause list.
     * The facade is immutable — each call's RESULT is threaded forward
     * (discarding it drops the clause, exactly like the base builder).
     */
    public function testWhereBuilderFacadeInsideNestedGroup(): void
    {
        $rows = FvUser::whereNested(function (WhereBuilder $nested): WhereBuilder {
            $withRaw = $nested->whereRaw('age > ?', [25]);
            $withColumn = $withRaw->whereColumn('name', ColumnOperator::Eq, 'name');

            self::assertCount(2, $withColumn->getWheres());

            return $withColumn;
        })
            ->orderBy('id')
            ->get();

        // age > 25 keeps alicia (30) and ben (40); cara's NULL age is
        // excluded by the comparison.
        self::assertSame(['alicia', 'ben'], $this->columnValues($rows, 'name'));
    }

    /**
     * The facade's where() returns a NEW facade (immutable host), and the
     * nested query reachable via getNestedQuery() accumulates the clauses.
     */
    public function testWhereBuilderFacadeIsImmutable(): void
    {
        FvUser::whereNested(function (WhereBuilder $nested): WhereBuilder {
            $first = $nested->whereEq('name', 'alicia');
            $second = $first->orWhereEq('name', 'ben');

            self::assertNotSame($nested, $first);
            self::assertNotSame($first, $second);

            self::assertCount(2, $second->getWheres());

            return $second;
        })
            ->orderBy('id')
            ->get()
            ->each(function (FvUser $row): void {
                // Touch every row so the builder genuinely ran.
                self::assertGreaterThan(0, $row->id);
            });
    }

    // ---- Static side accepts Expression columns ----

    /**
     * Static where() accepts a raw Expression column — the same width as
     * the instance sink. The expression is spliced verbatim; the value
     * still binds.
     */
    public function testStaticWhereAcceptsExpressionColumn(): void
    {
        $rows = FvUser::where(new Expression('length(name)'), '=', 4)
            ->orderBy('id')
            ->get();

        self::assertSame(['cara'], $this->columnValues($rows, 'name'));
    }

    /**
     * Static orderBy() accepts a raw Expression column — the string-only
     * narrowing of the static facade is gone. NULL ages sort last under
     * SQLite's ASC nulls-last default, mirroring the instance surface.
     */
    public function testStaticOrderByAcceptsExpressionColumn(): void
    {
        $rows = FvUser::orderBy(new Expression('age is null'), 'asc')
            ->orderBy('id')
            ->get();

        self::assertSame(['alicia', 'ben', 'cara'], $this->columnValues($rows, 'name'));
    }

    /**
     * Static sugar keeps its delegation contract with a non-string
     * column: whereNull() on an Expression compiles the IS NULL arm.
     */
    public function testStaticSugarAcceptsExpressionColumn(): void
    {
        $rows = FvUser::whereNull(new Expression('age'))
            ->orderBy('id')
            ->get();

        self::assertSame(['cara'], $this->columnValues($rows, 'name'));
    }
}
