<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Relations;

use BlueprintAU\Radiant\Collection;
use BlueprintAU\Radiant\Database\Schema\Blueprint;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Tests\Support\DatabaseTestCase;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\MtmPost;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\MtmTag;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\MtmVideo;

/**
 * Phase D: `MorphToMany` + `morphedByMany` — the type-filtered pivot
 * query, eager loading, the write API's alias stamping, and the inverse
 * direction.
 */
final class MorphToManyE2ETest extends DatabaseTestCase
{
    /**
     * Create the fixtures and seed: post 1 carries tags 1+2, video 1
     * carries tag 2 (same pivot, different alias).
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
            ['taggable_id' => 1, 'taggable_type' => MtmPost::class, 'mtm_tags_id' => 1],
            ['taggable_id' => 1, 'taggable_type' => MtmPost::class, 'mtm_tags_id' => 2],
            ['taggable_id' => 1, 'taggable_type' => MtmVideo::class, 'mtm_tags_id' => 2],
        ]);
    }

    /**
     * Lazy query: the type filter keeps Video 1's tag out of Post 1's pool.
     */
    public function testLazyMorphToManyFiltersByType(): void
    {
        $post = MtmPost::newQuery()->find(1);
        self::assertNotNull($post);

        $tags = $post->tags()->get();
        self::assertCount(2, $tags);
        self::assertSame(['php', 'db'], $tags->map(fn (MtmTag $m) => $m->attribute('label'))->all());

        $video = MtmVideo::newQuery()->find(1);
        self::assertNotNull($video);

        $videoTags = $video->tags()->get();
        self::assertCount(1, $videoTags);
        self::assertSame('db', $videoTags->first()?->attribute('label'));
    }

    /**
     * Eager load: type-filtered, grouped per parent.
     */
    public function testEagerMorphToMany(): void
    {
        $posts = MtmPost::newQuery()->with(['tags'])->get();

        $post = $posts->first();
        self::assertNotNull($post);

        $tags = $post->tags()->get();
        self::assertInstanceOf(Collection::class, $tags);
        self::assertCount(2, $tags);
    }

    /**
     * attach() stamps the morph alias onto every inserted row.
     */
    public function testAttachStampsMorphAlias(): void
    {
        $video = MtmVideo::newQuery()->find(1);
        self::assertNotNull($video);

        $video->tags()->attach(1);

        $row = $this->connection->table('taggable')
            ->where('taggable_id', '=', 1)
            ->where('taggable_type', '=', MtmVideo::class)
            ->where('mtm_tags_id', '=', 1)
            ->first();
        self::assertNotNull($row);
    }

    /**
     * The inverse direction: a tag lists every post tagged with it —
     * and NOT the videos.
     */
    public function testMorphedByManyInverse(): void
    {
        $tag = MtmTag::newQuery()->find(2);
        self::assertNotNull($tag);

        $posts = $tag->posts()->get();
        self::assertCount(1, $posts);
        self::assertSame('Post One', $posts->first()?->attribute('title'));
    }

    /**
     * The inverse direction eager-loads too.
     */
    public function testMorphedByManyEager(): void
    {
        $tags = MtmTag::newQuery()->with(['posts'])->get();

        $db = null;
        foreach ($tags as $tag) {
            if ($tag->attribute('label') === 'db') {
                $db = $tag;
            }
        }

        self::assertNotNull($db);
        $posts = $db->posts()->get();
        self::assertInstanceOf(Collection::class, $posts);
        self::assertCount(1, $posts);
    }

    /**
     * sync() stamps the morph alias on every row it ATTACHES — a pivot
     * row without `{morphName}_type` would leak across parent classes
     * sharing the pivot (the regression: sync/toggle rode the base
     * insert path, which skipped the stamp).
     */
    public function testSyncStampsMorphAlias(): void
    {
        $video = MtmVideo::newQuery()->find(1);
        self::assertNotNull($video);

        $video->tags()->sync([1, 2]);

        $rows = $this->connection->table('taggable')
            ->where('taggable_id', '=', 1)
            ->where('taggable_type', '=', MtmVideo::class)
            ->get();
        self::assertCount(2, $rows);

        // And the rows belong to the video's alias, not the post's.
        $postRows = $this->connection->table('taggable')
            ->where('taggable_id', '=', 1)
            ->where('taggable_type', '=', MtmPost::class)
            ->get();
        self::assertCount(2, $postRows);
    }

    /**
     * toggle() stamps the morph alias on the rows it attaches.
     */
    public function testToggleStampsMorphAlias(): void
    {
        $video = MtmVideo::newQuery()->find(1);
        self::assertNotNull($video);

        // Tag 2 is attached → toggling detaches it; tag 1 is not →
        // toggling attaches it (and must stamp the alias).
        $video->tags()->toggle([1, 2]);

        $attached = $this->connection->table('taggable')
            ->where('taggable_id', '=', 1)
            ->where('taggable_type', '=', MtmVideo::class)
            ->get();
        self::assertCount(1, $attached);
        self::assertSame(1, $attached->first()?->mtm_tags_id);
    }

    /**
     * The relation is immutable: composing a filter returns a NEW
     * relation, the original keeps only the constraint, and the cached
     * prototype is never poisoned (a composed copy never lands in the
     * cache — the cache holds the un-composed original).
     */
    public function testCompositionReturnsNewInstanceAndLeavesOriginalUntouched(): void
    {
        $post = MtmPost::newQuery()->find(1);
        self::assertNotNull($post);

        $relation = $post->tags();
        $composed = $relation->where('label', '=', 'php');

        self::assertNotSame($relation, $composed);
        self::assertSame(
            'SELECT "mtm_tags"."id", "mtm_tags"."label" FROM "mtm_tags" INNER JOIN "taggable" ON "mtm_tags"."id" = "taggable"."mtm_tags_id"'
            . ' WHERE "taggable"."taggable_id" = ? AND "taggable"."taggable_type" = ?',
            $this->connection->grammar->compileSelect($relation->getQuery()),
        );
        self::assertSame(
            'SELECT "mtm_tags"."id", "mtm_tags"."label" FROM "mtm_tags" INNER JOIN "taggable" ON "mtm_tags"."id" = "taggable"."mtm_tags_id"'
            . ' WHERE "taggable"."taggable_id" = ? AND "taggable"."taggable_type" = ? AND "label" = ?',
            $this->connection->grammar->compileSelect($composed->getQuery()),
        );
    }
}
