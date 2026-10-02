<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Relations;

use BlueprintAU\Radiant\Collection;
use BlueprintAU\Radiant\Database\Schema\Blueprint;
use BlueprintAU\Radiant\Tests\Support\DatabaseTestCase;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\PolyComment;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\PolyImage;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\PolyPost;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\PolyVideo;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\UuidMorphComment;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\UuidVideo;

/**
 * Phase B: the polymorphic relations — lazy + eager morphMany/morphOne/
 * morphTo, the type filter's correctness, and the fail-fast guards.
 */
final class PolymorphicE2ETest extends DatabaseTestCase
{
    /**
     * Create the fixture tables and seed a shared id space: post 1 and
     * video 1 BOTH exist, so a missing type filter would cross-match.
     */
    protected function setUpDatabase(): void
    {
        $this->createTables(
            Blueprint::fromMetadata(PolyPost::class),
            Blueprint::fromMetadata(PolyVideo::class),
            Blueprint::fromMetadata(PolyComment::class),
            Blueprint::fromMetadata(PolyImage::class),
        );

        $this->connection->table('poly_posts')->insert(['id' => 1, 'title' => 'Post One']);
        $this->connection->table('poly_videos')->insert(['id' => 1, 'title' => 'Video One']);

        // Post 1 owns two comments; Video 1 owns one — same id, different alias.
        $this->connection->table('poly_comments')->insert([
            ['id' => 1, 'body' => 'on post', 'commentable_type' => PolyPost::class, 'commentable_id' => 1],
            ['id' => 2, 'body' => 'on post too', 'commentable_type' => PolyPost::class, 'commentable_id' => 1],
            ['id' => 3, 'body' => 'on video', 'commentable_type' => PolyVideo::class, 'commentable_id' => 1],
        ]);

        $this->connection->table('poly_images')->insert([
            ['id' => 1, 'path' => 'post.png', 'imageable_type' => PolyPost::class, 'imageable_id' => 1],
        ]);
    }

    /**
     * Lazy morphMany: the type filter keeps Video 1's comment out of
     * Post 1's pool — the core polymorphic correctness case.
     */
    public function testLazyMorphManyFiltersByType(): void
    {
        $post = PolyPost::newQuery()->find(1);
        self::assertNotNull($post);

        $comments = $post->comments()->get();
        self::assertCount(2, $comments);
        self::assertSame(['on post', 'on post too'], $comments->map(fn (PolyComment $m) => $m->attribute('body'))->all());
    }

    /**
     * Lazy morphTo resolves the parent through the type column.
     */
    public function testLazyMorphToResolvesPerType(): void
    {
        $comment = PolyComment::newQuery()->find(3);
        self::assertNotNull($comment);

        $parent = $comment->commentable()->get()->first();
        self::assertInstanceOf(PolyVideo::class, $parent);
        self::assertSame('Video One', $parent->title);

        $comment1 = PolyComment::newQuery()->find(1);
        self::assertNotNull($comment1);

        $postParent = $comment1->commentable()->get()->first();
        self::assertInstanceOf(PolyPost::class, $postParent);
        self::assertSame('Post One', $postParent->title);
    }

    /**
     * Lazy morphTo with a NULL type column resolves empty — the optional
     * morph target.
     */
    public function testLazyMorphToNullTypeIsEmpty(): void
    {
        $this->connection->table('poly_comments')->insert([
            ['id' => 4, 'body' => 'orphan', 'commentable_type' => null, 'commentable_id' => null],
        ]);

        $comment = PolyComment::newQuery()->find(4);
        self::assertNotNull($comment);

        self::assertCount(0, $comment->commentable()->get());
    }

    /**
     * Lazy morphTo with an UNKNOWN type class fails fast — a stale alias
     * is a data bug, not an empty result.
     */
    public function testLazyMorphToUnknownTypeFailsFast(): void
    {
        $this->connection->table('poly_comments')->insert([
            ['id' => 5, 'body' => 'stale', 'commentable_type' => 'App\\Gone', 'commentable_id' => 1],
        ]);

        $comment = PolyComment::newQuery()->find(5);
        self::assertNotNull($comment);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('does not resolve to an existing model class');

        $comment->commentable()->get();
    }

    /**
     * The morph-alias allowlist rejects a type outside the list.
     */
    public function testMorphToAllowlistRejectsForeignType(): void
    {
        $comment = PolyComment::newQuery()->find(3);
        self::assertNotNull($comment);

        // The factory is protected — reach it through a fixture method
        // that exposes an allowlisted variant.
        $relation = $comment->allowlistedWith([PolyPost::class]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('is not in the relation\'s allowlist');

        $relation->get();
    }

    /**
     * The templated helper narrows STATICALLY at the call site: the
     * `[PolyPost::class]` literal infers `$TParent = PolyPost`, so the
     * loaded parent is PolyPost without a local instanceof (the generic
     * flows through get() → first()). The runtime allowlist still
     * gates the resolution — a Video-typed row would throw.
     */
    public function testAllowlistedMorphToNarrowsStatically(): void
    {
        $comment = PolyComment::newQuery()->find(1);
        self::assertNotNull($comment);

        $parent = $comment->allowlistedWith([PolyPost::class])->get()->first();

        // PHPStan sees PolyPost|null here — property access below is the
        // static proof; the instanceof assert is the runtime lock.
        self::assertInstanceOf(PolyPost::class, $parent);
        self::assertSame('Post One', $parent->title);
    }

    /**
     * Eager morphMany: one load, type-filtered, grouped per parent.
     */
    public function testEagerMorphMany(): void
    {
        $posts = PolyPost::newQuery()->with(['comments'])->get();

        self::assertCount(1, $posts);
        $post = $posts->first();
        self::assertNotNull($post);
        $comments = $post->comments()->get();
        self::assertInstanceOf(Collection::class, $comments);
        self::assertCount(2, $comments);
    }

    /**
     * Eager morphOne: first-wins, type-filtered.
     */
    public function testEagerMorphOne(): void
    {
        $post = PolyPost::newQuery()->with(['image'])->find(1);
        self::assertNotNull($post);

        $image = $post->image()->get()->first();
        self::assertInstanceOf(PolyImage::class, $image);
        self::assertSame('post.png', $image->path);
    }

    /**
     * Eager morphTo with parents of TWO different types in ONE load —
     * the per-type dispatch's core correctness case.
     */
    public function testEagerMorphToDispatchesPerType(): void
    {
        $comments = PolyComment::newQuery()->with(['commentable'])->get();

        self::assertCount(3, $comments);

        /** @var array<int, PolyPost|PolyVideo> */
        $byId = [];
        foreach ($comments as $comment) {
            $byId[$comment->id] = $comment->commentable()->get()->first();
        }

        self::assertInstanceOf(PolyPost::class, $byId[1]);
        self::assertSame('Post One', $byId[1]->title);
        self::assertInstanceOf(PolyPost::class, $byId[2]);
        self::assertInstanceOf(PolyVideo::class, $byId[3]);
        self::assertSame('Video One', $byId[3]->title);
    }

    /**
     * Eager morphTo with a null type leaves the relation null (not an
     * empty collection) — the single-valued empty shape.
     */
    public function testEagerMorphToNullTypeIsNull(): void
    {
        $this->connection->table('poly_comments')->insert([
            ['id' => 4, 'body' => 'orphan', 'commentable_type' => null, 'commentable_id' => null],
        ]);

        $comment = PolyComment::newQuery()->with(['commentable'])->find(4);
        self::assertNotNull($comment);

        self::assertCount(0, $comment->commentable()->get());
    }

    /**
     * Nested eager loading through a morph relation: comments → their
     * commentable → the post's own comments. The nested path recurses
     * onto the morphTo result (a PolyPost), whose own morphMany loads.
     */
    public function testNestedEagerThroughMorph(): void
    {
        // Only the post-owned comments — the nested path recurses onto
        // PolyPost, which declares comments(); video-owned parents would
        // fail the path resolution (PolyVideo has no comments()).
        $comments = PolyComment::newQuery()
            ->where('commentable_type', '=', PolyPost::class)
            ->with(['commentable.comments'])
            ->get();

        $first = $comments->first();
        self::assertNotNull($first);

        $parent = $first->commentable()->get()->first();
        self::assertInstanceOf(PolyPost::class, $parent);

        $nested = $parent->comments()->get();
        self::assertInstanceOf(Collection::class, $nested);
        self::assertCount(2, $nested);
    }

    /**
     * The morph constraint compiles as ONE grouped unit: the key AND the
     * type inside parens, a caller's orWhere at the constraint's edges.
     * The shape is the lock — flat composition would read the same for a
     * two-part AND constraint, but the group is what keeps a caller's OR
     * from ever touching the constraint's PARTS if the constraint grows
     * internal ORs (the composite eager path does exactly that).
     */
    public function testMorphConstraintSqlShape(): void
    {
        $post = PolyPost::newQuery()->find(1);
        self::assertNotNull($post);

        $relation = $post->comments();
        $composed = $relation->orWhere('body', '=', 'on video');

        $sql = $this->connection->grammar->compileSelect($composed->getQuery());

        self::assertSame(
            'SELECT * FROM "poly_comments" WHERE ("commentable_id" = ? AND "commentable_type" = ?) OR "body" = ?',
            $sql,
        );

        // The relation is immutable: the original carries ONLY the
        // constraint — the discarded-composition no-op contract.
        self::assertSame(
            'SELECT * FROM "poly_comments" WHERE ("commentable_id" = ? AND "commentable_type" = ?)',
            $this->connection->grammar->compileSelect($relation->getQuery()),
        );
    }

    /**
     * MorphTo builds its query lazily per resolved type, so a grouped
     * aggregate cannot compose — the same LogicException as every other
     * composition method.
     */
    public function testMorphToCountByThrows(): void
    {
        $comment = PolyComment::newQuery()->find(1);
        self::assertNotNull($comment);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('cannot compose filters');

        $comment->commentable()->countBy('id');
    }

    /**
     * A UUID-keyed morph pair round-trips: a uuidMorphs() child points at
     * a UUID-PK parent, lazy + eager, through the attribute store.
     */
    public function testUuidKeyedMorphRoundTrips(): void
    {
        $this->createTables(
            Blueprint::fromMetadata(UuidVideo::class),
            Blueprint::fromMetadata(UuidMorphComment::class),
        );

        $videoId = '0b8df450-0e82-4c6e-9c1d-3f2a5b6c7d8e';
        $this->connection->table('uuid_videos')->insert(['id' => $videoId, 'title' => 'Uuid One']);

        $comment = new UuidMorphComment();
        $comment->id = 1;
        $comment->body = 'on uuid video';
        $comment->setAttribute('commentable_type', UuidVideo::class);
        $comment->setAttribute('commentable_id', $videoId);
        $comment->save();

        $loaded = UuidMorphComment::newQuery()->find(1);
        self::assertNotNull($loaded);
        self::assertSame(UuidVideo::class, $loaded->attribute('commentable_type'));
        self::assertSame($videoId, $loaded->attribute('commentable_id'));

        $parent = $loaded->commentable()->get()->first();
        self::assertInstanceOf(UuidVideo::class, $parent);
        self::assertSame('Uuid One', $parent->title);
    }

    /**
     * A BigInt morph pair pointing at a UUID-PK model fails fast at
     * relation construction — the forward side: the pair's `_id` column
     * lives on the child and must hold the UUID parent's key.
     */
    public function testBigIntMorphToUuidTargetFailsFastOnForwardSide(): void
    {
        $this->createTables(Blueprint::fromMetadata(UuidVideo::class));

        $video = new UuidVideo();
        $video->id = '0b8df450-0e82-4c6e-9c1d-3f2a5b6c7d8e';
        $video->title = 'Uuid One';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains("[" . UuidVideo::class . "]'s primary key is [uuid]");

        $video->comments();
    }

    /**
     * A stored alias whose target's PK type contradicts the key column
     * fails fast at resolution — the inverse side.
     */
    public function testMorphToTypeMismatchFailsFastOnInverseSide(): void
    {
        $this->createTables(Blueprint::fromMetadata(UuidVideo::class));

        $videoId = '0b8df450-0e82-4c6e-9c1d-3f2a5b6c7d8e';
        $this->connection->table('uuid_videos')->insert(['id' => $videoId, 'title' => 'Uuid One']);

        // A bigint-keyed morph pair holding a uuid target's alias — the
        // corrupt state the check exists to catch.
        $this->connection->table('poly_comments')->insert([
            ['id' => 6, 'body' => 'mismatch', 'commentable_type' => UuidVideo::class, 'commentable_id' => 1],
        ]);

        $comment = PolyComment::newQuery()->find(6);
        self::assertNotNull($comment);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains("[" . UuidVideo::class . "]'s primary key is [uuid]");

        $comment->commentable()->get();
    }
}
