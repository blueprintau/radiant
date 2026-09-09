<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Relations;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Attributes\Table;
use BlueprintAU\Radiant\Collection;
use BlueprintAU\Radiant\Database\Connections\SqlConnection;
use BlueprintAU\Radiant\Database\DatabaseManager;
use BlueprintAU\Radiant\Database\Schema\Blueprint;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;
use PHPUnit\Framework\TestCase;

/**
 * Fixture: the parent — one-to-many owner.
 */
#[Table(name: 'rel_users')]
class RelUser extends Model
{
    /**
     * The auto-increment primary key.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * The user's email.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 255)]
    public string $email;

    /**
     * The user's posts (one-to-many).
     *
     * @return \BlueprintAU\Radiant\Relations\HasMany<RelPost>
     */
    public function posts(): \BlueprintAU\Radiant\Relations\HasMany
    {
        return $this->hasMany(RelPost::class, 'author_id');
    }

    /**
     * A relation declaring a nonexistent FK — the fail-fast cross-check
     * fixture.
     *
     * @return \BlueprintAU\Radiant\Relations\HasMany<RelPost>
     */
    public function brokenPosts(): \BlueprintAU\Radiant\Relations\HasMany
    {
        return $this->hasMany(RelPost::class, 'bogus_id');
    }

    /**
     * The user's newest post (one-to-one).
     *
     * @return \BlueprintAU\Radiant\Relations\HasOne<RelPost>
     */
    public function featuredPost(): \BlueprintAU\Radiant\Relations\HasOne
    {
        return $this->hasOne(RelPost::class, 'author_id');
    }

    /**
     * A non-relation method referenced in with() — the fail-fast fixture.
     *
     * @return string
     */
    public function notARelation(): string
    {
        return 'nope';
    }

    /**
     * The user's team posts (two-hop through the team).
     *
     * @return \BlueprintAU\Radiant\Relations\HasManyThrough<RelTeamPost>
     */
    public function teamPosts(): \BlueprintAU\Radiant\Relations\HasManyThrough
    {
        return $this->hasManyThrough(RelTeamPost::class, RelTeam::class, 'owner_id', 'team_id');
    }

    /**
     * The user's first team post (one-to-one through the team).
     *
     * @return \BlueprintAU\Radiant\Relations\HasOneThrough<RelTeamPost>
     */
    public function featuredTeamPost(): \BlueprintAU\Radiant\Relations\HasOneThrough
    {
        return $this->hasOneThrough(RelTeamPost::class, RelTeam::class, 'owner_id', 'team_id');
    }
}

/**
 * Fixture: the child — belongsTo + hasMany target.
 */
#[Table(name: 'rel_posts')]
class RelPost extends Model
{
    /**
     * The auto-increment primary key.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * The author's id (the FK; stored as author_id).
     *
     * @var int|null
     */
    #[Column(type: ColumnType::BigInt, nullable: true, name: 'author_id')]
    public ?int $authorId;

    /**
     * The post title.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 255)]
    public string $title;

    /**
     * The post's author (inverse).
     *
     * @return \BlueprintAU\Radiant\Relations\BelongsTo<RelUser>
     */
    public function author(): \BlueprintAU\Radiant\Relations\BelongsTo
    {
        return $this->belongsTo(RelUser::class, 'author_id');
    }
}

/**
 * Fixture: an intermediate table for through relations.
 */
#[Table(name: 'rel_teams')]
class RelTeam extends Model
{
    /**
     * The auto-increment primary key.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * The team owner's user id (stored as owner_id).
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, name: 'owner_id')]
    public int $ownerId;

    /**
     * The team name.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 64)]
    public string $name;

    /**
     * The team's owner (inverse belongsTo) — the third level of the
     * teamPosts nesting chain.
     *
     * @return \BlueprintAU\Radiant\Relations\BelongsTo<RelUser>
     */
    public function owner(): \BlueprintAU\Radiant\Relations\BelongsTo
    {
        return $this->belongsTo(RelUser::class, 'owner_id');
    }
}

/**
 * Fixture: the through target — a post that belongs to a team.
 */
#[Table(name: 'rel_team_posts')]
class RelTeamPost extends Model
{
    /**
     * The auto-increment primary key.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * The team's id (stored as team_id).
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, name: 'team_id')]
    public int $teamId;

    /**
     * The post title.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 255)]
    public string $title;

    /**
     * The post's team.
     *
     * @return \BlueprintAU\Radiant\Relations\BelongsTo<RelTeam>
     */
    public function team(): \BlueprintAU\Radiant\Relations\BelongsTo
    {
        return $this->belongsTo(RelTeam::class, 'team_id');
    }
}

/**
 * End-to-end relation tests on live SQLite: lazy + eager HasMany / HasOne /
 * BelongsTo, eager through, Collection::load()/fresh(), and the fail-fast
 * cross-checks.
 */
final class RelationsE2ETest extends TestCase
{
    /**
     * The live SQLite connection.
     *
     * @var SqlConnection
     */
    private SqlConnection $connection;

    /**
     * Build a :memory: SQLite manager and create the fixture tables.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $manager = new DatabaseManager([
            'default' => ['driver' => 'sqlite', 'database' => ':memory:'],
        ]);
        \BlueprintAU\Radiant\Database::setManager($manager);
        $this->connection = $manager->sqlConnection();

        $this->connection->create((new Blueprint('rel_users'))
            ->column(ColumnType::BigInt, 'id', primaryKey: true, autoIncrement: true)
            ->column(ColumnType::String, 'email', length: 255));

        $this->connection->create((new Blueprint('rel_posts'))
            ->column(ColumnType::BigInt, 'id', primaryKey: true, autoIncrement: true)
            ->column(ColumnType::BigInt, 'author_id', nullable: true)
            ->column(ColumnType::String, 'title', length: 255));

        $this->connection->create((new Blueprint('rel_teams'))
            ->column(ColumnType::BigInt, 'id', primaryKey: true, autoIncrement: true)
            ->column(ColumnType::BigInt, 'owner_id')
            ->column(ColumnType::String, 'name', length: 64));

        $this->connection->create((new Blueprint('rel_team_posts'))
            ->column(ColumnType::BigInt, 'id', primaryKey: true, autoIncrement: true)
            ->column(ColumnType::BigInt, 'team_id')
            ->column(ColumnType::String, 'title', length: 255));
    }

    /**
     * Tear down the static facade so other tests are unaffected.
     */
    protected function tearDown(): void
    {
        \BlueprintAU\Radiant\Database::setManager(new DatabaseManager([
            'default' => ['driver' => 'sqlite', 'database' => ':memory:'],
        ]));
        parent::tearDown();
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

        foreach (['First', 'Second'] as $title) {
            $post = new RelPost();
            $post->authorId = $user->id;
            $post->title = $title;
            $post->save();
        }

        $orphan = new RelPost();
        $orphan->authorId = null;
        $orphan->title = 'Orphan';
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
        self::assertSame('First', $featured[0]->title);
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
        self::assertTrue($users[0]->relationLoaded('posts'));
        self::assertCount(2, $users[0]->getRelation('posts'));
        self::assertCount(0, $users[1]->getRelation('posts'), 'the second user has no posts');
    }

    /**
     * Eager BelongsTo via with() on the child query.
     */
    public function testEagerWithBelongsTo(): void
    {
        $this->seed();

        $posts = RelPost::with('author')->orderBy('id')->get();

        self::assertCount(3, $posts);
        self::assertInstanceOf(RelUser::class, $posts[0]->getRelation('author'));
        self::assertNull($posts[2]->getRelation('author'), 'the orphan has no author');
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
        $firstTeam = $teamPosts[0]->getRelation('team');
        $secondTeam = $teamPosts[1]->getRelation('team');
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
        $team = $teamPosts[0]->getRelation('team');
        self::assertInstanceOf(RelTeam::class, $team);
        self::assertTrue($team->relationLoaded('owner'));
        self::assertNotNull($team->getRelation('owner'));
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
     * whereKey() rejects key shapes outside the KeyValue contract — the
     * PHPDoc type cannot be enforced natively, so the boundary validates.
     * The keys arrive through mixed-typed helpers, simulating a caller
     * without a static analyzer.
     */
    public function testWhereKeyRejectsInvalidShapes(): void
    {
        $this->seed();

        try {
            $this->whereKeyUntyped(true);
            self::fail('Expected an InvalidArgumentException for a bool key.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('must be int, string or null; got bool', $e->getMessage());
        }

        try {
            $this->whereKeyUntyped(['id' => new \stdClass()]);
            self::fail('Expected an InvalidArgumentException for an object key value.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('must be int, string or null; got stdClass', $e->getMessage());
        }

        try {
            $this->whereKeyUntyped([0 => 1]);
            self::fail('Expected an InvalidArgumentException for a non-string column.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('string column names; got int', $e->getMessage());
        }
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
        $posts = $loaded->getRelation('teamPosts');
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
        self::assertInstanceOf(RelTeamPost::class, $loaded->getRelation('featuredTeamPost'));
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

        self::assertTrue($users[0]->relationLoaded('posts'));
        self::assertCount(2, $users[0]->getRelation('posts'));
    }

    /**
     * Collection::fresh() re-queries each model by key (scoped), replacing
     * the items.
     */
    public function testCollectionFresh(): void
    {
        ['user' => $user] = $this->seed();

        $users = RelUser::all();
        $users[0]->email = 'changed-in-memory@example.com';

        $users->fresh();

        self::assertSame('alicia@example.com', $users[0]->email, 'fresh() re-reads from the database');
        self::assertSame($user->id, $users[0]->id);
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
}
