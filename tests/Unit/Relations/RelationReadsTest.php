<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Relations;

use BlueprintAU\Radiant\Database\Query\Aggregate;
use BlueprintAU\Radiant\Tests\Support\DatabaseTestCase;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\RelPost;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\RelTeam;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\RelTeamPost;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\RelUser;

/**
 * The relation-level read family: first/find/count/exists/cursor plus the
 * scalar reads and grouped aggregates — every read scoped to the
 * relation's FK constraint.
 */
final class RelationReadsTest extends DatabaseTestCase
{
    /**
     * Create the rel_users / rel_posts / rel_teams / rel_team_posts
     * fixture tables from the models' attributes.
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

    // ---- Row reads ----

    /**
     * first() returns the first related model.
     */
    public function testFirstReturnsFirstRelatedModel(): void
    {
        ['user' => $user] = $this->seed();

        $first = $user->posts()->first();

        self::assertInstanceOf(RelPost::class, $first);
        self::assertSame('First', $first->title);
    }

    /**
     * first() returns null when the relation matches no rows.
     */
    public function testFirstReturnsNullWithoutMatch(): void
    {
        ['user' => $user] = $this->seed();

        $postless = new RelUser();
        $postless->email = 'empty@example.com';
        $postless->save();

        self::assertNull($postless->posts()->first());
    }

    /**
     * find() scopes to the relation's constraint — another parent's row
     * does not match.
     */
    public function testFindScopesToTheRelationConstraint(): void
    {
        ['user' => $user] = $this->seed();

        $other = new RelUser();
        $other->email = 'other@example.com';
        $other->save();

        $own = RelPost::where('title', '=', 'First')->first();
        self::assertNotNull($own);

        self::assertNotNull($user->posts()->find($own->id));
        self::assertNull($other->posts()->find($own->id));
    }

    /**
     * count()/exists() cover only THIS parent's related rows.
     */
    public function testCountAndExistsScopeToTheRelation(): void
    {
        ['user' => $user] = $this->seed();

        $postless = new RelUser();
        $postless->email = 'empty@example.com';
        $postless->save();

        self::assertSame(2, $user->posts()->count());
        self::assertTrue($user->posts()->exists());
        self::assertSame(0, $postless->posts()->count());
        self::assertFalse($postless->posts()->exists());
    }

    /**
     * cursor() streams the related models.
     */
    public function testCursorYieldsRelatedModels(): void
    {
        ['user' => $user] = $this->seed();

        $titles = [];

        foreach ($user->posts()->orderBy('title')->cursor() as $post) {
            $titles[] = $post->title;
        }

        self::assertSame(['First', 'Second'], $titles);
    }

    // ---- Scalar reads ----

    /**
     * value() decodes through the column's cast.
     */
    public function testValueDecodesThroughTheCast(): void
    {
        ['user' => $user] = $this->seed();

        self::assertSame('First', $user->posts()->value('title'));
    }

    /**
     * pluck() decodes every value through the casts.
     */
    public function testPluckDecodesThroughTheCasts(): void
    {
        ['user' => $user] = $this->seed();

        self::assertSame(['First', 'Second'], $user->posts()->orderBy('title')->pluck('title')->all());
    }

    /**
     * max()/sum() decode through the cast for declared columns.
     */
    public function testMaxAndSumDecodeThroughTheCasts(): void
    {
        ['user' => $user] = $this->seed();

        self::assertSame(10, $user->posts()->max('views'));
        self::assertSame(10, $user->posts()->sum('views'));
    }

    /**
     * avg() yields the numeric average.
     */
    public function testAvgYieldsTheAverage(): void
    {
        ['user' => $user] = $this->seed();

        self::assertEquals(10, $user->posts()->avg('views'));
    }

    /**
     * aggregates() returns the multi-aggregate row keyed by alias.
     */
    public function testAggregatesReturnsTheRow(): void
    {
        ['user' => $user] = $this->seed();

        $row = $user->posts()->aggregates(
            Aggregate::count('*', 'total'),
            Aggregate::max('views', 'top'),
        );

        self::assertSame(2, $row->total);
        self::assertSame(10, $row->top);
    }

    /**
     * The scalar reads compose with the relation's filters.
     */
    public function testScalarReadsComposeWithFilters(): void
    {
        ['user' => $user] = $this->seed();

        self::assertSame('Second', $user->posts()->where('status', '=', 'draft')->value('title'));
        self::assertSame(['Second'], $user->posts()->where('status', '=', 'draft')->pluck('title')->all());
    }

    // ---- Relation-shape reads ----

    /**
     * HasOne's first() is the stable PK-ordered row.
     */
    public function testHasOneFirstIsStable(): void
    {
        ['user' => $user] = $this->seed();

        $featured = $user->featuredPost()->first();

        self::assertInstanceOf(RelPost::class, $featured);
        self::assertSame('First', $featured->title);
    }

    /**
     * A through relation's reads run over the joined query.
     */
    public function testThroughRelationReads(): void
    {
        ['user' => $user] = $this->seed();

        self::assertSame(2, $user->teamPosts()->count());

        $first = $user->teamPosts()->first();

        self::assertInstanceOf(RelTeamPost::class, $first);
        self::assertSame('Alpha', $first->title);
    }

    /**
     * A null FK reads as empty across the whole family — first() null,
     * count() 0, exists() false.
     */
    public function testBelongsToNullFkReads(): void
    {
        ['user' => $user] = $this->seed();

        $orphan = RelPost::where('title', '=', 'Orphan')->first();
        self::assertNotNull($orphan);

        self::assertNull($orphan->author()->first());
        self::assertSame(0, $orphan->author()->count());
        self::assertFalse($orphan->author()->exists());
    }
}
