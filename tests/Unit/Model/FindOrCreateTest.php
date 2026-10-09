<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Model;

use BlueprintAU\Radiant\Database\Query\Enums\WhereOperator;
use BlueprintAU\Radiant\Database\Exceptions\QueryException;
use BlueprintAU\Radiant\Tests\Support\DatabaseTestCase;
use BlueprintAU\Radiant\Tests\Support\Expectation;
use BlueprintAU\Radiant\Tests\Unit\Model\Fixtures\FcDefaulted;
use BlueprintAU\Radiant\Tests\Unit\Model\Fixtures\FcSlug;
use BlueprintAU\Radiant\Tests\Unit\Model\Fixtures\FcUser;
use BlueprintAU\Radiant\Tests\Unit\Model\Fixtures\FcVetoUser;
use BlueprintAU\Radiant\Tests\Unit\Model\Fixtures\OfPost;
use BlueprintAU\Radiant\Tests\Unit\Model\Fixtures\OfUser;

/**
 * The find-else-create family: firstOrCreate() (builder + relation level,
 * wheres-as-match with the Eq audit) and findOrCreate() (builder, static,
 * relation level) — plus the lifecycle flags the hit/miss distinction
 * rides on.
 */
final class FindOrCreateTest extends DatabaseTestCase
{
    /**
     * Create the fixture tables and seed rows.
     */
    protected function setUpDatabase(): void
    {
        $this->createTables(FcUser::class, FcSlug::class, OfUser::class, OfPost::class, FcDefaulted::class);

        $ada = new FcUser();
        $ada->email = 'ada@example.com';
        $ada->name = 'ada';
        $ada->save();

        $author = new OfUser();
        $author->name = 'author';
        $author->save();

        $post = new OfPost();
        $post->authorId = $author->id;
        $post->title = 'seeded';
        $post->save();
    }

    // ---- Builder firstOrCreate (wheres-as-match) ----

    /**
     * A matching where finds the existing model — the hit reads
     * wasRecentlyCreated as false.
     */
    public function testFirstOrCreateHitReturnsExisting(): void
    {
        $user = OfUser::newQuery()
            ->where('name', WhereOperator::Eq, 'author')
            ->firstOrCreate();

        self::assertInstanceOf(OfUser::class, $user);
        self::assertFalse($user->wasRecentlyCreated);
        self::assertTrue($user->exists);
        self::assertSame(1, OfUser::newQuery()->count());
    }

    /**
     * A miss creates a model carrying the match Eqs plus the values —
     * and the created row satisfies the match that failed to find it.
     */
    public function testFirstOrCreateMissCreatesMatchSatisfyingRow(): void
    {
        $user = OfUser::newQuery()
            ->where('name', WhereOperator::Eq, 'newcomer')
            ->firstOrCreate();

        self::assertInstanceOf(OfUser::class, $user);
        self::assertTrue($user->wasRecentlyCreated);
        self::assertTrue($user->exists);
        self::assertSame('newcomer', $user->name);

        // The created row satisfies its own match — a second identical
        // call is a HIT, not another create.
        $second = OfUser::newQuery()
            ->where('name', WhereOperator::Eq, 'newcomer')
            ->firstOrCreate();

        self::assertSame($user->id, $second->id);
        self::assertFalse($second->wasRecentlyCreated);
        self::assertSame(2, OfUser::newQuery()->count());
    }

    /**
     * $values contributes create-only extras — present on a miss, ignored
     * on a hit, and never part of the match.
     */
    public function testFirstOrCreateValuesFillOnMissOnly(): void
    {
        $created = OfUser::newQuery()
            ->where('name', WhereOperator::Eq, 'valued')
            ->firstOrCreate();

        self::assertTrue($created->wasRecentlyCreated);

        // $values ride the create: find by a DIFFERENT column, pass the
        // name as a value — the create carries it, the hit ignores it.
        $hit = OfUser::newQuery()
            ->where('name', WhereOperator::Eq, 'valued')
            ->firstOrCreate(['name' => 'ignored-on-hit']);

        self::assertSame($created->id, $hit->id);
        self::assertSame('valued', $hit->name);
    }

    /**
     * A miss over a UNIQUE-backed column surfaces the constraint as a
     * QueryException — the race is loud by design, not retried.
     */
    public function testFirstOrCreateLostRaceSurfacesQueryException(): void
    {
        // The UNIQUE index on email backs the race: two creators probe the
        // same email at once — the second's INSERT collides.
        $creator = fn () => FcUser::newQuery()
            ->where('name', WhereOperator::Eq, 'racer')
            ->firstOrCreate(['email' => 'racer@example.com']);

        $winner = $creator();
        self::assertTrue($winner->wasRecentlyCreated);

        // The loser probes on a where that MISSES (the winner's email is
        // not in the match) but INSERTs the same unique value.
        Expectation::throws(
            fn () => FcUser::newQuery()
                ->where('name', WhereOperator::Eq, 'loser')
                ->firstOrCreate(['email' => 'racer@example.com']),
            QueryException::class,
        );
    }

    // ---- The Eq audit ----

    /**
     * A non-Eq where cannot invert into a fill — the audit fails fast.
     * The where must MISS so the audit actually runs (a hit returns
     * before it).
     */
    public function testFirstOrCreateRejectsNonEqWhere(): void
    {
        Expectation::throwsWithMessage(
            fn () => OfUser::newQuery()
                ->where('name', WhereOperator::Eq, 'nobody-here')
                ->where('id', WhereOperator::Gt, 0)
                ->firstOrCreate(),
            \InvalidArgumentException::class,
            'cannot invert the where clause into a fill',
        );
    }

    /**
     * A raw where cannot invert — the audit fails fast (a raw clause has
     * no column to seed).
     */
    public function testFirstOrCreateRejectsRawWhere(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('cannot invert the where clause into a fill');

        OfUser::newQuery()
            ->whereRaw('name = ?', ['nobody-either'])
            ->firstOrCreate();
    }

    /**
     * A non-Eq where whose column the caller SEEDS in $values composes
     * legitimately — the created row satisfies the constraint via the
     * caller's value, so the audit skips it instead of throwing.
     */
    public function testFirstOrCreateToleratesSeededConstraint(): void
    {
        $user = OfUser::newQuery()
            ->where('name', WhereOperator::Eq, 'seeded-range')
            ->where('id', WhereOperator::Gt, 0)
            ->firstOrCreate(['id' => 100]);

        self::assertTrue($user->wasRecentlyCreated);
        self::assertSame(100, $user->id);
        self::assertSame('seeded-range', $user->name);
    }

    /**
     * A `saving` listener's veto reports false and nothing lands —
     * save()'s contract. The create family returns the unsaved model on
     * the same path (the flags stay truthful).
     */
    public function testVetoedCreateReturnsUnsavedModel(): void
    {
        $vetoing = new FcVetoUser();
        $vetoing->email = 'vetoed@example.com';
        $vetoing->registerVeto();

        self::assertFalse($vetoing->save());
        self::assertFalse($vetoing->exists);
        self::assertFalse($vetoing->wasRecentlyCreated);
        self::assertSame(1, FcUser::newQuery()->count());
    }

    /**
     * The soft-delete scope (a traitScope-marked nested group) does not
     * block the audit — the helper composes with soft-deleting models.
     */
    public function testFirstOrCreateToleratesTraitScope(): void
    {
        $user = OfUser::newQuery()
            ->where('name', WhereOperator::Eq, 'scoped')
            ->firstOrCreate(['name' => 'ignored']);

        self::assertTrue($user->wasRecentlyCreated);
        self::assertSame('scoped', $user->name);
    }

    // ---- Builder findOrCreate ----

    /**
     * findOrCreate hits on an existing key.
     */
    public function testFindOrCreateHitReturnsExisting(): void
    {
        $user = OfUser::newQuery()->findOrCreate(1);

        self::assertInstanceOf(OfUser::class, $user);
        self::assertFalse($user->wasRecentlyCreated);
    }

    /**
     * findOrCreate's miss creates a row addressed by the key.
     */
    public function testFindOrCreateMissCreatesKeyedRow(): void
    {
        $user = OfUser::newQuery()->findOrCreate(50, ['name' => 'keyed']);

        self::assertTrue($user->wasRecentlyCreated);
        self::assertSame(50, $user->id);
        self::assertSame('keyed', $user->name);

        // The row is now addressable by its key.
        $found = OfUser::findOrFail(50);
        self::assertSame('keyed', $found->name);
    }

    /**
     * The builder's invertible wheres fill beneath the key on a miss.
     */
    public function testFindOrCreateMissFillsConstraintWheres(): void
    {
        $post = OfPost::newQuery()
            ->where('title', WhereOperator::Eq, 'constrained')
            ->findOrCreate(10, ['author_id' => 1]);

        self::assertTrue($post->wasRecentlyCreated);
        self::assertSame(10, $post->id);
        self::assertSame('constrained', $post->title);
    }

    /**
     * A non-auto-increment PK must be covered by the key — otherwise the
     * create fails fast (an unaddressable row is never born).
     */
    public function testFindOrCreateRejectsUnaddressableCreate(): void
    {
        Expectation::throwsWithMessage(
            fn () => FcSlug::newQuery()->firstOrCreate(),
            \InvalidArgumentException::class,
            'not auto-generated',
        );
    }

    /**
     * A key list cannot create — a create has exactly one target. The
     * list form is the whereKey() batching shape; no row matches either
     * key, so the miss path runs and the guard rejects the create.
     */
    public function testFindOrCreateRejectsKeyList(): void
    {
        /** @var mixed $list */
        $list = [900, 901];

        Expectation::throwsWithMessage(
            fn () => OfUser::newQuery()->findOrCreate($list),
            \InvalidArgumentException::class,
            'not a key list',
        );
    }

    /**
     * A null key cannot create. The lookup fails fast FIRST — where(null)
     * can never match (the SQL NULL guard) — so the thrown error is the
     * where guard's, and the key-side create guard never runs.
     */
    public function testFindOrCreateRejectsNullKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('can never match');

        OfUser::newQuery()->findOrCreate(null);
    }

    // ---- Static findOrCreate ----

    /**
     * The static forwarder hits and misses like the builder form.
     */
    public function testStaticFindOrCreate(): void
    {
        $hit = OfUser::findOrCreate(1);
        self::assertFalse($hit->wasRecentlyCreated);

        $miss = OfUser::findOrCreate(51, ['name' => 'static']);
        self::assertTrue($miss->wasRecentlyCreated);
        self::assertSame('static', $miss->name);
        self::assertInstanceOf(OfUser::class, $miss);
    }

    // ---- Relation level ----

    /**
     * The relation's constraint fills the FK — a created post carries the
     * parent's user_id. The constraint is the author's FK equality only;
     * an author with no posts misses, and the create carries the FK.
     */
    public function testRelationFirstOrCreateFillsFk(): void
    {
        $barren = new OfUser();
        $barren->name = 'barren';
        $barren->save();

        $post = $barren->posts()->firstOrCreate(['title' => 'relation-made']);

        self::assertTrue($post->wasRecentlyCreated);
        self::assertSame($barren->id, $post->authorId);
        self::assertSame('relation-made', $post->title);

        // The created post satisfies the constraint — a second identical
        // call is a hit on it.
        $hit = $barren->posts()->firstOrCreate(['title' => 'ignored-on-hit']);

        self::assertSame($post->id, $hit->id);
        self::assertFalse($hit->wasRecentlyCreated);
        self::assertSame('relation-made', $hit->title);
    }

    /**
     * Relation-level findOrCreate hits within the constraint and creates
     * carrying both the key and the constraint.
     */
    public function testRelationFindOrCreate(): void
    {
        $author = OfUser::findOrFail(1);

        $post = $author->posts()->findOrCreate(7, ['title' => 'relation-keyed']);

        self::assertTrue($post->wasRecentlyCreated);
        self::assertSame(7, $post->id);
        self::assertSame($author->id, $post->authorId);
    }

    // ---- Lifecycle flags ----

    /**
     * A re-save of a created model keeps wasRecentlyCreated true; a
     * fetched model never carries the flag.
     */
    public function testWasRecentlyCreatedSurvivesResaveOnly(): void
    {
        $created = OfUser::findOrCreate(60, ['name' => 'persisted']);
        self::assertTrue($created->wasRecentlyCreated);

        $created->name = 'persisted-two';
        $created->save();
        self::assertTrue($created->wasRecentlyCreated, 'a re-save is not a re-create');

        $fetched = OfUser::findOrFail(60);
        self::assertFalse($fetched->wasRecentlyCreated);
        self::assertSame('persisted-two', $fetched->name);
    }

    // ---- Column defaults on the create path ----

    /**
     * A create-family miss rides the full insert machinery — declared
     * column defaults materialize onto the created model exactly as a
     * plain save() would.
     */
    public function testFindOrCreateMaterializesColumnDefaults(): void
    {
        $model = FcDefaulted::newQuery()
            ->where('author', '=', 'anon')
            ->firstOrCreate();

        self::assertTrue($model->wasRecentlyCreated);
        self::assertSame(0, $model->hits);
        self::assertSame('anon', $model->author);

        // The row agrees with the model — the INSERT applied the defaults.
        $raw = $this->connection->table('fc_defaulteds')->first();
        self::assertNotNull($raw);
        self::assertSame(0, $raw->hits);
        self::assertSame('anon', $raw->author);
    }

    /**
     * A defaulted column the create EXPLICITLY sets keeps the caller's
     * value — the default only fills what the caller left out.
     */
    public function testFindOrCreateExplicitValueBeatsDefault(): void
    {
        $model = FcDefaulted::newQuery()
            ->where('author', '=', 'named')
            ->firstOrCreate(['hits' => 5]);

        self::assertTrue($model->wasRecentlyCreated);
        self::assertSame('named', $model->author);
        self::assertSame(5, $model->hits);
    }
}
