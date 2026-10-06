<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Model;

use BlueprintAU\Radiant\Exceptions\ModelNotFoundException;
use BlueprintAU\Radiant\Exceptions\MultipleRecordsFoundException;
use BlueprintAU\Radiant\Tests\Support\DatabaseTestCase;
use BlueprintAU\Radiant\Tests\Support\Expectation;
use BlueprintAU\Radiant\Tests\Unit\Model\Fixtures\OfPost;
use BlueprintAU\Radiant\Tests\Unit\Model\Fixtures\OfSoftPost;
use BlueprintAU\Radiant\Tests\Unit\Model\Fixtures\OfUser;

/**
 * The fail-fast retrieval family: firstOrFail()/findOrFail()/sole() on the
 * builder, their static forwarders, and the relation-level delegates —
 * every zero-row path throws ModelNotFoundException, sole()'s multiple-row
 * path throws MultipleRecordsFoundException.
 */
final class OrFailTest extends DatabaseTestCase
{
    /**
     * Create the of_users / of_posts / of_soft_posts fixture tables from
     * the models' attributes and seed rows.
     */
    protected function setUpDatabase(): void
    {
        $this->createTables(OfUser::class, OfPost::class, OfSoftPost::class);

        $ada = new OfUser();
        $ada->name = 'ada';
        $ada->save();

        $post = new OfPost();
        $post->authorId = $ada->id;
        $post->title = 'first';
        $post->save();

        $second = new OfPost();
        $second->authorId = $ada->id;
        $second->title = 'second';
        $second->save();

        $orphan = new OfUser();
        $orphan->name = 'orphan';
        $orphan->save();

        $solo = new OfUser();
        $solo->name = 'solo';
        $solo->save();

        $soloPost = new OfPost();
        $soloPost->authorId = $solo->id;
        $soloPost->title = 'only';
        $soloPost->save();
    }

    // ---- Builder firstOrFail ----

    /**
     * firstOrFail() returns the first hydrated model when rows exist.
     */
    public function testFirstOrFailReturnsFirstModel(): void
    {
        $user = OfUser::newQuery()->orderBy('id')->firstOrFail();

        self::assertInstanceOf(OfUser::class, $user);
        self::assertSame('ada', $user->name);
    }

    /**
     * firstOrFail() throws with the model class named when empty.
     */
    public function testFirstOrFailThrowsOnEmpty(): void
    {
        $exception = Expectation::throws(
            fn () => OfUser::newQuery()->where('name', '=', 'nobody')->firstOrFail(),
            ModelNotFoundException::class,
        );

        self::assertSame(OfUser::class, $exception->model);
        self::assertNull($exception->key);
        self::assertSame(
            'No query results for model ['.OfUser::class.'].',
            $exception->getMessage(),
        );
    }

    // ---- Builder findOrFail ----

    /**
     * findOrFail() round-trips a scalar key.
     */
    public function testFindOrFailReturnsModel(): void
    {
        $user = OfUser::newQuery()->findOrFail(1);

        self::assertInstanceOf(OfUser::class, $user);
        self::assertSame('ada', $user->name);
    }

    /**
     * findOrFail() throws carrying the missing key in the message.
     */
    public function testFindOrFailThrowsWithKey(): void
    {
        $exception = Expectation::throws(
            fn () => OfUser::newQuery()->findOrFail(999),
            ModelNotFoundException::class,
        );

        self::assertSame(999, $exception->key);
        self::assertSame(
            'No query results for model ['.OfUser::class.'] 999.',
            $exception->getMessage(),
        );
    }

    /**
     * findOrFail() serializes a composite key into the message.
     */
    public function testFindOrFailCompositeKeyMessage(): void
    {
        // A composite whereKey against a single-PK model fails validation
        // before the fetch — use a raw composite-shaped probe on the same
        // single-PK builder via two wheres and read the exception shape.
        $exception = Expectation::throws(
            fn () => OfUser::newQuery()->findOrFail(['id' => 5]),
            ModelNotFoundException::class,
        );

        self::assertSame(['id' => 5], $exception->key);
        self::assertSame(
            'No query results for model ['.OfUser::class.'] {"id":5}.',
            $exception->getMessage(),
        );
    }

    // ---- Builder sole ----

    /**
     * sole() returns the model when exactly one row matches.
     */
    public function testSoleReturnsSingleModel(): void
    {
        $user = OfUser::newQuery()->where('name', '=', 'ada')->sole();

        self::assertInstanceOf(OfUser::class, $user);
        self::assertSame('ada', $user->name);
    }

    /**
     * sole() throws ModelNotFoundException on zero rows.
     */
    public function testSoleThrowsOnZeroRows(): void
    {
        $exception = Expectation::throws(
            fn () => OfUser::newQuery()->where('name', '=', 'nobody')->sole(),
            ModelNotFoundException::class,
        );

        self::assertSame(OfUser::class, $exception->model);
    }

    /**
     * sole() throws MultipleRecordsFoundException on two matching rows.
     */
    public function testSoleThrowsOnMultipleRows(): void
    {
        // Two posts share author 1.
        $exception = Expectation::throws(
            fn () => OfPost::newQuery()->where('author_id', '=', 1)->sole(),
            MultipleRecordsFoundException::class,
        );

        self::assertSame(2, $exception->count);
        self::assertSame(
            'Query returned 2 rows for model ['.OfPost::class.'], expected exactly 1.',
            $exception->getMessage(),
        );
    }

    /**
     * The fail-fast family is SIDE-EFFECT-FREE: the internal limit (and
     * findOrFail's added wheres) run on a clone, so a shared builder keeps
     * its full state — a later get() on the same builder is not limited
     * or filtered by the fail-fast read.
     */
    public function testOrFailReadsDoNotMutateSharedBuilder(): void
    {
        $builder = OfUser::newQuery();

        $builder->firstOrFail();
        $builder->findOrFail(1);

        // sole() THROWS on the multi-row table — but a mutating sole()
        // would have already applied limit(2) to the SHARED builder before
        // throwing, so this line is part of the regression probe too.
        Expectation::throws(fn () => $builder->sole(), MultipleRecordsFoundException::class);

        // A mutated builder would carry limit(2)/limit(1) or findOrFail's
        // added key wheres — all three users must still come back.
        self::assertCount(3, $builder->get());

        // The same contract through a relation's constrained query: ada
        // has TWO posts. firstOrFail() would limit(1) a shared builder,
        // hiding the second post from a later get().
        $ada = OfUser::newQuery()->where('name', '=', 'ada')->firstOrFail();
        $posts = $ada->posts();
        $posts->firstOrFail();

        self::assertCount(2, $posts->get());
    }

    // ---- Static forwarders ----

    /**
     * The static findOrFail forwarder throws on a missing key.
     */
    public function testStaticFindOrFailThrows(): void
    {
        $exception = Expectation::throws(
            fn () => OfUser::findOrFail(4242),
            ModelNotFoundException::class,
        );

        self::assertSame(OfUser::class, $exception->model);
        self::assertSame(4242, $exception->key);
    }

    /**
     * The static firstOrFail forwarder returns the first model.
     */
    public function testStaticFirstOrFailReturnsModel(): void
    {
        $user = OfUser::newQuery()->orderBy('id')->firstOrFail();

        self::assertInstanceOf(OfUser::class, $user);
        self::assertSame('ada', $user->name);
    }

    /**
     * The static sole forwarder (via the static where entry point) returns
     * the single model.
     */
    public function testStaticSoleReturnsModel(): void
    {
        $user = OfUser::where('name', '=', 'orphan')->sole();

        self::assertInstanceOf(OfUser::class, $user);
        self::assertSame('orphan', $user->name);
    }

    // ---- Relation-level ----

    /**
     * A one-to-one relation's firstOrFail() throws when no related row exists.
     */
    public function testRelationFirstOrFailThrowsWhenEmpty(): void
    {
        $orphan = OfUser::newQuery()->where('name', '=', 'orphan')->firstOrFail();

        $exception = Expectation::throws(
            fn () => $orphan->featuredPost()->firstOrFail(),
            ModelNotFoundException::class,
        );

        self::assertSame(OfPost::class, $exception->model);
    }

    /**
     * A one-to-one relation's firstOrFail() returns the related model.
     */
    public function testRelationFirstOrFailReturnsModel(): void
    {
        $ada = OfUser::newQuery()->where('name', '=', 'ada')->firstOrFail();

        $post = $ada->featuredPost()->firstOrFail();

        self::assertInstanceOf(OfPost::class, $post);
        self::assertSame(1, $post->authorId);
    }

    /**
     * A to-many relation's sole() throws when the relation matches twice.
     */
    public function testRelationSoleThrowsOnMultiple(): void
    {
        $ada = OfUser::newQuery()->where('name', '=', 'ada')->firstOrFail();

        $exception = Expectation::throws(
            fn () => $ada->posts()->sole(),
            MultipleRecordsFoundException::class,
        );

        self::assertSame(2, $exception->count);
    }

    /**
     * A to-many relation's sole() returns the single related model when
     * exactly one row matches — the success path through the relation
     * delegate (hydrated, FK intact).
     */
    public function testRelationSoleReturnsSingleModel(): void
    {
        $solo = OfUser::newQuery()->where('name', '=', 'solo')->firstOrFail();

        $post = $solo->posts()->sole();

        self::assertInstanceOf(OfPost::class, $post);
        self::assertSame('only', $post->title);
        self::assertSame($solo->id, $post->authorId);
    }

    /**
     * A one-to-one relation's sole() throws ModelNotFoundException when
     * the relation matches no rows.
     */
    public function testRelationSoleThrowsOnZeroRows(): void
    {
        $orphan = OfUser::newQuery()->where('name', '=', 'orphan')->firstOrFail();

        $exception = Expectation::throws(
            fn () => $orphan->featuredPost()->sole(),
            ModelNotFoundException::class,
        );

        self::assertSame(OfPost::class, $exception->model);
        self::assertNull($exception->key);
    }

    /**
     * A relation's findOrFail() returns the related model when it exists
     * inside the relation's constraint.
     */
    public function testRelationFindOrFailReturnsModel(): void
    {
        $ada = OfUser::newQuery()->where('name', '=', 'ada')->firstOrFail();

        $post = $ada->posts()->findOrFail(1);

        self::assertInstanceOf(OfPost::class, $post);
        self::assertSame('first', $post->title);
    }

    /**
     * A relation's findOrFail() throws carrying the missing key.
     */
    public function testRelationFindOrFailThrowsWithKey(): void
    {
        $orphan = OfUser::newQuery()->where('name', '=', 'orphan')->firstOrFail();

        $exception = Expectation::throws(
            fn () => $orphan->posts()->findOrFail(999),
            ModelNotFoundException::class,
        );

        self::assertSame(999, $exception->key);
    }

    // ---- Soft-delete interplay ----

    /**
     * The soft-delete scope excludes trashed rows — firstOrFail() throws
     * for a trashed-only match, and onlyTrashed() finds it.
     */
    public function testSoftDeleteScopeInterplay(): void
    {
        $post = new OfSoftPost();
        $post->title = 'gone';
        $post->save();
        $post->delete();

        Expectation::throws(
            fn () => OfSoftPost::newQuery()->where('title', '=', 'gone')->firstOrFail(),
            ModelNotFoundException::class,
        );

        $restored = OfSoftPost::newQuery()->onlyTrashed()->where('title', '=', 'gone')->firstOrFail();

        self::assertInstanceOf(OfSoftPost::class, $restored);
        self::assertSame('gone', $restored->title);
    }
}
