<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Relations;

use BlueprintAU\Radiant\Tests\Support\DatabaseTestCase;
use BlueprintAU\Radiant\Tests\Support\Expectation;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\PolyComment;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\PolyPost;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\PolyVideo;

/**
 * The morph-pair READ guard: a HALF-NULL (type, key) pair stored in the
 * database throws when a MorphTo resolves it, instead of silently reading
 * as "no parent". Both-null and both-set behavior is unchanged.
 *
 * These rows bypass the write guard (seeded through the connection's raw
 * table builder) — exactly the DB-side corruption the read guard exists
 * to catch.
 */
final class MorphPairReadGuardTest extends DatabaseTestCase
{
    /**
     * Create the fixture tables and seed the shared id space.
     */
    protected function setUpDatabase(): void
    {
        $this->createTables(PolyPost::class, PolyVideo::class, PolyComment::class);

        $this->connection->table('poly_posts')->insert(['id' => 1, 'title' => 'Post One']);
        $this->connection->table('poly_videos')->insert(['id' => 1, 'title' => 'Video One']);
    }

    /**
     * A half-null row (type set, key null) throws on lazy MorphTo read.
     */
    public function testLazyReadHalfNullTypeThrows(): void
    {
        $this->connection->table('poly_comments')->insert([
            ['id' => 3, 'body' => 'type-only', 'commentable_type' => PolyPost::class, 'commentable_id' => null],
        ]);

        $comment = PolyComment::newQuery()->find(3);
        self::assertNotNull($comment);

        $exception = Expectation::throws(
            fn() => $comment->commentable()->get(),
            \InvalidArgumentException::class,
        );

        self::assertStringContainsString('half-null', $exception->getMessage());
        self::assertStringContainsString('[commentable_type, commentable_id]', $exception->getMessage());
    }

    /**
     * A half-null row (key set, type null) throws on lazy MorphTo read.
     */
    public function testLazyReadHalfNullKeyThrows(): void
    {
        $this->connection->table('poly_comments')->insert([
            ['id' => 3, 'body' => 'key-only', 'commentable_type' => null, 'commentable_id' => 1],
        ]);

        $comment = PolyComment::newQuery()->find(3);
        self::assertNotNull($comment);

        $exception = Expectation::throws(
            fn() => $comment->commentable()->get(),
            \InvalidArgumentException::class,
        );

        self::assertStringContainsString('half-null', $exception->getMessage());
        self::assertStringContainsString('[commentable_type, commentable_id]', $exception->getMessage());
    }

    /**
     * A half-null row throws on the eager-load distribution path too.
     */
    public function testEagerReadHalfNullThrows(): void
    {
        $this->connection->table('poly_comments')->insert([
            ['id' => 3, 'body' => 'key-only', 'commentable_type' => null, 'commentable_id' => 1],
        ]);

        $comment = PolyComment::newQuery()->find(3);
        self::assertNotNull($comment);

        $exception = Expectation::throws(
            fn() => PolyComment::newQuery()->whereIn('id', [3])->with('commentable')->get(),
            \InvalidArgumentException::class,
        );

        self::assertStringContainsString('half-null', $exception->getMessage());
        self::assertStringContainsString('[commentable_type, commentable_id]', $exception->getMessage());
    }

    /**
     * A BOTH-null pair still reads empty — the optional morph target is
     * unchanged.
     */
    public function testLazyReadBothNullStillEmpty(): void
    {
        $this->connection->table('poly_comments')->insert([
            ['id' => 3, 'body' => 'detached', 'commentable_type' => null, 'commentable_id' => null],
        ]);

        $comment = PolyComment::newQuery()->find(3);
        self::assertNotNull($comment);

        self::assertCount(0, $comment->commentable()->get());
    }

    /**
     * A both-set pair still resolves — unchanged.
     */
    public function testLazyReadBothSetStillResolves(): void
    {
        $this->connection->table('poly_comments')->insert([
            ['id' => 3, 'body' => 'on post', 'commentable_type' => PolyPost::class, 'commentable_id' => 1],
        ]);

        $comment = PolyComment::newQuery()->find(3);
        self::assertNotNull($comment);

        $parent = $comment->commentable()->get()->first();
        self::assertInstanceOf(PolyPost::class, $parent);
    }

    /**
     * A half-null VIDEO reference throws through the same guard — the
     * check is per-row, not per-alias.
     */
    public function testLazyReadHalfNullOnSecondTypeThrows(): void
    {
        $this->connection->table('poly_comments')->insert([
            ['id' => 3, 'body' => 'video-ish', 'commentable_type' => PolyVideo::class, 'commentable_id' => null],
        ]);

        $comment = PolyComment::newQuery()->find(3);
        self::assertNotNull($comment);

        $exception = Expectation::throws(
            fn() => $comment->commentable()->get(),
            \InvalidArgumentException::class,
        );

        self::assertStringContainsString('half-null', $exception->getMessage());
        self::assertStringContainsString('[commentable_type, commentable_id]', $exception->getMessage());
    }
}
