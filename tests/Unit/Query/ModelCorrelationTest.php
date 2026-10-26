<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Query;

use BlueprintAU\Radiant\Database\Query\Aggregate;
use BlueprintAU\Radiant\Database\Query\SubquerySelect;
use BlueprintAU\Radiant\ModelQueryBuilder;
use BlueprintAU\Radiant\Tests\Support\DatabaseTestCase;
use BlueprintAU\Radiant\Tests\Unit\Concerns\Fixtures\FvPost;
use BlueprintAU\Radiant\Tests\Unit\Concerns\Fixtures\FvUser;

/**
 * Correlated model sub-builders: the select() mixed-list contracts and
 * the correlateWith()/correlate() vocabulary.
 *
 * Covers the proposal's three repro shapes (mixed-list `*` expansion,
 * documented replace semantics across chained select() calls, node-first
 * hydration), the correlate() accumulation contracts (same related model
 * twice, select()-after-correlate(), fail-fast declarations) and the
 * fromSub() FROM-identity rule for correlateWith().
 */
final class ModelCorrelationTest extends DatabaseTestCase
{
    /**
     * Create the fixture tables and seed three users + four posts
     * (alicia 2 posts, ben 1 post, cara 1 post with 400 views).
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
     * A correlated FvPost post-count subquery under the given alias,
     * optionally floored by a views filter (the second-count shape).
     *
     * @param  string  $alias
     * @param  int|null  $views  Extra views floor for the filtered shape.
     * @return SubquerySelect
     */
    private function postCount(string $alias, ?int $views = null): SubquerySelect
    {
        $query = FvPost::newQuery()
            ->correlateWith(FvUser::newQuery())
            ->select(Aggregate::count())
            ->whereColumn('fv_posts.user_id', '=', 'fv_users.id');

        if ($views !== null) {
            $query = $query->where('views', '>', $views);
        }

        return new SubquerySelect($query, $alias);
    }

    // ---- Phase 1: select() mixed-list contracts ----

    /**
     * A bare `*` beside a node expands to the model's columns IN PLACE —
     * hydration works and the alias rides each model.
     */
    public function testSelectStarBesideNodeHydrates(): void
    {
        $node = $this->postCount('post_count');

        $rows = FvUser::newQuery()
            ->select('*', $node)
            ->orderBy('id')
            ->get();

        self::assertCount(3, $rows);
        self::assertNotNull($rows[0]);
        self::assertNotNull($rows[1]);
        self::assertSame('alicia', $rows[0]->name);
        self::assertSame('ben', $rows[1]->name);
        self::assertSame(2, $rows[0]->attribute('post_count'));
        self::assertSame(1, $rows[1]->attribute('post_count'));
    }

    /**
     * A node FIRST with `*` after hydrates the same way — position in
     * the mixed list is irrelevant to the expansion.
     */
    public function testSelectNodeFirstThenStarHydrates(): void
    {
        $node = $this->postCount('post_count');

        $rows = FvUser::newQuery()
            ->select($node, '*')
            ->orderBy('id')
            ->get();

        self::assertCount(3, $rows);
        self::assertNotNull($rows[0]);
        self::assertSame('alicia', $rows[0]->name);
        self::assertSame(2, $rows[0]->attribute('post_count'));
    }

    /**
     * Chained select() calls REPLACE the list (documented Laravel
     * parity): the second call's specs survive, the first call's do not.
     * With `*` in the second call the model columns still expand.
     */
    public function testChainedSelectReplacesAndSecondStarExpands(): void
    {
        $n1 = $this->postCount('comment_count');
        $n2 = $this->postCount('like_count');

        $rows = FvUser::newQuery()
            ->select('*', $n1)
            ->select('*', $n2)
            ->orderBy('id')
            ->get();

        self::assertCount(3, $rows);
        self::assertNotNull($rows[0]);
        self::assertSame('alicia', $rows[0]->name);
        self::assertSame(2, $rows[0]->attribute('like_count'));

        $sql = $this->connection->grammar->compileSelect(
            FvUser::newQuery()->select('*', $n1)->select('*', $n2),
        );
        self::assertStringNotContainsString('comment_count', $sql);
        self::assertStringContainsString('like_count', $sql);
    }

    /**
     * A plain string select on the merge path keeps the forced-key
     * merge (PK present) — the node-free path is unchanged.
     */
    public function testMergePathStillMergesForcedKeys(): void
    {
        $model = FvUser::newQuery()->select('name')->first();

        self::assertNotNull($model);
        self::assertSame('alicia', $model->name);
        self::assertNull($model->attribute('age'));
    }

    // ---- Phase 2: correlateWith() FROM identity ----

    /**
     * An outer built with fromSub() names its FROM by ALIAS in the
     * compiled SQL — the correlated references must use that alias, and
     * correlate() receives that identity in its closure.
     */
    public function testCorrelateWithFromSubAliasAdmitted(): void
    {
        $outer = FvUser::newQuery()
            ->fromSub(FvUser::newQuery()->select('id', 'name', 'age'), 'recent_users')
            ->where('age', '>', 20);

        $inner = FvPost::newQuery()
            ->correlateWith($outer)
            ->select(Aggregate::count())
            ->whereColumn('fv_posts.user_id', '=', 'recent_users.id');

        $rows = $outer
            ->select(new SubquerySelect($inner, 'post_count'))
            ->orderBy('id')
            ->get();

        self::assertCount(2, $rows); // cara's age is null → excluded.
        self::assertNotNull($rows[0]);
        self::assertNotNull($rows[1]);
        self::assertSame(2, $rows[0]->attribute('post_count')); // alicia
        self::assertSame(1, $rows[1]->attribute('post_count')); // ben
    }

    /**
     * Under fromSub(), the UNDERLYING table name is runtime-dead — a
     * correlated reference to it throws at the clause call.
     */
    public function testCorrelateWithFromSubUnderlyingTableRejected(): void
    {
        $outer = FvUser::newQuery()
            ->fromSub(FvUser::newQuery()->select('id', 'name'), 'recent_users');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Unknown column [fv_users.id] on model');

        FvPost::newQuery()
            ->correlateWith($outer)
            ->whereColumn('fv_posts.user_id', '=', 'fv_users.id');
    }

    // ---- Phase 3: correlate() ----

    /**
     * Two correlate() calls on the SAME related model accumulate: both
     * aliases hydrate, model columns intact.
     */
    public function testCorrelateAccumulatesSameRelatedTwice(): void
    {
        $rows = FvUser::newQuery()
            ->correlate('post_count', FvPost::class,
                fn ($posts, $users) => $posts
                    ->select(Aggregate::count())
                    ->whereColumn('user_id', '=', "{$users}.id"))
            ->correlate('popular_count', FvPost::class,
                fn ($posts, $users) => $posts
                    ->select(Aggregate::count())
                    ->whereColumn('user_id', '=', "{$users}.id")
                    ->where('views', '>', 300))
            ->orderBy('id')
            ->get();

        self::assertCount(3, $rows);
        self::assertNotNull($rows[0]);
        self::assertNotNull($rows[1]);
        self::assertNotNull($rows[2]);
        self::assertSame('alicia', $rows[0]->name);
        self::assertSame(2, $rows[0]->attribute('post_count'));
        self::assertSame(1, $rows[1]->attribute('post_count'));
        self::assertSame(1, $rows[2]->attribute('popular_count'));
        self::assertSame(0, $rows[0]->attribute('popular_count'));
    }

    /**
     * select() AFTER correlate() re-applies the pendings — correlate()
     * survives a later explicit select.
     */
    public function testSelectAfterCorrelateKeepsPendings(): void
    {
        $rows = FvUser::newQuery()
            ->correlate('post_count', FvPost::class,
                fn ($posts, $users) => $posts
                    ->select(Aggregate::count())
                    ->whereColumn('user_id', '=', "{$users}.id"))
            ->select('name')
            ->orderBy('id')
            ->get();

        self::assertCount(3, $rows);
        self::assertNotNull($rows[0]);
        self::assertSame('alicia', $rows[0]->name);
        self::assertSame(2, $rows[0]->attribute('post_count'));
    }

    /**
     * withoutCorrelations() strips the declared correlations — the
     * spliced nodes AND the pending list — on an explicit opt-out (the
     * mirror of withoutScope(): never a select() side effect).
     */
    public function testWithoutCorrelationsStripsDeclaredNodes(): void
    {
        $counted = FvUser::newQuery()
            ->correlate('post_count', FvPost::class,
                fn ($posts, $users) => $posts
                    ->select(Aggregate::count())
                    ->whereColumn('user_id', '=', "{$users}.id"));

        $plain = $counted->withoutCorrelations()->select('name')->orderBy('id')->get();

        self::assertCount(3, $plain);
        self::assertNotNull($plain[0]);
        self::assertSame('alicia', $plain[0]->name);

        // attribute() validates against the DECLARED columns (a stripped
        // alias is no longer readable — its node is gone), so the SQL is
        // the honest assertion: the correlated scalar must not compile.
        $sql = $this->connection->grammar->compileSelect(
            $counted->withoutCorrelations()->select('name'),
        );
        self::assertStringNotContainsString('post_count', $sql);

        // Contrast: the pre-strip builder still compiles the correlation.
        $kept = $this->connection->grammar->compileSelect($counted);
        self::assertStringContainsString('post_count', $kept);
    }

    /**
     * Correlations can be declared AGAIN after a strip — the alias is
     * no longer taken.
     */
    public function testCorrelateAfterStripReusesAlias(): void
    {
        $builder = FvUser::newQuery()
            ->correlate('post_count', FvPost::class,
                fn ($posts, $users) => $posts
                    ->select(Aggregate::count())
                    ->whereColumn('user_id', '=', "{$users}.id"))
            ->withoutCorrelations()
            ->correlate('post_count', FvPost::class,
                fn ($posts, $users) => $posts
                    ->select(Aggregate::count())
                    ->whereColumn('user_id', '=', "{$users}.id")
                    ->where('views', '>', 300));

        $rows = $builder->orderBy('id')->get();

        self::assertCount(3, $rows);
        self::assertNotNull($rows[0]);
        self::assertNotNull($rows[2]);
        self::assertSame(0, $rows[0]->attribute('post_count'));
        self::assertSame(1, $rows[2]->attribute('post_count'));
    }

    /**
     * The default (no explicit select()) splices the pendings after the
     * full model column list.
     */
    public function testCorrelateOnDefaultSelect(): void
    {
        $rows = FvUser::newQuery()
            ->correlate('post_count', FvPost::class,
                fn ($posts, $users) => $posts
                    ->select(Aggregate::count())
                    ->whereColumn('user_id', '=', "{$users}.id"))
            ->orderBy('id')
            ->get();

        self::assertCount(3, $rows);
        self::assertNotNull($rows[1]);
        self::assertSame('ben', $rows[1]->name);
        self::assertSame(1, $rows[1]->attribute('post_count'));
    }

    /**
     * A build closure that returns nothing fails fast — the builder must
     * be returned (an untyped closure falling off the end returns null).
     */
    public function testCorrelateVoidBuildThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('correlate');

        FvUser::newQuery()->correlate('post_count', FvPost::class,
            function (ModelQueryBuilder $posts, string $users) {
                $posts->select(Aggregate::count());
            });
    }

    /**
     * A sub-builder selecting MULTIPLE columns fails fast — the
     * SubquerySelect contract is exactly one.
     */
    public function testCorrelateMultiColumnSubThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('exactly one column');

        FvUser::newQuery()->correlate('post_count', FvPost::class,
            fn ($posts, $users) => $posts
                ->select('id', 'title'));
    }

    /**
     * A duplicate pending alias fails fast.
     */
    public function testCorrelateDuplicateAliasThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('post_count');

        FvUser::newQuery()
            ->correlate('post_count', FvPost::class,
                fn ($posts, $users) => $posts
                    ->select(Aggregate::count())
                    ->whereColumn('user_id', '=', "{$users}.id"))
            ->correlate('post_count', FvPost::class,
                fn ($posts, $users) => $posts
                    ->select(Aggregate::count())
                    ->whereColumn('user_id', '=', "{$users}.id"));
    }

    /**
     * The reserved radiant_* prefix is rejected through correlate().
     */
    public function testCorrelateReservedPrefixAliasThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('reserved prefix');

        FvUser::newQuery()->correlate('radiant_evil', FvPost::class,
            fn ($posts, $users) => $posts
                ->select(Aggregate::count())
                ->whereColumn('user_id', '=', "{$users}.id"));
    }

    /**
     * A non-bare alias is rejected through correlate().
     */
    public function testCorrelateNonBareAliasThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('bare identifier');

        FvUser::newQuery()->correlate('post count', FvPost::class,
            fn ($posts, $users) => $posts
                ->select(Aggregate::count())
                ->whereColumn('user_id', '=', "{$users}.id"));
    }

    /**
     * A grouped query with pendings compiles and runs — splicing is
     * unconditional.
     */
    public function testGroupByWithPendingsRuns(): void
    {
        $rows = FvUser::newQuery()
            ->correlate('post_count', FvPost::class,
                fn ($posts, $users) => $posts
                    ->select(Aggregate::count())
                    ->whereColumn('user_id', '=', "{$users}.id"))
            ->groupBy('name')
            ->orderBy('name')
            ->get();

        self::assertSame(['alicia', 'ben', 'cara'],
            array_map(fn ($r) => $r->name, $rows->all()));
        self::assertNotNull($rows[0]);
        self::assertSame(2, $rows[0]->attribute('post_count'));
    }

    /**
     * The compiled SQL shows each correlated scalar after the model
     * columns, and recompiles are binding-idempotent.
     */
    public function testCorrelateSqlSnapshotAndBindingIdempotency(): void
    {
        $builder = FvUser::newQuery()
            ->correlate('post_count', FvPost::class,
                fn ($posts, $users) => $posts
                    ->select(Aggregate::count())
                    ->whereColumn('user_id', '=', "{$users}.id"))
            ->correlate('popular_count', FvPost::class,
                fn ($posts, $users) => $posts
                    ->select(Aggregate::count())
                    ->whereColumn('user_id', '=', "{$users}.id")
                    ->where('views', '>', 300));

        $sql = $this->connection->grammar->compileSelect($builder);
        self::assertStringContainsString('AS "post_count"', $sql);
        self::assertStringContainsString('AS "popular_count"', $sql);

        $bindings = $builder->getBindings();
        self::assertSame([300], $bindings);

        $this->connection->grammar->compileSelect($builder);
        $this->connection->grammar->compileSelect($builder);
        self::assertSame([300], $builder->getBindings());
    }

    /**
     * correlate()'s closures receive the outer's FROM identity — under
     * fromSub() that is the ALIAS, so the written whereColumn matches
     * the compiled SQL.
     */
    public function testCorrelatePassesFromIdentityToClosure(): void
    {
        $received = null;

        $builder = FvUser::newQuery()
            ->fromSub(FvUser::newQuery()->select('id', 'name'), 'recent_users')
            ->correlate('post_count', FvPost::class,
                function ($posts, $users) use (&$received) {
                    $received = $users;

                    return $posts
                        ->select(Aggregate::count())
                        ->whereColumn('fv_posts.user_id', '=', "{$users}.id");
                });

        self::assertSame('recent_users', $received);

        $sql = $this->connection->grammar->compileSelect($builder);
        self::assertStringContainsString('"recent_users"."id"', $sql);
    }
}
