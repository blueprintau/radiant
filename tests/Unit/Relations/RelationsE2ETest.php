<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Relations;

use BlueprintAU\Radiant\Collection;
use BlueprintAU\Radiant\Database\Query\Aggregate;
use BlueprintAU\Radiant\Database\Query\Expression;
use BlueprintAU\Radiant\Tests\Support\DatabaseTestCase;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\RelPost;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\RelTeam;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\RelTeamPost;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\RelUser;

/**
 * End-to-end relation tests on live SQLite: lazy + eager HasMany / HasOne /
 * BelongsTo, eager through, Collection::load()/fresh(), and the fail-fast
 * cross-checks.
 */
final class RelationsE2ETest extends DatabaseTestCase
{
    /**
     * Create the rel_users / rel_posts / rel_teams / rel_team_posts fixture
     * tables from the models' attributes.
     */
    protected function setUpDatabase(): void
    {
        $this->createTables(RelUser::class, RelPost::class, RelTeam::class, RelTeamPost::class);
    }

    /**
     * Seed one user with two posts, one orphan post, and a through chain.
     *
     * @return array{user: RelUser, team: RelTeam}
     */
    private function seed(): array
    {
        $user = new RelUser();
        $user->email = 'alicia@example.com';
        $user->save();

        foreach (['First' => 'published', 'Second' => 'draft'] as $title => $status) {
            $post = new RelPost();
            $post->authorId = $user->id;
            $post->title = $title;
            $post->status = $status;
            $post->views = $title === 'First' ? 10 : null;
            $post->save();
        }

        $orphan = new RelPost();
        $orphan->authorId = null;
        $orphan->title = 'Orphan';
        $orphan->status = 'published';
        $orphan->views = 5;
        $orphan->save();

        $team = new RelTeam();
        $team->ownerId = $user->id;
        $team->name = 'Core';
        $team->save();

        foreach (['Alpha', 'Beta'] as $title) {
            $teamPost = new RelTeamPost();
            $teamPost->teamId = $team->id;
            $teamPost->title = $title;
            $teamPost->save();
        }

        return ['user' => $user, 'team' => $team];
    }

    /**
     * Lazy HasMany: the relation composes (constraint + orderBy) and
     * executes on getResults().
     */
    public function testLazyHasMany(): void
    {
        ['user' => $user] = $this->seed();

        // The relation's own filter methods compose onto the constrained
        // builder — orderBy returns the relation, getResults() materializes.
        $posts = $user->posts()->orderBy('title')->getResults();

        self::assertCount(2, $posts);
        self::assertNotNull($posts[0]);
        self::assertNotNull($posts[1]);
        self::assertSame('First', $posts[0]->title);
        self::assertSame('Second', $posts[1]->title);
    }

    /**
     * Lazy BelongsTo round-trips: the post finds its author; a null FK
     * yields no results (not an error).
     */
    public function testLazyBelongsToAndNullFk(): void
    {
        ['user' => $user] = $this->seed();

        $post = RelPost::where('title', '=', 'First')->first();
        self::assertNotNull($post);

        $author = $post->author()->getResults();
        self::assertCount(1, $author);
        self::assertNotNull($author[0]);
        self::assertSame($user->id, $author[0]->id);

        $orphan = RelPost::where('title', '=', 'Orphan')->first();
        self::assertNotNull($orphan);
        self::assertCount(0, $orphan->author()->getResults());
    }

    /**
     * Lazy HasOne: the first row, stably ordered by the related PK.
     */
    public function testLazyHasOne(): void
    {
        ['user' => $user] = $this->seed();

        $featured = $user->featuredPost()->getResults();

        self::assertCount(1, $featured);
        self::assertNotNull($featured[0]);
        self::assertSame('First', $featured[0]->title);
    }

    /**
     * Duplicate-FK rows resolve to the LOWEST-PK related model, identically
     * on the lazy and eager paths (regression: the lazy path
     * ordered by related PK, the eager path did not, so with duplicate FK
     * rows the two paths could disagree on which row "wins").
     *
     * Rows are seeded so the LOWEST PK is NOT the first-attached row for
     * the winning parent: the second user gets 'Second' (id 2) first, then
     * 'First' (id 1) is re-pointed at them afterwards. An insertion/DB-order
     * eager scan would pick id 2; a PK-ordered scan picks id 1. Both paths
     * must pick id 1.
     */
    public function testEagerHasOnePicksLowestPkOnDuplicateFk(): void
    {
        ['user' => $user] = $this->seed();
        $second = $this->seedSecondUser();

        // Detach 'First' (id 1) from the first user and attach it to the
        // second AFTER 'Second' (id 2) already pointed there: the second
        // user's HasOne candidate set is now {First: id 1, Second: id 2},
        // with id 1 attached last. Insertion order would pick id 2.
        $first = RelPost::where('title', '=', 'First')->first();
        self::assertNotNull($first);
        $first->authorId = $second->id;
        $first->save();

        $lazy = $second->featuredPost()->getResults();
        self::assertCount(1, $lazy);
        self::assertNotNull($lazy[0]);
        self::assertSame(1, $lazy[0]->id, 'lazy HasOne picks the lowest-PK duplicate');

        $eager = RelUser::with('featuredPost')->find($second->id);
        self::assertNotNull($eager);
        $featured = $eager->featuredPost()->getResults()->first();
        self::assertInstanceOf(RelPost::class, $featured);
        self::assertSame(1, $featured->id, 'eager HasOne must agree with lazy: lowest PK wins');

        // The first user still owns exactly one post ('Late' is not seeded;
        // 'Second' id 2 remains theirs): their winner is id 2 on both paths.
        $lazyFirst = $user->featuredPost()->getResults();
        self::assertCount(1, $lazyFirst);
        self::assertNotNull($lazyFirst[0]);
        self::assertSame(2, $lazyFirst[0]->id);

        $eagerFirst = RelUser::with('featuredPost')->find($user->id);
        self::assertNotNull($eagerFirst);
        $featuredFirst = $eagerFirst->featuredPost()->getResults()->first();
        self::assertInstanceOf(RelPost::class, $featuredFirst);
        self::assertSame(2, $featuredFirst->id);
    }

    /**
     * Eager HasMany via with(): one extra query, children stitched per
     * parent by FK value.
     */
    public function testEagerWithHasMany(): void
    {
        $this->seed();
        $this->seedSecondUser();

        $users = RelUser::with('posts')->orderBy('id')->get();

        self::assertCount(2, $users);
        self::assertNotNull($users[0]);
        self::assertNotNull($users[1]);
        self::assertTrue($users[0]->relationLoaded('posts'));
        self::assertCount(2, $users[0]->posts()->getResults());
        self::assertCount(0, $users[1]->posts()->getResults(), 'the second user has no posts');
    }

    /**
     * Eager BelongsTo via with() on the child query.
     */
    public function testEagerWithBelongsTo(): void
    {
        $this->seed();

        $posts = RelPost::with('author')->orderBy('id')->get();

        self::assertCount(3, $posts);
        self::assertNotNull($posts[0]);
        self::assertNotNull($posts[2]);
        self::assertInstanceOf(RelUser::class, $posts[0]->author()->getResults()->first());
        self::assertCount(0, $posts[2]->author()->getResults(), 'the orphan has no author');
    }

    /**
     * Eager BelongsTo with NON-overlapping ids: the loader must collect the
     * parents' FK values, not their own PKs. (Regression: the loader used
     * getLocalKey() for every relation type, which for BelongsTo is the
     * RELATED table's owner key — the IN clause matched parent PKs and the
     * stitch came back null whenever ids did not coincide.)
     */
    public function testEagerBelongsToNonOverlappingIds(): void
    {
        $this->seed();

        $teamPosts = RelTeamPost::with('team')->orderBy('id')->get();

        self::assertCount(2, $teamPosts);
        self::assertNotNull($teamPosts[0]);
        self::assertNotNull($teamPosts[1]);
        $firstTeam = $teamPosts[0]->team()->getResults()->first();
        $secondTeam = $teamPosts[1]->team()->getResults()->first();
        self::assertInstanceOf(RelTeam::class, $firstTeam);
        self::assertInstanceOf(RelTeam::class, $secondTeam);
        self::assertSame('Core', $firstTeam->name);
        self::assertSame('Core', $secondTeam->name);
    }

    /**
     * Lazy BelongsTo tracks an UNSAVED FK change: the relation reads the
     * typed property via attribute(), so re-pointing a model in memory
     * (before save()) re-targets the relation.
     */
    public function testLazyBelongsToTracksUnsavedChange(): void
    {
        $this->seed();

        $post = RelPost::where('title', '=', 'First')->first();
        self::assertNotNull($post);

        $second = $this->seedSecondUser();
        $post->authorId = $second->id;

        $author = $post->author()->getResults();
        self::assertCount(1, $author);
        self::assertNotNull($author[0]);
        self::assertSame($second->id, $author[0]->id);
    }

    /**
     * Dot-notation nests THREE levels deep (regression: the recursion
     * passed the whole dotted remainder as a method name, so any path of
     * three or more segments threw "no method [b.c]").
     */
    public function testEagerNestsThreeLevels(): void
    {
        $this->seed();

        $teamPosts = RelTeamPost::with('team.owner')->get();

        self::assertCount(2, $teamPosts);
        self::assertNotNull($teamPosts[0]);
        $team = $teamPosts[0]->team()->getResults()->first();
        self::assertInstanceOf(RelTeam::class, $team);
        self::assertTrue($team->relationLoaded('owner'));
        self::assertCount(1, $team->owner()->getResults());
    }

    /**
     * with() rejects non-string and empty paths at the call site. The
     * builder method takes an array (Model::with() is string-variadic), so
     * the element-shape check lives there. The paths arrive through a
     * mixed-typed helper — simulating a caller without a static analyzer.
     *
     * @param mixed $paths The paths as an untyped caller supplied them.
     * @return void
     * @throws \InvalidArgumentException When any path is invalid.
     */
    private function withUntyped(mixed $paths): void
    {
        RelUser::where('id', '=', 1)->with($paths);
    }

    /**
     * with() rejects non-string and empty paths at the call site.
     */
    public function testWithRejectsInvalidPaths(): void
    {
        try {
            $this->withUntyped(['posts', 42]);
            self::fail('Expected an InvalidArgumentException for a non-string path.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('must be non-empty strings; got int', $e->getMessage());
        }

        try {
            $this->withUntyped(['posts', '']);
            self::fail('Expected an InvalidArgumentException for an empty path.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('got an empty path', $e->getMessage());
        }
    }

    /**
     * whereKey() rejects key shapes outside the KeyValue contract. The
     * scalar/array part of the contract is the NATIVE parameter type —
     * a bool key fails with a TypeError at the boundary before any
     * validation runs. The array-shape part (string column names, scalar
     * map values) cannot be expressed natively, so the boundary
     * validators still reject those with InvalidArgumentException. The
     * keys arrive through mixed-typed helpers, simulating a caller
     * without a static analyzer.
     */
    public function testWhereKeyRejectsInvalidShapes(): void
    {
        $this->seed();

        try {
            $this->whereKeyUntyped(true);
            self::fail('Expected a TypeError for a bool key.');
        } catch (\TypeError $e) {
            self::assertStringContainsString('whereKey(): Argument #1 ($id)', $e->getMessage());
            self::assertStringContainsString('array|string|int|null', $e->getMessage());
        }

        try {
            $this->whereKeyUntyped(['id' => new \stdClass()]);
            self::fail('Expected an InvalidArgumentException for an object key value.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('must be int, string or null; got stdClass', $e->getMessage());
        }

        try {
            // A NON-list array (int key not starting at 0 in sequence) is a
            // composite key map — an int column name is rejected there.
            $this->whereKeyUntyped([5 => 1]);
            self::fail('Expected an InvalidArgumentException for a non-string column.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('string column names; got int', $e->getMessage());
        }

        // A LIST of scalars is the whereKey batch contract — valid.
        $this->whereKeyUntyped([1, 2]);
        $this->addToAssertionCount(1);
    }

    /**
     * Call whereKey() with an untyped value — the boundary under test.
     *
     * @param mixed $id The key as an untyped caller supplied it.
     * @return void
     */
    private function whereKeyUntyped(mixed $id): void
    {
        RelUser::where('id', '=', 1)->whereKey($id);
    }

    /**
     * Unknown relation in with() throws at the with() call — fail-fast.
     */
    public function testWithUnknownRelationThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('no method [pst()]');
        RelUser::with('pst')->get();
    }

    /**
     * A method that does not return a Relation is rejected.
     */
    public function testWithNonRelationMethodThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not return a Relation');
        RelUser::with('notARelation')->get();
    }

    /**
     * Eager through: the join traces parent → intermediate → related.
     */
    public function testEagerThrough(): void
    {
        ['user' => $user] = $this->seed();

        $loaded = RelUser::with('teamPosts')->find($user->id);

        self::assertNotNull($loaded);
        $posts = $loaded->teamPosts()->getResults();
        self::assertInstanceOf(Collection::class, $posts);
        self::assertCount(2, $posts);
    }

    /**
     * LAZY through: getResults() executes the joined query for one parent.
     */
    public function testLazyThrough(): void
    {
        ['user' => $user] = $this->seed();

        $posts = $user->teamPosts()->getResults();

        self::assertCount(2, $posts);
    }

    /**
     * HasOneThrough: the first team post per parent, lazy + eager.
     */
    public function testHasOneThrough(): void
    {
        ['user' => $user] = $this->seed();

        $lazy = $user->featuredTeamPost()->getResults();
        self::assertCount(1, $lazy);

        $loaded = RelUser::with('featuredTeamPost')->find($user->id);
        self::assertNotNull($loaded);
        self::assertInstanceOf(RelTeamPost::class, $loaded->featuredTeamPost()->getResults()->first());
    }

    /**
     * Collection::find() locates a model by key; modelKeys() lists them.
     */
    public function testCollectionFindAndModelKeys(): void
    {
        ['user' => $user] = $this->seed();

        $users = RelUser::all();

        self::assertSame([$user->id], $users->modelKeys());
        self::assertNotNull($users->find($user->id));
        self::assertNull($users->find(99999));
    }

    /**
     * Collection::load() eager-loads onto an existing collection.
     */
    public function testCollectionLoad(): void
    {
        $this->seed();

        $users = RelUser::all();
        self::assertCount(1, $users);

        $users->load('posts');

        self::assertNotNull($users[0]);
        self::assertTrue($users[0]->relationLoaded('posts'));
        self::assertCount(2, $users[0]->posts()->getResults());
    }

    /**
     * Collection::fresh() re-queries each model by key (scoped), replacing
     * the items.
     */
    public function testCollectionFresh(): void
    {
        ['user' => $user] = $this->seed();

        $users = RelUser::all();
        self::assertNotNull($users[0]);
        $users[0]->email = 'changed-in-memory@example.com';

        $users->fresh();

        self::assertNotNull($users[0]);
        self::assertSame('alicia@example.com', $users[0]->email, 'fresh() re-reads from the database');
        self::assertSame($user->id, $users[0]->id);
    }

    /**
     * countBy groups THIS parent's related rows only: the orphan post's
     * identical status never leaks into the user's counts, and the FK
     * constraint rides the grouped query automatically.
     */
    public function testCountByGroupsRelatedRows(): void
    {
        ['user' => $user] = $this->seed();

        $counts = $user->posts()->countBy('status');

        self::assertSame(1, $counts['published']);
        self::assertSame(1, $counts['draft']);
        self::assertCount(2, $counts);
    }

    /**
     * The countBy seed is ADDITIVE: seeded-but-absent groups become 0,
     * database rows always win, and a group value present in the data
     * but missing from the seed still appears — the seed never hides data.
     */
    public function testCountBySeedIsAdditive(): void
    {
        ['user' => $user] = $this->seed();

        // A status the seed list does not know about.
        $post = new RelPost();
        $post->authorId = $user->id;
        $post->title = 'Review';
        $post->status = 'review';
        $post->save();

        $counts = $user->posts()->countBy('status', ['published', 'draft', 'archived']);

        self::assertSame(1, $counts['published']);
        self::assertSame(1, $counts['draft']);
        self::assertSame(0, $counts['archived']);
        self::assertSame(1, $counts['review']);
    }

    /**
     * An Expression aggregate argument passes through raw: count over a
     * CASE counts only the rows where the CASE yields a value.
     */
    public function testAggregateByExpressionPassesThroughRaw(): void
    {
        ['user' => $user] = $this->seed();

        $counts = $user->posts()->aggregateBy(
            new Aggregate('count', new Expression("case when status = 'draft' then 1 end")),
            'status',
        );

        self::assertSame(1, $counts['draft']);
        self::assertSame(0, $counts['published']);
    }

    /**
     * countBy runs its own grouped query on a scoped clone — the
     * relation's builder is untouched, so a later getResults() still
     * returns every post (the read never marks the relation composed).
     */
    public function testCountByLeavesTheRelationUntouched(): void
    {
        ['user' => $user] = $this->seed();

        $relation = $user->posts();
        $relation->countBy('status');

        self::assertCount(2, $relation->getResults());
    }

    /**
     * The fail-fast cross-check: an unknown FK column throws at relation
     * construction.
     */
    public function testUnknownForeignKeyThrows(): void
    {
        $user = new RelUser();
        $user->email = 'x@example.com';
        $user->save();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown foreign key column [bogus_id]');
        $user->brokenPosts();
    }

    /**
     * Seed a second user with no posts.
     *
     * @return RelUser The second user.
     */
    private function seedSecondUser(): RelUser
    {
        $second = new RelUser();
        $second->email = 'ben@example.com';
        $second->save();

        return $second;
    }

    /**
     * countBy groups the related rows per status — the FK constraint
     * keeps other authors' rows out of the counts.
     */
    public function testCountByGroupsPerStatus(): void
    {
        ['user' => $user] = $this->seed();

        $this->connection->table('rel_posts')->where('author_id', '=', $user->id)->update(['status' => 'going']);
        $this->connection->table('rel_posts')->where('title', '=', 'Orphan')->update(['status' => 'going']);

        $counts = $user->posts()->countBy('status');

        // The orphan shares the status but not the author — excluded.
        self::assertSame(['going' => 2], $counts->all());
    }

    /**
     * The seed is ADDITIVE: seeded keys absent from the data become 0,
     * database rows always win, and unlisted group values still appear.
     */
    public function testCountBySeedFillsAbsentGroupsAndKeepsUnknown(): void
    {
        ['user' => $user] = $this->seed();

        $this->connection->table('rel_posts')->where('title', '=', 'First')->update(['status' => 'going']);
        $this->connection->table('rel_posts')->where('title', '=', 'Second')->update(['status' => 'cancelled']);

        $counts = $user->posts()->countBy('status', ['going', 'declined', 'maybe']);

        self::assertSame(1, $counts['going']);
        self::assertSame(0, $counts['declined']);
        self::assertSame(0, $counts['maybe']);
        self::assertSame(1, $counts['cancelled'], 'an unlisted database value still appears');
        self::assertCount(4, $counts);
    }

    /**
     * countBy rides on top of composed filters — the where constrains
     * the grouped query too.
     */
    public function testCountByRespectsComposedFilters(): void
    {
        ['user' => $user] = $this->seed();

        $this->connection->table('rel_posts')->where('title', '=', 'First')->update(['status' => 'going']);
        $this->connection->table('rel_posts')->where('title', '=', 'Second')->update(['status' => 'declined']);

        $counts = $user->posts()->where('title', '=', 'First')->countBy('status');

        self::assertSame(['going' => 1], $counts->all());
    }

    /**
     * aggregateBy sums a declared column per group, decoded through the
     * column's cast — and the FK constraint excludes other authors' rows.
     */
    public function testAggregateBySumsPerGroup(): void
    {
        ['user' => $user] = $this->seed();

        $this->connection->table('rel_posts')->where('title', '=', 'First')->update(['status' => 'going', 'views' => 10]);
        $this->connection->table('rel_posts')->where('title', '=', 'Second')->update(['status' => 'going', 'views' => 5]);
        $this->connection->table('rel_posts')->where('title', '=', 'Orphan')->update(['status' => 'going', 'views' => 100]);

        $sums = $user->posts()->aggregateBy(Aggregate::sum('views'), 'status');

        // The orphan's 100 views share the status but not the author.
        self::assertSame(15, $sums['going']);
    }

    /**
     * A group whose aggregated values are ALL null sums to null — the
     * SQL-honest result, not a coerced zero.
     */
    public function testAggregateByAllNullGroupIsNull(): void
    {
        ['user' => $user] = $this->seed();

        $this->connection->table('rel_posts')->where('author_id', '=', $user->id)->update(['status' => 'going', 'views' => null]);

        $sums = $user->posts()->aggregateBy(Aggregate::sum('views'), 'status');

        self::assertSame(['going' => null], $sums->all());
    }

    /**
     * An Expression aggregate argument passes through raw — the
     * conditional-count pattern works on SQL connections.
     */
    public function testAggregateByAcceptsExpressionArgument(): void
    {
        ['user' => $user] = $this->seed();

        $this->connection->table('rel_posts')->where('title', '=', 'First')->update(['status' => 'going']);
        $this->connection->table('rel_posts')->where('title', '=', 'Second')->update(['status' => 'declined']);

        $going = $user->posts()->aggregateBy(
            new Aggregate('count', new Expression("case when status = 'going' then 1 end")),
            'status',
        );

        self::assertSame(1, $going['going']);
        self::assertSame(0, $going['declined']);
    }

    /**
     * countBy is a READ, not a composition: it does not mark the
     * relation composed, so a later getResults() is unaffected.
     */
    public function testCountByLeavesRelationUncomposed(): void
    {
        ['user' => $user] = $this->seed();

        $this->connection->table('rel_posts')->where('author_id', '=', $user->id)->update(['status' => 'going']);

        $relation = $user->posts();
        $relation->countBy('status');

        self::assertCount(2, $relation->getResults());
    }
}
