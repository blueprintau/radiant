<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Relations;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Attributes\Table;
use BlueprintAU\Radiant\Collection;
use BlueprintAU\Radiant\Database\Schema\Blueprint;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\Tests\Support\DatabaseTestCase;

/**
 * A polymorphic many-to-many parent — posts tag through taggables.
 */
#[Table(name: 'mtm_posts')]
class MtmPost extends Model
{
    /**
     * The primary key.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * A plain column.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 64)]
    public string $title;

    /**
     * The polymorphic many-to-many relation.
     *
     * @return \BlueprintAU\Radiant\Relations\MorphToMany<MtmTag>
     */
    public function tags(): \BlueprintAU\Radiant\Relations\MorphToMany
    {
        return $this->morphToMany(MtmTag::class, 'taggable');
    }
}

/**
 * A SECOND polymorphic many-to-many parent class — same pivot, same pool.
 */
#[Table(name: 'mtm_videos')]
class MtmVideo extends Model
{
    /**
     * The primary key.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * A plain column.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 64)]
    public string $title;

    /**
     * The polymorphic many-to-many relation.
     *
     * @return \BlueprintAU\Radiant\Relations\MorphToMany<MtmTag>
     */
    public function tags(): \BlueprintAU\Radiant\Relations\MorphToMany
    {
        return $this->morphToMany(MtmTag::class, 'taggable');
    }
}

/**
 * The shared related model — and the INVERSE direction's parent.
 */
#[Table(name: 'mtm_tags')]
class MtmTag extends Model
{
    /**
     * The primary key.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * A plain column.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 64)]
    public string $label;

    /**
     * The INVERSE polymorphic many-to-many relation — every post tagged
     * with this tag.
     *
     * @return \BlueprintAU\Radiant\Relations\MorphToMany<MtmPost>
     */
    public function posts(): \BlueprintAU\Radiant\Relations\MorphToMany
    {
        return $this->morphedByMany(MtmPost::class, 'taggable');
    }
}

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

        $pivot = new Blueprint('taggable');
        $pivot->foreignId('taggable_id', 'mtm_posts.id');
        $pivot->column(ColumnType::String, 'taggable_type', length: 255);
        $pivot->foreignId('mtm_tags_id', 'mtm_tags.id');
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

        $tags = $post->tags()->getResults();
        self::assertCount(2, $tags);
        self::assertSame(['php', 'db'], $tags->map(fn (Model $m) => $m->attribute('label'))->all());

        $video = MtmVideo::newQuery()->find(1);
        self::assertNotNull($video);

        $videoTags = $video->tags()->getResults();
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

        $tags = $post->tags()->getResults();
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

        $posts = $tag->posts()->getResults();
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
        $posts = $db->posts()->getResults();
        self::assertInstanceOf(Collection::class, $posts);
        self::assertCount(1, $posts);
    }
}
