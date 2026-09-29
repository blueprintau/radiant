<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Model;

use BlueprintAU\Radiant\Tests\Support\DatabaseTestCase;
use BlueprintAU\Radiant\Tests\Unit\Model\Fixtures\RbImmutableSoftPost;
use BlueprintAU\Radiant\Tests\Unit\Model\Fixtures\RbImmutableStampedPost;
use Carbon\CarbonImmutable;

/**
 * The Carbon → CarbonImmutable re-base branch: a model whose stamp or
 * delete column is DECLARED as a CarbonImmutable property receives the
 * trait's Carbon stamp re-based through
 * `DateTimeImmutable::createFromInterface()` — the typed property never
 * sees a foreign type (a TypeError would otherwise kill every save).
 */
final class TimestampRebaseTest extends DatabaseTestCase
{
    /**
     * Create the fixture tables from metadata — the declared
     * CarbonImmutable columns become real datetime columns.
     */
    protected function setUpDatabase(): void
    {
        $this->createTables(RbImmutableStampedPost::class, RbImmutableSoftPost::class);
    }

    /**
     * Timestamps: an INSERT stamps BOTH declared CarbonImmutable columns —
     * the re-base converts the trait's Carbon to the property's type.
     */
    public function testInsertStampsImmutableColumns(): void
    {
        $post = new RbImmutableStampedPost();
        $post->title = 'Immutable';
        $post->save();

        self::assertInstanceOf(CarbonImmutable::class, $post->began_at);
        self::assertInstanceOf(CarbonImmutable::class, $post->modified_at);

        $raw = $this->connection->table('rb_immutable_stamped_posts')
            ->where('id', '=', $post->id)->first();
        self::assertNotNull($raw);
        self::assertNotNull($raw->began_at);
        self::assertNotNull($raw->modified_at);
    }

    /**
     * Timestamps: an UPDATE re-stamps the immutable updated-at column —
     * the re-base runs on the update path too.
     */
    public function testUpdateRestampsImmutableColumn(): void
    {
        $post = new RbImmutableStampedPost();
        $post->title = 'Immutable';
        $post->save();

        $firstStamp = $post->modified_at;
        self::assertInstanceOf(CarbonImmutable::class, $firstStamp);

        sleep(1);

        $post->title = 'Immutable-edited';
        $post->save();

        self::assertInstanceOf(CarbonImmutable::class, $post->modified_at);
        self::assertTrue(
            $post->modified_at->greaterThan($firstStamp),
            'the immutable updated-at stamp must bump on update',
        );
    }

    /**
     * A caller-set CarbonImmutable (the property's own type) is never
     * touched by the stamper — the caller's instant survives verbatim.
     * (A foreign-typed value cannot even reach the property: PHP enforces
     * the declared type on every assignment, so the re-base branch exists
     * solely for the trait's own Carbon stamps.)
     */
    public function testCallerSetImmutableStampWins(): void
    {
        $post = new RbImmutableStampedPost();
        $post->title = 'Backdated';
        $post->began_at = CarbonImmutable::parse('2020-06-01 12:00:00', 'UTC');
        $post->save();

        self::assertInstanceOf(CarbonImmutable::class, $post->began_at);
        self::assertSame('2020-06-01 12:00:00', $post->began_at->format('Y-m-d H:i:s'));

        $raw = $this->connection->table('rb_immutable_stamped_posts')
            ->where('id', '=', $post->id)->first();
        self::assertNotNull($raw);
        self::assertSame('2020-06-01 12:00:00', substr((string) $raw->began_at, 0, 19));
    }

    /**
     * SoftDeletes: delete() writes the Carbon stamp into the declared
     * CarbonImmutable property via the re-base — and trashed() flips.
     */
    public function testDeleteWritesImmutableStamp(): void
    {
        $post = new RbImmutableSoftPost();
        $post->title = 'Soft';
        $post->save();

        self::assertFalse($post->trashed());

        self::assertTrue($post->delete());

        self::assertInstanceOf(CarbonImmutable::class, $post->removed_at);
        self::assertTrue($post->trashed());

        $raw = $this->connection->table('rb_immutable_soft_posts')
            ->where('id', '=', $post->id)->first();
        self::assertNotNull($raw);
        self::assertNotNull($raw->removed_at);
    }

    /**
     * SoftDeletes: restore() clears the immutable delete column back to
     * null — the null arm of the write path.
     */
    public function testRestoreClearsImmutableStamp(): void
    {
        $post = new RbImmutableSoftPost();
        $post->title = 'Soft';
        $post->save();
        $post->delete();

        self::assertTrue($post->restore());

        self::assertNull($post->removed_at);
        self::assertFalse($post->trashed());

        $raw = $this->connection->table('rb_immutable_soft_posts')
            ->where('id', '=', $post->id)->first();
        self::assertNotNull($raw);
        self::assertNull($raw->removed_at);
    }

    /**
     * The re-based stamp round-trips a reload — the hydrated value is the
     * declared CarbonImmutable type, not the trait's Carbon.
     */
    public function testReloadedModelHydratesImmutableType(): void
    {
        $post = new RbImmutableStampedPost();
        $post->title = 'Round trip';
        $post->save();

        $loaded = RbImmutableStampedPost::newQuery()->find($post->id);
        self::assertNotNull($loaded);

        self::assertInstanceOf(CarbonImmutable::class, $loaded->began_at);
        self::assertInstanceOf(CarbonImmutable::class, $loaded->modified_at);
    }
}
