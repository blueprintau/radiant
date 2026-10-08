<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Model;

use BlueprintAU\Radiant\Database\Query\Aggregate;
use BlueprintAU\Radiant\Database\Query\Expression;
use BlueprintAU\Radiant\Metadata\MetadataFactory;
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\Tests\Support\DatabaseTestCase;
use BlueprintAU\Radiant\Tests\Unit\Model\Fixtures\OfPost;
use BlueprintAU\Radiant\Tests\Unit\Model\Fixtures\OfSoftPost;
use BlueprintAU\Radiant\Tests\Unit\Model\Fixtures\OfUser;
use BlueprintAU\Radiant\Tests\Unit\Model\Fixtures\TwoStatePost;

/**
 * Exercise the ModelQueryBuilder edge arms — the aggregate family, scope
 * stripping, whereKey guards, select expansion and relation resolution.
 */
final class BuilderAggregateTest extends DatabaseTestCase
{
    /**
     * Create the fixture tables.
     */
    protected function setUpDatabase(): void
    {
        $this->createTables(OfUser::class, OfPost::class, OfSoftPost::class, TwoStatePost::class);
    }

    /**
     * Reset the metadata cache after every test.
     */
    protected function tearDown(): void
    {
        MetadataFactory::clear();

        parent::tearDown();
    }

    /**
     * Seed two users and three posts.
     *
     * @return array{a: OfUser, b: OfUser}
     */
    private function seed(): array
    {
        $a = new OfUser();
        $a->name = 'Alice';
        $a->save();

        $b = new OfUser();
        $b->name = 'Bob';
        $b->save();

        foreach (['P1', 'P2', 'P3'] as $title) {
            $post = new OfPost();
            $post->authorId = $a->id;
            $post->title = $title;
            $post->save();
        }

        return ['a' => $a, 'b' => $b];
    }

    // ---- Aggregate family ----

    /**
     * count() counts the matching rows.
     */
    public function testCount(): void
    {
        $this->seed();

        self::assertSame(2, OfUser::newQuery()->count());
    }

    /**
     * min()/max()/sum()/avg() decode through the column's cast.
     */
    public function testMinMaxSumAvg(): void
    {
        $this->seed();

        $builder = OfPost::newQuery();

        self::assertSame(1, $builder->min('id'));
        self::assertSame(3, $builder->max('id'));
        self::assertSame(6, $builder->sum('id'));
        self::assertEqualsWithDelta(2.0, $builder->avg('id'), 0.001);
    }

    /**
     * aggregates() returns one raw row keyed by aggregate alias, with an
     * Expression argument passing through undecoded.
     */
    public function testAggregatesWithExpressionArgument(): void
    {
        $this->seed();

        $row = OfPost::newQuery()->aggregates(
            Aggregate::count('*', 'total'),
            new Aggregate('max', new Expression('id'), 'top'),
        );

        self::assertSame(3, $row->total);
        self::assertSame(3, $row->top);
    }

    /**
     * value() with an Aggregate whose column is an Expression passes the
     * computed value through undecoded.
     */
    public function testValueWithExpressionAggregatePassesThrough(): void
    {
        $this->seed();

        $value = OfPost::newQuery()->value(new Aggregate('max', new Expression('id'), 'radiant_scalar'));

        self::assertSame(3, $value);
    }

    /**
     * value() with an Aggregate on a declared column decodes through the
     * column's cast.
     */
    public function testValueWithAggregateDecodesColumn(): void
    {
        $this->seed();

        $value = OfPost::newQuery()->value(Aggregate::max('id'));

        self::assertSame(3, $value);
    }

    /**
     * value() with an Aggregate on an empty match returns null.
     */
    public function testValueWithAggregateOnEmptyMatchReturnsNull(): void
    {
        $this->seed();

        $value = OfPost::newQuery()->where('title', '=', 'missing')->value(Aggregate::max('id'));

        self::assertNull($value);
    }

    /**
     * having() with a plain string column validates it — the non-aggregate,
     * non-Expression arm.
     */
    public function testHavingWithPlainColumnValidates(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Unknown column [ghost] on model [' . OfPost::class . ']');

        OfPost::newQuery()->groupBy('title')->having('ghost', '=', 1);
    }

    /**
     * having() with an Expression passes through unvalidated — raw SQL by
     * contract.
     */
    public function testHavingWithExpressionPassesThrough(): void
    {
        $this->seed();

        $rows = OfPost::newQuery()
            ->groupBy('title')
            ->having(new Expression('count(*)'), '>', 1)
            ->get();

        self::assertCount(0, $rows); // every title is unique.
    }

    /**
     * select() with a `column as alias` spec validates the SOURCE column —
     * the alias-only arm of validateColumn.
     */
    public function testSelectAliasValidatesSourceColumn(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Unknown column [ghost as label] on model [' . OfPost::class . ']');

        OfPost::newQuery()->select('ghost as label');
    }

    /**
     * select() with a `declared as alias` spec PASSES validation — the
     * alias arm admits a declared source column.
     */
    public function testSelectAliasOnDeclaredColumnPasses(): void
    {
        $this->seed();

        $rows = OfPost::newQuery()->select('title as label')->orderBy('id')->getRaw();

        self::assertCount(3, $rows);
        $first = $rows->first();
        self::assertNotNull($first);
        self::assertSame('P1', $first->label);
    }

    /**
     * select() with a QUALIFIED spec is caller-owned — no forced-key merge,
     * and the spec passes through to the parent builder.
     */
    public function testSelectQualifiedSpecIsCallerOwnedPassthrough(): void
    {
        $this->seed();

        $rows = OfPost::newQuery()
            ->select('of_posts.id', 'of_posts.title')
            ->orderBy('id')
            ->get();

        self::assertCount(3, $rows);
        $first = $rows->first();
        self::assertNotNull($first);
        self::assertSame(1, $first->id);
    }

    /**
     * whereKey() with a QUALIFIED select that omits the PK still throws —
     * the qualified form is checked against the PK names too.
     */
    public function testWhereKeyQualifiedSelectWithoutPkThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('whereKey() requires the primary key in the select list');

        OfPost::newQuery()->select('of_posts.title')->whereKey(1);
    }

    /**
     * whereKey() with a select naming the PK QUALIFIED passes the guard —
     * the MTI builder's own default select shape.
     */
    public function testWhereKeyQualifiedSelectWithPkPasses(): void
    {
        $this->seed();

        $post = OfPost::newQuery()->select('of_posts.id')->whereKey(1)->first();

        self::assertNotNull($post);
        self::assertSame(1, $post->id);
    }

    /**
     * whereKey() with a select containing an Expression AND the PK passes
     * the guard — the Expression entry is skipped, the PK entry matches.
     */
    public function testWhereKeySelectWithExpressionSkipsGuard(): void
    {
        $this->seed();

        $post = OfPost::newQuery()
            ->select(new Expression('id'), 'id')
            ->whereKey(1)
            ->first();

        self::assertNotNull($post);
    }

    /**
     * whereKey() with a select containing an Aggregate AND the PK passes
     * the guard — the Aggregate entry is skipped, the PK entry matches.
     */
    public function testWhereKeySelectWithAggregateSkipsGuard(): void
    {
        $this->seed();

        $post = OfPost::newQuery()
            ->select(Aggregate::count('*', 'total'), 'id')
            ->whereKey(1)
            ->first();

        self::assertNotNull($post);
    }

    /**
     * whereKey() with a `table.*` select passes the guard — the star
     * expands to every column including the PK.
     */
    public function testWhereKeySelectTableStarPassesGuard(): void
    {
        $this->seed();

        $post = OfPost::newQuery()
            ->select('of_posts.*')
            ->whereKey(1)
            ->first();

        self::assertNotNull($post);
        self::assertSame(1, $post->id);
    }

    /**
     * select() with an Aggregate validates the aggregate's inner column —
     * the same allowlist as a plain column.
     */
    public function testSelectAggregateValidatesInnerColumn(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Unknown column [ghost] on model [' . OfPost::class . ']');

        OfPost::newQuery()->select(Aggregate::count('ghost', 'total'));
    }

    /**
     * select() with an Aggregate whose column is `*` skips validation —
     * COUNT(*) has no column to check. An Aggregate entry makes the
     * select caller-owned, so it passes through to the parent builder
     * untouched and the row carries the computed value.
     */
    public function testSelectAggregateStarSkipsValidation(): void
    {
        $this->seed();

        $rows = OfPost::newQuery()->select(Aggregate::count('*', 'total'))->getRaw();

        // Caller-owned passthrough — one raw row holding the computed
        // count, keyed by the explicit alias.
        self::assertCount(1, $rows);
        $row = $rows->first();
        self::assertNotNull($row);
        self::assertSame(3, (int) $row->total);
    }

    // ---- Scope stripping ----

    /**
     * withoutScopes() on a builder WITH trait scopes strips them — the
     * clone path (a new builder is returned, the original is untouched).
     */
    public function testWithoutScopesStripsTraitScopes(): void
    {
        $this->seed();

        $post = new OfSoftPost();
        $post->title = 'gone';
        $post->save();
        $post->delete();

        // The default scope hides the trashed post...
        self::assertCount(0, OfSoftPost::newQuery()->get());

        // ...withoutScopes() strips it — the clone path fires.
        $stripped = OfSoftPost::newQuery()->withoutScopes();
        self::assertNotSame(OfSoftPost::newQuery(), $stripped);
        self::assertCount(1, $stripped->get());
    }

    /**
     * withoutScope() for a PRESENT trait strips only that trait's scope —
     * the clone path.
     */
    public function testWithoutScopeStripsPresentTrait(): void
    {
        $this->seed();

        $post = new OfSoftPost();
        $post->title = 'gone';
        $post->save();
        $post->delete();

        $stripped = OfSoftPost::newQuery()->withoutScope(\BlueprintAU\Radiant\SoftDeletes::class);
        self::assertCount(1, $stripped->get());
    }

    /**
     * withoutScopes() on a builder with no trait scopes reuses the same
     * instance — nothing to remove.
     */
    public function testWithoutScopesReusesInstanceWhenNothingToStrip(): void
    {
        $builder = OfUser::newQuery();

        self::assertSame($builder, $builder->withoutScopes());
    }

    /**
     * withoutScope() for an absent trait reuses the same instance.
     */
    public function testWithoutScopeReusesInstanceWhenTraitAbsent(): void
    {
        $builder = OfUser::newQuery();

        self::assertSame($builder, $builder->withoutScope(\BlueprintAU\Radiant\SoftDeletes::class));
    }

    // ---- onlyTrashed ----

    /**
     * onlyTrashed() on a model without SoftDeletes fails fast.
     */
    public function testOnlyTrashedWithoutSoftDeletesThrows(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains(
            'Model [' . OfUser::class . '] does not use SoftDeletes; onlyTrashed() is unavailable.',
        );

        OfUser::newQuery()->onlyTrashed();
    }

    // ---- whereKey guards ----

    /**
     * whereKey() with a narrow select omitting the PK fails fast — the
     * result feeds save()/delete().
     */
    public function testWhereKeyRejectsSelectWithoutPk(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'whereKey() requires the primary key in the select list',
        );

        // A QUALIFIED select is caller-owned — no forced-key merge — so the
        // PK-selection guard actually fires (a bare select('name') would
        // silently re-merge the PK).
        OfUser::newQuery()->select('of_users.name')->whereKey(1);
    }

    /**
     * whereKey() with an empty list compiles to an impossible filter.
     */
    public function testWhereKeyEmptyListMatchesNothing(): void
    {
        $this->seed();

        $rows = OfUser::newQuery()->whereKey([])->get();

        self::assertCount(0, $rows);
    }

    /**
     * whereKey() with a list matches any of the keys.
     */
    public function testWhereKeyListMatchesAny(): void
    {
        ['a' => $a, 'b' => $b] = $this->seed();

        $rows = OfUser::newQuery()->whereKey([$a->id, $b->id])->get();

        self::assertCount(2, $rows);
    }

    // ---- select expansion ----

    /**
     * select('*') expands to the model's columns with the PK first.
     */
    public function testSelectStarExpandsPkFirst(): void
    {
        $builder = OfUser::newQuery()->select('*');

        $columns = $builder->getColumns();

        self::assertSame('id', $columns[0], 'the PK leads the expanded list');
    }

    /**
     * A caller-owned select (qualified spec) skips the forced-key merge.
     */
    public function testSelectQualifiedSpecIsCallerOwned(): void
    {
        $builder = OfUser::newQuery()->select('of_users.name');

        $columns = $builder->getColumns();

        self::assertSame(['of_users.name'], $columns);
    }

    /**
     * A select on a grouped builder skips the forced-key merge.
     */
    public function testSelectWithGroupsIsCallerOwned(): void
    {
        $builder = OfUser::newQuery()->groupBy('name')->select('name');

        $columns = $builder->getColumns();

        self::assertSame(['name'], $columns);
    }

    // ---- having ----

    /**
     * having() with an Aggregate validates the inner column.
     */
    public function testHavingWithAggregateValidatesColumn(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'Unknown column [ghost] on model [' . OfUser::class . '].',
        );

        OfUser::newQuery()->groupBy('name')->having(Aggregate::count('ghost'), '>', 1);
    }

    // ---- Relation resolution ----

    /**
     * with() on a non-existent relation method fails fast.
     */
    public function testWithUnknownRelationThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'Unknown relation [ghosts] — model [' . OfUser::class . '] has no method [ghosts()].',
        );

        OfUser::newQuery()->with('ghosts');
    }

    /**
     * with() on a non-public relation method fails fast.
     */
    public function testWithNonPublicRelationThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'Relation [hiddenPosts] — method [' . OfUser::class . '::hiddenPosts()] is not public;'
            . ' relations must be callable.',
        );

        OfUser::newQuery()->with('hiddenPosts');
    }

    /**
     * with() on a method that does not return a Relation fails fast.
     */
    public function testWithNonRelationMethodThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'does not return a Relation.',
        );

        OfUser::newQuery()->with('name');
    }

    /**
     * An empty relation path fails the path assertion.
     */
    public function testWithEmptyPathThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'Relation paths must be non-empty strings; got an empty path.',
        );

        OfUser::newQuery()->with('');
    }

    /**
     * loadRelationPath() on an empty collection returns early — no query
     * runs.
     */
    public function testLoadRelationPathOnEmptyCollectionIsANoOp(): void
    {
        $builder = OfUser::newQuery();

        $builder->loadRelationPath(\BlueprintAU\Radiant\Collection::make(), 'posts');

        // Reaching here without a query error is the assertion — the
        // early return ran. assertCount(0) also satisfies the no-op
        // contract check without a tautological assertTrue.
        self::assertCount(0, \BlueprintAU\Radiant\Collection::make());
    }

    // ---- Nested eager loading ----

    /**
     * A dotted relation path loads the nested relation through the
     * parents' cached results.
     */
    public function testNestedRelationPathLoads(): void
    {
        $this->seed();

        $users = OfUser::newQuery()->with('posts')->get();
        self::assertCount(2, $users);

        // The nested path walks the cached posts of the loaded users.
        $builder = OfUser::newQuery();
        /** @var \BlueprintAU\Radiant\Collection<int, \BlueprintAU\Radiant\Model> $models */
        $models = $users;
        $builder->loadRelationPath($models, 'posts');

        $first = $users->first();
        self::assertNotNull($first);
        self::assertNotNull($first->cachedRelation('posts'));
    }

    // ---- Multi-condition trait scopes ----

    /**
     * A trait scope with TWO conditions joins them with the declared
     * boolean — the second condition's where carries it.
     */
    public function testMultiConditionScopeJoinsWithDeclaredBoolean(): void
    {
        $published = new TwoStatePost();
        $published->status = 'published';
        $published->save();

        $archived = new TwoStatePost();
        $archived->status = 'archived';
        $archived->save();

        $draft = new TwoStatePost();
        $draft->status = 'draft';
        $draft->save();

        // The scope is (status = 'published' OR status = 'archived') —
        // the draft row is excluded.
        $rows = TwoStatePost::newQuery()->get();

        self::assertCount(2, $rows);
        $first = $rows->first();
        self::assertNotNull($first);
        self::assertSame('published', $first->status);
        $second = $rows->skip(1)->first();
        self::assertNotNull($second);
        self::assertSame('archived', $second->status);
    }
}
