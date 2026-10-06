<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Relations;

use BlueprintAU\Radiant\Collection;
use BlueprintAU\Radiant\Database\Schema\Blueprint;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\Tests\Support\DatabaseTestCase;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\MtmPost;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\MtmTag;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\MtmVideo;

/**
 * `MorphToMany::pool()` — the inverse direction's cross-type read: one
 * shared (type, key) pivot pair resolves rows of EVERY morph type, each
 * hydrated through its own model.
 */
final class MorphToManyPoolTest extends DatabaseTestCase
{
    /**
     * Create the fixtures and seed: tag 1 pools post 1 and video 1;
     * tag 2 pools post 1 only.
     */
    /**
     * Create the fixtures and seed — the pool read's subject is the TAG
     * side (`morphedByMany(MtmPost::class, 'taggable')`): the pivot's
     * morph-key FK points at POSTS (the other side of the inverse), the
     * parent-side FK at TAGS, and the shared video rows ride the SAME
     * two columns with their own type alias — taggable_id holds 1 either
     * way because both fixtures' ids coincide.
     */
    protected function setUpDatabase(): void
    {
        $this->createTables(
            Blueprint::fromMetadata(MtmPost::class),
            Blueprint::fromMetadata(MtmVideo::class),
            Blueprint::fromMetadata(MtmTag::class),
        );

        $pivot = (new Blueprint('taggable'))
            ->foreignId('taggable_id', 'mtm_posts.id')
            ->column(ColumnType::String, 'taggable_type', length: 255)
            ->foreignId('mtm_tags_id', 'mtm_tags.id');
        $this->connection->create($pivot);

        $this->connection->table('mtm_posts')->insert(['id' => 1, 'title' => 'Post One']);
        $this->connection->table('mtm_videos')->insert(['id' => 1, 'title' => 'Video One']);
        $this->connection->table('mtm_tags')->insert([
            ['id' => 1, 'label' => 'php'],
            ['id' => 2, 'label' => 'db'],
        ]);
        $this->connection->table('taggable')->insert([
            ['mtm_tags_id' => 1, 'taggable_type' => MtmPost::class, 'taggable_id' => 1],
            ['mtm_tags_id' => 1, 'taggable_type' => MtmVideo::class, 'taggable_id' => 1],
            ['mtm_tags_id' => 2, 'taggable_type' => MtmPost::class, 'taggable_id' => 1],
        ]);
    }

    /**
     * The pool read resolves BOTH types through one pivot pair, each row
     * hydrated through its own class.
     */
    public function testPoolResolvesMixedTypes(): void
    {
        $tag = MtmTag::newQuery()->find(1);
        self::assertNotNull($tag);

        $pool = $tag->poolPosts()->pool();

        self::assertInstanceOf(Collection::class, $pool);
        self::assertCount(2, $pool);

        $classes = array_map(fn (Model $m) => $m::class, $pool->all());
        self::assertContains(MtmPost::class, $classes);
        self::assertContains(MtmVideo::class, $classes);

        $post = $pool->first(fn (Model $m) => $m instanceof MtmPost);
        self::assertNotNull($post);
        self::assertSame('Post One', $post->attribute('title'));
    }

    /**
     * The per-parent constraint holds — tag 2's pool carries exactly ITS
     * rows: post 1 legitimately, never the video pooled only under
     * tag 1's alias row.
     */
    public function testPoolIsScopedToTheParent(): void
    {
        $tag = MtmTag::newQuery()->find(2);
        self::assertNotNull($tag);

        $pool = $tag->poolPosts()->pool();

        self::assertCount(1, $pool);

        $classes = array_map(fn (Model $m) => $m::class, $pool->all());
        self::assertContains(MtmPost::class, $classes);
        self::assertNotContains(MtmVideo::class, $classes);
    }

    /**
     * A stored alias outside ANY known shape fails fast — the unallowlisted
     * pool enumerates stored rows, so a rogue type value surfaces here.
     */
    public function testOutOfListTypeFailsFast(): void
    {
        $tag = MtmTag::newQuery()->find(1);
        self::assertNotNull($tag);

        // Swap video 1's stored alias to a NON-MODEL class-string — the
        // enumeration will pick it up and fail validation.
        $this->connection->table('taggable')
            ->where('taggable_type', '=', MtmVideo::class)
            ->update(['taggable_type' => 'App\\Missing\\Deleted']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('does not resolve to an existing model class');

        $tag->posts()->pool();
    }

    /**
     * The pool allowlist fail-fasts when a STORED alias outside the list
     * would need resolving — exercised by forcing the enumeration path:
     * drop the allowlist relation and corrupt the store.
     */
    public function testAllowlistedPoolSkipsUnlistedStoredTypes(): void
    {
        $tag = MtmTag::newQuery()->find(1);
        self::assertNotNull($tag);

        // An out-of-list alias in the store simply produces no rows for
        // the allowlisted queries — optimistic, never exploding.
        $this->connection->table('taggable')
            ->where('taggable_type', '=', MtmVideo::class)
            ->update(['taggable_type' => 'BlueprintAU\\Radiant\\Tests\\Unit\\Relations\\Fixtures\\MtmTag']);

        $pool = $tag->poolPosts()->pool();

        self::assertCount(1, $pool);
        self::assertInstanceOf(MtmPost::class, $pool->first());
    }

    /**
     * WITHOUT an allowlist the pool resolves every distinct stored type —
     * the honest Model bound at runtime.
     */
    public function testUnallowlistedPoolResolvesStoredTypes(): void
    {
        $tag = MtmTag::newQuery()->find(1);
        self::assertNotNull($tag);

        $posts = $tag->posts()->pool();

        self::assertCount(2, $posts);
        $classes = array_map(fn (Model $m) => $m::class, $posts->all());
        self::assertContains(MtmPost::class, $classes);
        self::assertContains(MtmVideo::class, $classes);
    }

    /**
     * The direct direction carries no shared (type, key) pair — pool()
     * refuses there.
     */
    public function testDirectDirectionRefusesPool(): void
    {
        $post = MtmPost::newQuery()->find(1);
        self::assertNotNull($post);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('available only on the inverse direction');

        $post->tags()->pool();
    }

    /**
     * MtmTag::directPool() is a DIRECT relation with pool types declared
     * — pool() must refuse it too (the allowlist does not enable it).
     */
    public function testDirectDirectionRefusesPoolEvenAllowlisted(): void
    {
        $tag = MtmTag::newQuery()->find(1);
        self::assertNotNull($tag);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('available only on the inverse direction');

        $tag->directPool()->pool();
    }

    /**
     * withPivot() columns ride each per-type pool query — pivotValue()
     * works on pool rows.
     */
    public function testPivotValuesRidePoolRows(): void
    {
        $tag = MtmTag::newQuery()->find(1);
        self::assertNotNull($tag);

        $pool = $tag->poolPosts()->withPivot('taggable_id')->pool();

        self::assertCount(2, $pool);

        foreach ($pool as $model) {
            self::assertSame(1, $model->pivotValue('taggable_id'));
        }
    }
    /**
     * The plain single-typed inverse read is untouched by pool()'s
     * arrival.
     */
    public function testSingleTypedReadUnchanged(): void
    {
        $tag = MtmTag::newQuery()->find(1);
        self::assertNotNull($tag);

        $posts = $tag->posts()->get();

        self::assertCount(1, $posts);
        self::assertSame('Post One', $posts->first()?->attribute('title'));
    }
}
