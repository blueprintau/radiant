<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Relations;

use BlueprintAU\Radiant\Collection;
use BlueprintAU\Radiant\Database\Concerns\QuotesLiterals;
use BlueprintAU\Radiant\Database\Schema\Blueprint;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Relations\BelongsToMany;
use BlueprintAU\Radiant\Relations\EagerResult;
use BlueprintAU\Radiant\Relations\HasMany;
use BlueprintAU\Radiant\Relations\HasManyThrough;
use BlueprintAU\Radiant\Relations\HasOneThrough;
use BlueprintAU\Radiant\Relations\Relation;
use BlueprintAU\Radiant\Tests\Support\DatabaseTestCase;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\B2mPost;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\B2mTag;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\CmpRegion;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\CmpShipment;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\PolyImage;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\PolyPost;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\RelPost;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\RelPostNoFk;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\RelTeam;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\RelTeamPost;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\RelUser;

/**
 * The relation residuals the E2E suites never reach: the constructor
 * key-shape guards, the scalar/composite accessor guards, the base
 * Relation::executeResults(), the match() eager-key contracts, the
 * BelongsToMany write-API edges, and the shared concern traits.
 */
final class RelationResidualsTest extends DatabaseTestCase
{
    /**
     * Create the fixture tables: the rel_* chain, the b2m pair + pivot,
     * and the poly pair for the MorphOne paths.
     */
    protected function setUpDatabase(): void
    {
        $this->createTables(
            RelUser::class,
            RelPost::class,
            RelTeam::class,
            RelTeamPost::class,
            Blueprint::fromMetadata(B2mPost::class),
            Blueprint::fromMetadata(B2mTag::class),
            Blueprint::fromMetadata(PolyPost::class),
            Blueprint::fromMetadata(PolyImage::class),
        );

        $pivot = (new Blueprint('b2m_posts_b2m_tags'))
            ->foreignId('b2m_posts_id', 'b2m_posts.id')
            ->foreignId('b2m_tags_id', 'b2m_tags.id')
            ->column(ColumnType::String, 'position', nullable: true, length: 16);
        $this->connection->create($pivot);
    }

    /**
     * Seed one user with two posts, and a team with two team posts.
     *
     * @return array{user: RelUser, team: RelTeam}
     */
    private function seedRel(): array
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
     * Seed the b2m pair: post 1 carries tags 1+2, post 2 carries tag 2.
     *
     * @return array{post1: B2mPost, post2: B2mPost}
     */
    private function seedB2m(): array
    {
        $this->connection->table('b2m_posts')->insert([
            ['id' => 1, 'title' => 'Post One'],
            ['id' => 2, 'title' => 'Post Two'],
        ]);
        $this->connection->table('b2m_tags')->insert([
            ['id' => 1, 'label' => 'php'],
            ['id' => 2, 'label' => 'db'],
            ['id' => 3, 'label' => 'orm'],
        ]);
        $this->connection->table('b2m_posts_b2m_tags')->insert([
            ['b2m_posts_id' => 1, 'b2m_tags_id' => 1],
            ['b2m_posts_id' => 1, 'b2m_tags_id' => 2],
            ['b2m_posts_id' => 2, 'b2m_tags_id' => 2],
        ]);

        $post1 = B2mPost::newQuery()->find(1);
        $post2 = B2mPost::newQuery()->find(2);
        self::assertNotNull($post1);
        self::assertNotNull($post2);

        return ['post1' => $post1, 'post2' => $post2];
    }

    /**
     * Seed one poly post with TWO images — the first-wins data.
     *
     * @return PolyPost
     */
    private function seedPoly(): PolyPost
    {
        $this->connection->table('poly_posts')->insert(['id' => 1, 'title' => 'Post One']);
        $this->connection->table('poly_images')->insert([
            ['id' => 1, 'path' => 'first.png', 'imageable_type' => PolyPost::class, 'imageable_id' => 1],
            ['id' => 2, 'path' => 'second.png', 'imageable_type' => PolyPost::class, 'imageable_id' => 1],
        ]);

        $post = PolyPost::newQuery()->find(1);
        self::assertNotNull($post);

        return $post;
    }

    // ---- Constructor key-shape guards ----

    /**
     * A relation mixing a scalar FK with a composite local key fails fast
     * — the join would be unbuildable.
     */
    public function testRelationCtorRejectsMixedKeyShapes(): void
    {
        $user = new RelUser();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('BOTH single columns or BOTH composite column lists');

        new HasMany($user, RelPost::class, ['author_id'], 'id');
    }

    /**
     * An empty composite key list fails fast — a zero-column join matches
     * everything.
     */
    public function testRelationCtorRejectsEmptyCompositeKeys(): void
    {
        $user = new RelUser();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('requires at least one column');

        new HasMany($user, RelPost::class, [], []);
    }

    /**
     * Composite keys of mismatched arity fail fast — the columns cannot
     * pair up.
     */
    public function testRelationCtorRejectsArityMismatch(): void
    {
        $user = new RelUser();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('matching arity; got 2 and 1');

        new HasMany($user, RelPost::class, ['author_id', 'extra'], ['id']);
    }

    /**
     * A through relation mixing a scalar first key with a composite
     * second key fails fast.
     */
    public function testThroughCtorRejectsMixedHopKeys(): void
    {
        $user = new RelUser();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('BOTH single columns or BOTH composite column lists');

        new HasManyThrough($user, RelTeamPost::class, RelTeam::class, ['owner_id'], 'team_id', 'id');
    }

    /**
     * A through relation with an empty hop key list fails fast.
     */
    public function testThroughCtorRejectsEmptyHopKeys(): void
    {
        $user = new RelUser();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('require at least one column');

        new HasManyThrough($user, RelTeamPost::class, RelTeam::class, [], [], 'id');
    }

    /**
     * A through relation with mismatched hop-key arity fails fast.
     */
    public function testThroughCtorRejectsArityMismatchHopKeys(): void
    {
        $user = new RelUser();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('matching arity; got 2 and 1');

        new HasManyThrough($user, RelTeamPost::class, RelTeam::class, ['owner_id', 'x'], ['team_id'], 'id');
    }

    // ---- Scalar/composite accessor guards ----

    /**
     * The scalar FK accessor rejects a composite relation — the caller
     * meant getForeignKeys().
     */
    public function testScalarForeignKeyAccessorRejectsCompositeRelation(): void
    {
        $shipment = new CmpShipment();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('composite foreign key; call getForeignKeys()');

        $shipment->region()->getForeignKey();
    }

    /**
     * The composite FK accessor rejects a scalar relation — the caller
     * meant getForeignKey().
     */
    public function testCompositeForeignKeyAccessorRejectsScalarRelation(): void
    {
        ['user' => $user] = $this->seedRel();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('single foreign key; call getForeignKey()');

        $user->posts()->getForeignKeys();
    }

    /**
     * The composite local-key accessor rejects a scalar relation.
     */
    public function testCompositeLocalKeyAccessorRejectsScalarRelation(): void
    {
        ['user' => $user] = $this->seedRel();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('single local key; call getLocalKey()');

        $user->posts()->getLocalKeys();
    }

    /**
     * A belongsTo with NO explicit keys walks the convention path: the
     * owner key derives from the related model's primary key, the FK from
     * the related class's short name — and the derived FK fails the
     * column-exists guard when the model never declares it.
     */
    public function testConventionBelongsToDerivesKeysFromRelatedClass(): void
    {
        $post = new RelPostNoFk();
        $post->id = 1;
        $post->title = 'no fk';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('foreign key');

        $post->authorByConvention();
    }

    // ---- Base Relation paths ----

    /**
     * The BASE executeResults() runs the constrained query unmodified —
     * an anonymous subclass with a no-op constraint returns every post.
     */
    public function testBaseRelationExecuteResultsViaAnonymousSubclass(): void
    {
        ['user' => $user] = $this->seedRel();

        $relation = new /** The no-constraint probe — the base executeResults() shape under test. */ class($user, RelPost::class, 'author_id', 'id') extends Relation {
            /**
             * No constraint — the base executeResults() shape under test.
             *
             * @return void
             */
            #[\Override]
            protected function addConstraints(): void
            {
            }

            /**
             * Unused — executeResults() is the path under test.
             *
             * @param  list<\BlueprintAU\Radiant\Model>  $parents
             * @param  Collection<\BlueprintAU\Radiant\Model>  $results
             * @param  string  $name
             * @param  list<int|string|null|list<int|string|null>>|null  $eagerParentKeys
             * @return void
             */
            #[\Override]
            public function match(array $parents, Collection $results, string $name, ?array $eagerParentKeys = null): void
            {
                unset($parents, $results, $name, $eagerParentKeys);
            }
        };

        self::assertCount(2, $relation->getResults());
    }

    /**
     * whereNested() composes a parenthesized group onto the relation —
     * the group ORs internally while ANDing against the constraint, and
     * the original relation stays untouched.
     */
    public function testWhereNestedComposesGroupedFilters(): void
    {
        ['user' => $user] = $this->seedRel();

        $composed = $user->posts()->whereNested(
            fn ($query) => $query->where('status', '=', 'published')->orWhere('views', '>', 5),
        );

        $results = $composed->getResults();
        self::assertCount(1, $results);
        self::assertNotNull($results->first());
        self::assertSame('First', $results->first()->title);

        // The original keeps only the constraint — immutability.
        self::assertCount(2, $user->posts()->getResults());
    }

    /**
     * withName(null) is the documented no-op — the SAME instance comes
     * back on the base relation too.
     */
    public function testWithNameNullReturnsSameInstance(): void
    {
        ['user' => $user] = $this->seedRel();

        $relation = $user->posts();

        self::assertSame($relation, $relation->withName(null));
    }

    /**
     * eagerLoad([]) short-circuits to an empty models-only result — no
     * query runs, no parent keys ride along.
     */
    public function testEagerLoadEmptyKeysReturnsEmptyResult(): void
    {
        ['user' => $user] = $this->seedRel();
        ['post1' => $post1] = $this->seedB2m();

        $hasManyResult = $user->posts()->eagerLoad([]);
        self::assertCount(0, $hasManyResult->models);
        self::assertNull($hasManyResult->parentKeys);

        $b2mResult = $post1->tags()->eagerLoad([]);
        self::assertCount(0, $b2mResult->models);
        self::assertNull($b2mResult->parentKeys);
    }

    // ---- EagerResult factories ----

    /**
     * The EagerResult factories wrap model lists and collections —
     * models-only (null parent keys), and listToCollection reindexes.
     */
    public function testEagerResultFactories(): void
    {
        ['user' => $user] = $this->seedRel();
        $posts = RelPost::newQuery()->orderBy('id')->get();

        $firstPost = $posts->first();
        $lastPost = $posts->last();
        self::assertNotNull($firstPost);
        self::assertNotNull($lastPost);

        $fromModels = EagerResult::fromModels([$firstPost, $lastPost]);
        self::assertCount(2, $fromModels->models);
        self::assertNull($fromModels->parentKeys);

        $fromCollection = EagerResult::fromCollection($posts);
        self::assertCount(2, $fromCollection->models);
        self::assertNull($fromCollection->parentKeys);

        /**
         * A sparse list reindexes to 0-based — the array_values contract.
         * 
         * @var Collection<\BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\RelPost> $reindexed
         */
        $reindexed = EagerResult::listToCollection([5 => $firstPost]);
        self::assertCount(1, $reindexed);
        self::assertNotNull($reindexed->first());
        self::assertSame($firstPost->id, $reindexed->first()->id);

        self::assertInstanceOf(RelUser::class, $user);
    }

    // ---- match() eager-key contracts ----

    /**
     * HasOneThrough::match() without the eager parent keys fails fast —
     * the per-row keys are unavailable without the EagerResult context.
     */
    public function testHasOneThroughMatchRequiresEagerKeys(): void
    {
        ['user' => $user] = $this->seedRel();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('requires the EagerResult parent keys');

        $user->featuredTeamPost()->match([$user], Collection::make([]), 'featuredTeamPost', null);
    }

    /**
     * HasManyThrough::match() without the eager parent keys fails fast —
     * the same contract as the one-to-one through variant.
     */
    public function testHasManyThroughMatchRequiresEagerKeys(): void
    {
        ['user' => $user] = $this->seedRel();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('requires the EagerResult parent keys');

        $user->teamPosts()->match([$user], Collection::make([]), 'teamPosts', null);
    }

    /**
     * BelongsToMany::match() without the eager parent keys fails fast.
     */
    public function testBelongsToManyMatchRequiresEagerKeys(): void
    {
        ['post1' => $post1] = $this->seedB2m();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('requires the EagerResult parent keys');

        $post1->tags()->match([$post1], Collection::make([]), 'tags', null);
    }

    /**
     * HasOneThrough::match() keeps the FIRST result per parent key — and
     * a null-keyed result is skipped, letting the next one win.
     */
    public function testHasOneThroughMatchFirstWinsAndSkipsNullKeys(): void
    {
        ['user' => $user, 'team' => $team] = $this->seedRel();

        $results = RelTeamPost::newQuery()->orderBy('id')->get();
        self::assertCount(2, $results);

        $firstResult = $results->first();
        $lastResult = $results->last();
        self::assertNotNull($firstResult);
        self::assertNotNull($lastResult);

        // Both rows carry the same parent key — the first (Alpha) wins.
        $user->featuredTeamPost()->match([$user], $results, 'featuredTeamPost', [$team->id, $team->id]);
        $cached = $user->featuredTeamPost()->getResults();
        self::assertCount(1, $cached);
        self::assertNotNull($cached->first());
        self::assertSame($firstResult->id, $cached->first()->id);

        // A null key skips its row — the second (Beta) becomes the match.
        $user->featuredTeamPost()->match([$user], $results, 'featuredTeamPost', [null, $team->id]);
        $cached2 = $user->featuredTeamPost()->getResults();
        self::assertNotNull($cached2->first());
        self::assertSame($lastResult->id, $cached2->first()->id);
    }

    /**
     * BelongsToMany::match() groups results per parent key — and a
     * null-keyed result lands on NO parent.
     */
    public function testBelongsToManyMatchGroupsAndSkipsNullKeys(): void
    {
        ['post1' => $post1, 'post2' => $post2] = $this->seedB2m();

        $results = B2mTag::newQuery()->whereIn('id', [1, 2])->orderBy('id')->get();
        self::assertCount(2, $results);

        // tag 1 → post 1, tag 2 → post 2.
        $post1->tags()->match([$post1, $post2], $results, 'tags', [1, 2]);
        self::assertCount(1, $post1->tags()->getResults());
        self::assertSame(1, $post1->tags()->getResults()->first()?->id);
        self::assertCount(1, $post2->tags()->getResults());
        self::assertSame(2, $post2->tags()->getResults()->first()?->id);

        // A null key drops its row — post 2 loads empty.
        $post1->tags()->match([$post1, $post2], $results, 'tags', [1, null]);
        self::assertCount(1, $post1->tags()->getResults());
        self::assertCount(0, $post2->tags()->getResults());
    }

    /**
     * MorphOne::match() keeps the first result per FK value — the
     * one-to-one cardinality on the eager path.
     */
    public function testMorphOneMatchFirstWinsPerForeignKey(): void
    {
        $post = $this->seedPoly();

        $results = PolyImage::newQuery()->orderBy('id')->get();
        self::assertCount(2, $results);

        $post->image()->match([$post], $results, 'image', null);

        $cached = $post->image()->getResults();
        self::assertCount(1, $cached);
        self::assertNotNull($cached->first());
        self::assertSame(1, $cached->first()->id);
    }

    // ---- BelongsToMany write-API edges ----

    /**
     * attach([]) inserts NOTHING — the empty-rows early return fires
     * before the pivot INSERT.
     */
    public function testAttachEmptyIdsInsertsNothing(): void
    {
        ['post2' => $post2] = $this->seedB2m();

        $before = $this->connection->table('b2m_posts_b2m_tags')
            ->where('b2m_posts_id', '=', 2)->count();

        $post2->tags()->attach([]);

        $after = $this->connection->table('b2m_posts_b2m_tags')
            ->where('b2m_posts_id', '=', 2)->count();

        self::assertSame(1, $before);
        self::assertSame($before, $after);
    }

    /**
     * attach() with a MIXED map (attributed ids beside bare ids) throws —
     * the rows would be ragged, and a bulk INSERT cannot carry differing
     * column sets (padding the absent column would silently write NULL).
     * Give every id the same attribute shape instead.
     */
    public function testAttachMixedMapThrows(): void
    {
        ['post2' => $post2] = $this->seedB2m();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('same columns; row 1 differs from row 0');

        $post2->tags()->attach([3 => ['position' => 'mixed'], 1]);
    }

    /**
     * attach() with a UNIFORM attributed map inserts every row — the
     * happy path of the per-id attribute form.
     */
    public function testAttachUniformAttributedMapInsertsEveryRow(): void
    {
        ['post2' => $post2] = $this->seedB2m();

        $post2->tags()->attach([3 => ['position' => 'mixed'], 1 => ['position' => null]]);

        $pinned = $this->connection->table('b2m_posts_b2m_tags')
            ->where('b2m_posts_id', '=', 2)
            ->where('b2m_tags_id', '=', 3)
            ->first();
        self::assertNotNull($pinned);
        self::assertSame('mixed', $pinned->position);

        $bare = $this->connection->table('b2m_posts_b2m_tags')
            ->where('b2m_posts_id', '=', 2)
            ->where('b2m_tags_id', '=', 1)
            ->first();
        self::assertNotNull($bare);
        self::assertNull($bare->position);
    }

    /**
     * A composite-PK RELATED model fails fast at relation construction —
     * pivot keys are scalar-only.
     */
    public function testBelongsToManyRejectsCompositePivotKeyOnRelated(): void
    {
        $post = new B2mPost();
        $post->title = 'unsaved';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('on the related model');

        new BelongsToMany($post, CmpRegion::class);
    }

    /**
     * A composite-PK PARENT fails fast at relation construction — the
     * parent side is checked first.
     */
    public function testBelongsToManyRejectsCompositePivotKeyOnParent(): void
    {
        $region = new CmpRegion();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('on the parent model');

        new BelongsToMany($region, B2mTag::class);
    }

    /**
     * A parent with a NULL key loads an EMPTY relation — the constraint
     * compiles as `1 = 0` instead of a meaningless pivot filter.
     */
    public function testNullParentKeyLoadsEmptyRelation(): void
    {
        $this->seedB2m();

        $unsaved = new B2mPost();
        $unsaved->title = 'no key';

        self::assertCount(0, $unsaved->tags()->getResults());
    }

    // ---- MorphOne lazy first-match ----

    /**
     * MorphOne's lazy read keeps only the FIRST matching row — two
     * images on one post resolve to the lower-PK one.
     */
    public function testMorphOneLazyReadKeepsFirstMatch(): void
    {
        $post = $this->seedPoly();

        $images = $post->image()->getResults();
        self::assertCount(1, $images);
        self::assertNotNull($images->first());
        self::assertSame('first.png', $images->first()->path);
    }

    // ---- Shared concerns ----

    /**
     * The stale flag round-trips: markStale() sets it, clearStale()
     * clears it — the reconnect path's contract.
     */
    public function testClearStaleRoundTrips(): void
    {
        self::assertFalse($this->connection->isStale());

        $this->connection->markStale();
        self::assertTrue($this->connection->isStale());

        $this->connection->clearStale();
        self::assertFalse($this->connection->isStale());
    }

    /**
     * quoteLiteral() renders every scalar arm: null bare, bools as 1/0,
     * numbers unquoted, strings single-quoted with internal quotes
     * doubled.
     */
    public function testQuoteLiteralArms(): void
    {
        $quoter = new /** The test-only quoter wrapper — exposes the trait's protected quoter. */ class {
            use QuotesLiterals;

            /**
             * Expose the protected quoter for the test.
             *
             * @param  string|int|float|bool|null  $value
             * @return string
             */
            public function quote(string|int|float|bool|null $value): string
            {
                return $this->quoteLiteral($value);
            }
        };

        self::assertSame('null', $quoter->quote(null));
        self::assertSame('1', $quoter->quote(true));
        self::assertSame('0', $quoter->quote(false));
        self::assertSame('42', $quoter->quote(42));
        self::assertSame('1.5', $quoter->quote(1.5));
        self::assertSame("'it''s'", $quoter->quote("it's"));
    }
}
