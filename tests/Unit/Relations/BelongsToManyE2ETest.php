<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Relations;

use BlueprintAU\Radiant\Collection;
use BlueprintAU\Radiant\Database\Schema\Blueprint;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Tests\Support\DatabaseTestCase;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\B2mPost;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\B2mTag;

/**
 * Phase C: `BelongsToMany` — the joined query, eager loading with pivot
 * columns, and the full pivot write API.
 */
final class BelongsToManyE2ETest extends DatabaseTestCase
{
    /**
     * Create the fixture tables (including the pivot) and seed: post 1
     * carries tags 1+2, post 2 carries tag 2.
     */
    protected function setUpDatabase(): void
    {
        $this->createTables(
            Blueprint::fromMetadata(B2mPost::class),
            Blueprint::fromMetadata(B2mTag::class),
        );

        $pivot = (new Blueprint('b2m_posts_b2m_tags'))
            ->foreignId('b2m_posts_id', 'b2m_posts.id')
            ->foreignId('b2m_tags_id', 'b2m_tags.id')
            ->column(ColumnType::String, 'position', nullable: true, length: 16)
            ->column(ColumnType::DateTime, 'created_at', nullable: true)
            ->column(ColumnType::DateTime, 'updated_at', nullable: true);
        $this->connection->create($pivot);

        $this->connection->table('b2m_posts')->insert([
            ['id' => 1, 'title' => 'Post One'],
            ['id' => 2, 'title' => 'Post Two'],
        ]);
        $this->connection->table('b2m_tags')->insert([
            ['id' => 1, 'label' => 'php'],
            ['id' => 2, 'label' => 'db'],
            ['id' => 3, 'label' => 'orm'],
        ]);
        $this->connection->table('b2m_posts_b2m_tags')->insert([
            ['b2m_posts_id' => 1, 'b2m_tags_id' => 1, 'position' => 'first'],
            ['b2m_posts_id' => 1, 'b2m_tags_id' => 2, 'position' => 'second'],
            ['b2m_posts_id' => 2, 'b2m_tags_id' => 2, 'position' => null],
        ]);
    }

    /**
     * Lazy query: the join returns only the parent's tags.
     */
    public function testLazyBelongsToMany(): void
    {
        $post = B2mPost::newQuery()->find(1);
        self::assertNotNull($post);

        $tags = $post->tags()->getResults();
        self::assertCount(2, $tags);
        self::assertSame(['php', 'db'], $tags->map(fn (B2mTag $m) => $m->attribute('label'))->all());
    }

    /**
     * Eager load: one joined query, per-row parent keys, grouped per
     * parent — post 2 gets ONLY its own tag.
     */
    public function testEagerBelongsToMany(): void
    {
        $posts = B2mPost::newQuery()->with(['tags'])->get();

        self::assertCount(2, $posts);

        $first = $posts->first();
        self::assertNotNull($first);
        $tags1 = $first->tags()->getResults();
        self::assertInstanceOf(Collection::class, $tags1);
        self::assertCount(2, $tags1);

        $second = $posts->last();
        self::assertNotNull($second);
        $tags2 = $second->tags()->getResults();
        self::assertInstanceOf(Collection::class, $tags2);
        self::assertCount(1, $tags2);
        self::assertSame('db', $tags2->first()?->attribute('label'));
    }

    /**
     * withPivot carries the pivot columns onto the related models.
     */
    public function testWithPivotCarriesPivotValues(): void
    {
        $post = B2mPost::newQuery()->find(1);
        self::assertNotNull($post);

        $tags = $post->tags()->withPivot('position')->getResults();
        self::assertCount(2, $tags);
        self::assertSame('first', $tags->first()?->pivotValue('position'));
        self::assertSame('second', $tags->last()?->pivotValue('position'));
    }

    /**
     * A pivot column starting with the reserved `radiant_` prefix fails
     * fast at the withPivot() call — the internal `radiant_pivot_{column}`
     * alias would collide with the reserved namespace the row lift treats
     * as internal state, silently hijacking the pivot value slot.
     */
    public function testWithPivotRejectsReservedPrefix(): void
    {
        $post = B2mPost::newQuery()->find(1);
        self::assertNotNull($post);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('starts with the reserved prefix [radiant_]');

        $post->tags()->withPivot('radiant_position');
    }

    /**
     * attach() inserts pivot rows — single id, list, and attribute map.
     * (The seed already gave post 2 one row for tag 2.)
     */
    public function testAttachInsertsPivotRows(): void
    {
        $post = B2mPost::newQuery()->find(2);
        self::assertNotNull($post);

        $post->tags()->attach(3);
        $post->tags()->attach([1, 2]);
        $post->tags()->attach(1, ['position' => 'pinned']);

        $rows = $this->connection->table('b2m_posts_b2m_tags')
            ->where('b2m_posts_id', '=', 2)->get();

        // Seeded 1 + attach(3) + attach([1,2]) + attach(1, attrs) = 5 rows.
        self::assertCount(5, $rows);

        $pinned = $this->connection->table('b2m_posts_b2m_tags')
            ->where('b2m_posts_id', '=', 2)
            ->where('b2m_tags_id', '=', 1)
            ->where('position', '=', 'pinned')
            ->first();
        self::assertNotNull($pinned);
    }

    /**
     * detach() removes pivot rows — scoped ids and all.
     */
    public function testDetachRemovesPivotRows(): void
    {
        $post = B2mPost::newQuery()->find(1);
        self::assertNotNull($post);

        $detached = $post->tags()->detach(1);
        self::assertSame(1, $detached);

        $remaining = $post->tags()->getResults();
        self::assertCount(1, $remaining);
        self::assertSame('db', $remaining->first()?->attribute('label'));

        // Detach ALL of post 2's rows.
        $post2 = B2mPost::newQuery()->find(2);
        self::assertNotNull($post2);
        self::assertSame(1, $post2->tags()->detach());
        self::assertCount(0, $post2->tags()->getResults());
    }

    /**
     * sync() computes the exact diff — attach the missing, detach the
     * extra — inside a transaction.
     */
    public function testSyncComputesExactDiff(): void
    {
        $post = B2mPost::newQuery()->find(1);
        self::assertNotNull($post);

        // Current: [1, 2]. Desired: [2, 3] → attach 3, detach 1.
        $diff = $post->tags()->sync([2, 3]);

        self::assertSame(['attached' => [3], 'detached' => [1], 'updated' => []], $diff);

        $labels = $post->tags()->getResults()
            ->map(fn (B2mTag $m) => $m->attribute('label'))->all();
        self::assertSame(['db', 'orm'], $labels);
    }

    /**
     * sync() with pivot attributes updates the shared rows in place.
     */
    public function testSyncUpdatesSharedRows(): void
    {
        $post = B2mPost::newQuery()->find(1);
        self::assertNotNull($post);

        $diff = $post->tags()->sync([1 => ['position' => 'promoted'], 2]);

        self::assertSame(['attached' => [], 'detached' => [], 'updated' => [1]], $diff);

        $row = $this->connection->table('b2m_posts_b2m_tags')
            ->where('b2m_posts_id', '=', 1)
            ->where('b2m_tags_id', '=', 1)
            ->first();
        self::assertNotNull($row);
        self::assertSame('promoted', $row->position);
    }

    /**
     * syncWithoutDetaching() attaches the missing and keeps the rest.
     */
    public function testSyncWithoutDetachingKeepsExtraRows(): void
    {
        $post = B2mPost::newQuery()->find(1);
        self::assertNotNull($post);

        $diff = $post->tags()->syncWithoutDetaching([3]);

        self::assertSame(['attached' => [3], 'detached' => [], 'updated' => []], $diff);
        self::assertCount(3, $post->tags()->getResults());
    }

    /**
     * toggle() flips each id — attached ones detach, missing ones attach.
     */
    public function testToggleFlipsEachId(): void
    {
        $post = B2mPost::newQuery()->find(1);
        self::assertNotNull($post);

        // Current: [1, 2]. Toggle [2, 3] → detach 2, attach 3.
        $diff = $post->tags()->toggle([2, 3]);

        self::assertSame(['attached' => [3], 'detached' => [2]], $diff);

        $labels = $post->tags()->getResults()
            ->map(fn (B2mTag $m) => $m->attribute('label'))->all();
        self::assertSame(['php', 'orm'], $labels);
    }

    /**
     * A parent with no pivot rows loads an EMPTY collection — the relation
     * is loaded either way.
     */
    public function testEmptyPivotLoadsEmptyCollection(): void
    {
        $this->connection->table('b2m_posts')->insert(['id' => 3, 'title' => 'Post Three']);

        $post = B2mPost::newQuery()->with(['tags'])->find(3);
        self::assertNotNull($post);

        $tags = $post->tags()->getResults();
        self::assertInstanceOf(Collection::class, $tags);
        self::assertCount(0, $tags);
    }

    /**
     * withTimestamps() is sugar for withPivot('created_at', 'updated_at')
     * — the pivot pair rides the select and reads through pivotValue().
     */
    public function testWithTimestampsCarriesPivotTimestamps(): void
    {
        $this->connection->table('b2m_posts_b2m_tags')->insert([
            ['b2m_posts_id' => 2, 'b2m_tags_id' => 3, 'position' => null, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-02 00:00:00'],
        ]);

        $post = B2mPost::newQuery()->find(2);
        self::assertNotNull($post);

        $tags = $post->tags()->withTimestamps()->getResults();
        self::assertCount(2, $tags);

        $orm = null;
        foreach ($tags as $tag) {
            if ($tag->attribute('label') === 'orm') {
                $orm = $tag;
            }
        }

        self::assertNotNull($orm);
        self::assertSame('2026-01-01 00:00:00', $orm->pivotValue('created_at'));
        self::assertSame('2026-01-02 00:00:00', $orm->pivotValue('updated_at'));
    }

    /**
     * The relation is immutable: withPivot() returns a NEW relation, the
     * original stays pivot-free, and a discarded call is a no-op.
     */
    public function testWithPivotReturnsNewInstanceAndLeavesOriginalUntouched(): void
    {
        $post = B2mPost::newQuery()->find(1);
        self::assertNotNull($post);

        $relation = $post->tags();
        $withPivot = $relation->withPivot('position');

        self::assertNotSame($relation, $withPivot);

        // The original's lazy read selects no pivot alias.
        $rows = $relation->getResults();
        self::assertCount(2, $rows);
        self::assertNull($rows->first()?->pivotValue('position'));

        // The composed copy carries the pivot columns.
        $pivoted = $withPivot->getResults();
        self::assertCount(2, $pivoted);
        self::assertSame('first', $pivoted->first()?->pivotValue('position'));
    }

    /**
     * withName(null) is the documented no-op — the SAME instance comes
     * back; withName('x') returns a NEW instance with the stamp.
     */
    public function testWithNameNullIsNoOpAndNameReturnsNewInstance(): void
    {
        $post = B2mPost::newQuery()->find(1);
        self::assertNotNull($post);

        $relation = $post->tags();

        self::assertSame($relation, $relation->withName(null));
        self::assertNotSame($relation, $relation->withName('tags'));
    }
}
