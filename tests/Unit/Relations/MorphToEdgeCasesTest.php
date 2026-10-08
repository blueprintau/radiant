<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Relations;

use BlueprintAU\Radiant\Tests\Support\DatabaseTestCase;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\PolyComment;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\PolyPost;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\PolyVideo;

/**
 * The MorphTo edge paths beyond {@see PolymorphicE2ETest}: the
 * addConstraints guard, the null-FK lazy shape, the malformed eager tuple
 * guard, the unreferenced-row skip, and the composite-PK target guard.
 */
final class MorphToEdgeCasesTest extends DatabaseTestCase
{
    /**
     * Create the fixture tables and seed the shared id space.
     */
    protected function setUpDatabase(): void
    {
        $this->createTables(PolyPost::class, PolyVideo::class, PolyComment::class);

        $this->connection->table('poly_posts')->insert(['id' => 1, 'title' => 'Post One']);
        $this->connection->table('poly_videos')->insert(['id' => 1, 'title' => 'Video One']);

        $this->connection->table('poly_comments')->insert([
            ['id' => 1, 'body' => 'on post', 'commentable_type' => PolyPost::class, 'commentable_id' => 1],
            ['id' => 2, 'body' => 'on video', 'commentable_type' => PolyVideo::class, 'commentable_id' => 1],
        ]);
    }

    /**
     * addConstraints() must never run — the relation builds its
     * constraints lazily per resolved type.
     */
    public function testAddConstraintsThrows(): void
    {
        $comment = PolyComment::newQuery()->find(1);
        self::assertNotNull($comment);

        $relation = $comment->commentable();

        $method = new \ReflectionMethod($relation, 'addConstraints');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('builds its constraints lazily per resolved type');

        $method->invoke($relation);
    }

    /**
     * A null FK (with a set type) resolves empty — the type alone is not
     * a target.
     */
    public function testNullForeignKeyResolvesEmpty(): void
    {
        $this->connection->table('poly_comments')->insert([
            ['id' => 3, 'body' => 'type-only', 'commentable_type' => PolyPost::class, 'commentable_id' => null],
        ]);

        $comment = PolyComment::newQuery()->find(3);
        self::assertNotNull($comment);

        self::assertCount(0, $comment->commentable()->get());
    }

    /**
     * A non-string type value fails fast — the alias must be a model
     * class-string.
     */
    public function testNonStringTypeFailsFast(): void
    {
        $this->connection->table('poly_comments')->insert([
            ['id' => 4, 'body' => 'numeric', 'commentable_type' => '42', 'commentable_id' => 1],
        ]);

        $comment = PolyComment::newQuery()->find(4);
        self::assertNotNull($comment);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('does not resolve to an existing model class');

        $comment->commentable()->get();
    }

    /**
     * Eager loading with a malformed (type, key) tuple fails fast — the
     * loader's contract requires both halves keyed by column name.
     */
    public function testEagerLoadMalformedTupleThrows(): void
    {
        $comment = PolyComment::newQuery()->find(1);
        self::assertNotNull($comment);

        $relation = $comment->commentable();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('requires the (type, key) tuple keyed by its column names');

        $this->runInvalidEagerLoad($relation, [['commentable_type' => PolyPost::class]]);
    }

    /**
     * Eager loading with an EMPTY tuple list returns an empty result —
     * nothing to load.
     */
    public function testEagerLoadEmptyKeysReturnsEmpty(): void
    {
        $comment = PolyComment::newQuery()->find(1);
        self::assertNotNull($comment);

        $relation = $comment->commentable();

        $result = $this->runEagerLoad($relation, []);

        self::assertCount(0, $result->models);
    }

    /**
     * A row no parent references is skipped — the eager query may return
     * rows outside the wanted key set when the IN overlaps.
     */
    public function testEagerLoadSkipsUnreferencedRows(): void
    {
        // A second post the comments do NOT reference.
        $this->connection->table('poly_posts')->insert(['id' => 2, 'title' => 'Post Two']);

        $comments = PolyComment::newQuery()->whereIn('id', [1, 2])->get();

        $firstComment = $comments->first();
        self::assertNotNull($firstComment);

        $relation = $firstComment->commentable();

        // Both comments point at (PolyPost, 1) and (PolyVideo, 1) — the
        // load must NOT drag Post Two in even though its id is in range.
        $result = $this->runEagerLoad($relation, [
            ['commentable_type' => PolyPost::class, 'commentable_id' => 1],
            ['commentable_type' => PolyVideo::class, 'commentable_id' => 1],
        ]);

        self::assertCount(2, $result->models);
    }

    /**
     * match() without the EagerResult pairs throws — the models alone
     * cannot be dispatched per type.
     */
    public function testMatchWithoutPairsThrows(): void
    {
        $comment = PolyComment::newQuery()->find(1);
        self::assertNotNull($comment);

        $relation = $comment->commentable();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('requires the EagerResult (alias, key) pairs');

        $relation->match([$comment], \BlueprintAU\Radiant\Collection::make([]), 'commentable', null);
    }

    /**
     * A morph target with a COMPOSITE primary key fails fast — the
     * (type, key) pair is a scalar-key convention.
     */
    public function testCompositePkTargetThrows(): void
    {
        $comment = PolyComment::newQuery()->find(1);
        self::assertNotNull($comment);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('A morph target requires a single named primary key');

        $this->runCompositeTarget($comment);
    }

    /**
     * getTypeColumn() exposes the declared discriminator column name.
     */
    public function testGetTypeColumnReturnsDeclaredName(): void
    {
        $comment = PolyComment::newQuery()->find(1);
        self::assertNotNull($comment);

        self::assertSame('commentable_type', $comment->commentable()->getTypeColumn());
    }

    /**
     * A NON-STRING type value held in memory fails fast — the synthetic
     * attribute store bypasses the DB's string typing, so the alias guard
     * is the only line of defense.
     */
    public function testNonStringTypeValueInMemoryFailsFast(): void
    {
        $comment = new PolyComment();
        $comment->setAttribute('commentable_type', 42);
        $comment->setAttribute('commentable_id', 1);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('holds a non-string value');

        $comment->commentable()->get();
    }

    /**
     * match() drops result rows whose positional pair is missing — a
     * shorter pair list leaves the extra models undispatched.
     */
    public function testMatchSkipsResultsWithoutPairs(): void
    {
        $comment = PolyComment::newQuery()->find(1);
        self::assertNotNull($comment);

        $post = PolyPost::newQuery()->find(1);
        $video = PolyVideo::newQuery()->find(1);
        self::assertNotNull($post);
        self::assertNotNull($video);

        // Two results, ONE pair — the second row has no (alias, key) slot
        // and must be skipped, not dispatched to a guessed parent.
        $relation = $comment->commentable();
        // match() takes Collection<Model> and the item template is not
        // covariant — both fixtures ARE Models.
        /** @var \BlueprintAU\Radiant\Collection<int, \BlueprintAU\Radiant\Model> $results */
        $results = \BlueprintAU\Radiant\Collection::make([$post, $video]);
        $relation->match(
            [$comment],
            $results,
            'commentable',
            [[PolyPost::class, '1']],
        );

        $loaded = $relation->get();
        self::assertCount(1, $loaded);
        self::assertInstanceOf(PolyPost::class, $loaded->first());
    }

    /**
     * Run an eager load through the mixed-typed boundary.
     *
     * @param  \BlueprintAU\Radiant\Relations\MorphTo<\BlueprintAU\Radiant\Model>  $relation
     * @param  list<array<string, mixed>>  $keys
     * @return \BlueprintAU\Radiant\Relations\EagerResult<\BlueprintAU\Radiant\Model>
     */
    private function runEagerLoad(\BlueprintAU\Radiant\Relations\MorphTo $relation, array $keys): \BlueprintAU\Radiant\Relations\EagerResult
    {
        return $relation->eagerLoad($keys);
    }

    /**
     * Run an eager load expected to throw — the mixed-typed boundary so
     * PHPStan cannot flag the malformed tuple at the call site.
     *
     * @param  \BlueprintAU\Radiant\Relations\MorphTo<\BlueprintAU\Radiant\Model>  $relation
     * @param  list<array<string, mixed>>  $keys
     * @return void
     */
    private function runInvalidEagerLoad(\BlueprintAU\Radiant\Relations\MorphTo $relation, array $keys): void
    {
        $relation->eagerLoad($keys);
    }

    /**
     * Resolve a morphTo against a composite-PK target — the mixed-typed
     * boundary for the fail-fast path.
     *
     * @param  PolyComment  $comment
     * @return void
     */
    private function runCompositeTarget(PolyComment $comment): void
    {
        // Point the comment at the composite-PK region model — the alias
        // resolves, then the key-type guard rejects the target shape.
        $this->connection->table('poly_comments')->insert([
            ['id' => 9, 'body' => 'composite', 'commentable_type' => \BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\CmpRegion::class, 'commentable_id' => 1],
        ]);

        $row = PolyComment::newQuery()->find(9);
        self::assertNotNull($row);

        $row->commentable()->get();
    }
}
