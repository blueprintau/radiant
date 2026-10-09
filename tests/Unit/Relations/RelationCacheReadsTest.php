<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Relations;

use BlueprintAU\Radiant\Exceptions\ModelNotFoundException;
use BlueprintAU\Radiant\Exceptions\MultipleRecordsFoundException;
use BlueprintAU\Radiant\Tests\Support\DatabaseTestCase;
use BlueprintAU\Radiant\Tests\Support\Expectation;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\RelPost;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\RelTeam;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\RelTeamPost;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\RelUser;

/**
 * The row reads' eager-cache path: after with()/load(), first()/find()/
 * firstOrFail()/findOrFail()/sole()/count()/exists()/cursor() are served
 * from the loaded snapshot — proven by mutating the database between the
 * load and the read (a cached read cannot see the change, fresh: can).
 */
final class RelationCacheReadsTest extends DatabaseTestCase
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
     * Seed one user with two posts, one orphan post, and a team chain.
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

    // ---- The snapshot-serves proof ----

    /**
     * first() is served from the eager-loaded snapshot: deleting the
     * user's posts AFTER with() changes nothing, and fresh: sees the
     * emptied database.
     */
    public function testFirstIsServedFromTheSnapshot(): void
    {
        ['user' => $user] = $this->seed();

        $loaded = RelUser::with('posts')->find($user->id);
        self::assertNotNull($loaded);

        $this->connection->table('rel_posts')->where('author_id', '=', $user->id)->delete();

        $first = $loaded->posts()->first();
        self::assertInstanceOf(RelPost::class, $first);
        self::assertSame('First', $first->title, 'the snapshot serves first() despite the delete');

        $fresh = $loaded->posts()->first(fresh: true);
        self::assertNull($fresh, 'fresh: bypasses the snapshot to the emptied database');
    }

    /**
     * find() is served from the snapshot — membership of the loaded set.
     */
    public function testFindIsServedFromTheSnapshot(): void
    {
        ['user' => $user] = $this->seed();

        $loaded = RelUser::with('posts')->find($user->id);
        self::assertNotNull($loaded);

        $second = RelPost::where('title', '=', 'Second')->first();
        self::assertNotNull($second);

        // Another parent's post is not a member of the loaded set.
        $other = new RelUser();
        $other->email = 'other@example.com';
        $other->save();

        self::assertNotNull($loaded->posts()->find($second->id));
        self::assertNull($other->posts()->find($second->id), 'an unloaded relation runs the constrained query');
    }

    /**
     * count()/exists() report the snapshot's size.
     */
    public function testCountAndExistsAreServedFromTheSnapshot(): void
    {
        ['user' => $user] = $this->seed();

        $loaded = RelUser::with('posts')->find($user->id);
        self::assertNotNull($loaded);

        $postless = new RelUser();
        $postless->email = 'empty@example.com';
        $postless->save();

        $emptyLoaded = RelUser::with('posts')->find($postless->id);
        self::assertNotNull($emptyLoaded);

        $this->connection->table('rel_posts')->where('author_id', '=', $user->id)->delete();

        self::assertSame(2, $loaded->posts()->count(), 'the snapshot count survives the delete');
        self::assertTrue($loaded->posts()->exists());

        self::assertSame(0, $emptyLoaded->posts()->count());
        self::assertFalse($emptyLoaded->posts()->exists());

        self::assertSame(0, $loaded->posts()->count(fresh: true), 'fresh: counts the emptied database');
        self::assertFalse($loaded->posts()->exists(fresh: true));
    }

    /**
     * cursor() streams the snapshot's items.
     */
    public function testCursorStreamsTheSnapshot(): void
    {
        ['user' => $user] = $this->seed();

        $loaded = RelUser::with('posts')->find($user->id);
        self::assertNotNull($loaded);

        $this->connection->table('rel_posts')->where('author_id', '=', $user->id)->delete();

        $titles = [];

        foreach ($loaded->posts()->cursor() as $post) {
            $titles[] = $post->title;
        }

        self::assertSame(['First', 'Second'], $titles, 'the snapshot streams despite the delete');

        $freshTitles = [];

        foreach ($loaded->posts()->cursor(fresh: true) as $post) {
            $freshTitles[] = $post->title;
        }

        self::assertSame([], $freshTitles, 'fresh: streams the emptied database');
    }

    // ---- The fail-fast family on the snapshot ----

    /**
     * firstOrFail() on a snapshot-empty relation throws without a query.
     */
    public function testFirstOrFailOnSnapshotEmptyThrows(): void
    {
        ['user' => $user] = $this->seed();

        $postless = new RelUser();
        $postless->email = 'empty@example.com';
        $postless->save();

        $loaded = RelUser::with('posts')->find($postless->id);
        self::assertNotNull($loaded);

        $this->connection->table('rel_posts')->delete();

        $exception = Expectation::throws(
            fn () => $loaded->posts()->firstOrFail(),
            ModelNotFoundException::class,
        );

        self::assertSame(RelPost::class, $exception->model);
        self::assertNull($exception->key);

        // fresh: reaches the live database — also empty, also throws.
        $this->expectException(ModelNotFoundException::class);
        $this->expectExceptionMessageIsOrContains('No query results for model');
        $loaded->posts()->firstOrFail(fresh: true);
    }

    /**
     * findOrFail() on the snapshot throws naming the lookup key.
     */
    public function testFindOrFailOnSnapshotMissThrows(): void
    {
        ['user' => $user] = $this->seed();

        $loaded = RelUser::with('posts')->find($user->id);
        self::assertNotNull($loaded);

        $exception = Expectation::throws(
            fn () => $loaded->posts()->findOrFail(99999),
            ModelNotFoundException::class,
        );

        self::assertSame(99999, $exception->key);
    }

    /**
     * sole() on the snapshot: zero throws NotFound, one returns, two
     * throws MultipleRecords — the loaded set's size decides.
     */
    public function testSoleDecidesOnTheSnapshotSize(): void
    {
        ['user' => $user] = $this->seed();

        // Two rows loaded → the snapshot size decides: throw.
        $loaded = RelUser::with('posts')->find($user->id);
        self::assertNotNull($loaded);

        $exception = Expectation::throws(
            fn () => $loaded->posts()->sole(),
            MultipleRecordsFoundException::class,
        );

        self::assertSame(2, $exception->count);

        // Delete one row, RE-load → the new snapshot holds one row: return.
        $this->connection->table('rel_posts')->where('title', '=', 'Second')->delete();

        $reloaded = RelUser::with('posts')->find($user->id);
        self::assertNotNull($reloaded);

        $sole = $reloaded->posts()->sole();
        self::assertInstanceOf(RelPost::class, $sole);
        self::assertSame('First', $sole->title);

        // Delete the last row, RE-load → the new snapshot is empty: throw.
        $this->connection->table('rel_posts')->where('title', '=', 'First')->delete();

        $emptied = RelUser::with('posts')->find($user->id);
        self::assertNotNull($emptied);

        $this->expectException(ModelNotFoundException::class);
        $this->expectExceptionMessageIsOrContains('No query results for model');
        $emptied->posts()->sole();
    }

    // ---- The gate disengages when it must ----

    /**
     * A composed chain never serves the snapshot — the filter must reach
     * the database.
     */
    public function testComposedChainBypassesTheSnapshot(): void
    {
        ['user' => $user] = $this->seed();

        $loaded = RelUser::with('posts')->find($user->id);
        self::assertNotNull($loaded);

        $this->connection->table('rel_posts')->where('title', '=', 'First')->delete();

        $filtered = $loaded->posts()->where('title', '=', 'First')->first();
        self::assertNull($filtered, 'a composed read cannot serve the unfiltered snapshot');
    }

    /**
     * An unnamed relation (built outside a relation method) never serves
     * the snapshot.
     */
    public function testUnnamedRelationBypassesTheSnapshot(): void
    {
        ['user' => $user] = $this->seed();

        // hasMany() reached directly — relationName() stamps nothing, so
        // the cache gate has no name to check.
        $unnamed = $user->posts();
        $loaded = RelUser::with('posts')->find($user->id);
        self::assertNotNull($loaded);

        $this->connection->table('rel_posts')->where('title', '=', 'Second')->delete();

        $first = $unnamed->first();
        self::assertNotNull($first);
        self::assertSame('First', $first->title, 'the unnamed relation re-queries — the delete is visible');
    }

    /**
     * An unloaded relation always executes — lazy access is never stale.
     */
    public function testUnloadedRelationAlwaysExecutes(): void
    {
        ['user' => $user] = $this->seed();

        $this->connection->table('rel_posts')->where('title', '=', 'Second')->delete();

        self::assertSame(1, $user->posts()->count(), 'no snapshot exists — the count re-queries');
    }

    /**
     * A single-valued relation's snapshot holds a model or null —
     * first() serves it, and the null shape stays nullable.
     */
    public function testSingleValuedSnapshotServesFirst(): void
    {
        ['user' => $user] = $this->seed();

        $loaded = RelUser::with('featuredPost')->find($user->id);
        self::assertNotNull($loaded);

        $this->connection->table('rel_posts')->where('author_id', '=', $user->id)->delete();

        $featured = $loaded->featuredPost()->first();
        self::assertInstanceOf(RelPost::class, $featured);
        self::assertSame('First', $featured->title, 'the snapshot model serves first()');

        $fresh = $loaded->featuredPost()->first(fresh: true);
        self::assertNull($fresh, 'fresh: re-queries the emptied database');
    }

    // ---- Scalar reads never consult the snapshot ----

    /**
     * The scalar reads execute even when a snapshot exists — the
     * aggregation policy line, locked against drift.
     */
    public function testScalarReadsIgnoreTheSnapshot(): void
    {
        ['user' => $user] = $this->seed();

        $loaded = RelUser::with('posts')->find($user->id);
        self::assertNotNull($loaded);

        $this->connection->table('rel_posts')->where('title', '=', 'First')->delete();

        self::assertSame(1, $loaded->posts()->pluck('id')->count(), 'pluck() re-queries — the delete is visible');

        $maxViews = $loaded->posts()->max('views');
        self::assertNull($maxViews, 'max() re-queries — only Second (views null) remains');

        $sumViews = $loaded->posts()->sum('views');
        self::assertNull($sumViews);
    }
}
