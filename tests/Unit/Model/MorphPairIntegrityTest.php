<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Model;

use BlueprintAU\Radiant\Tests\Support\DatabaseTestCase;
use BlueprintAU\Radiant\Tests\Support\Expectation;
use BlueprintAU\Radiant\Tests\Support\ModelIntrospection;
use BlueprintAU\Radiant\Tests\Unit\Model\Fixtures\MorphPairMedia;
use BlueprintAU\Radiant\Tests\Unit\Model\Fixtures\MorphPairTypedMedia;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\PolyPost;

/**
 * The morph-pair integrity guard: a flush (insert or update) that would
 * land a HALF-SET `#[Morphs]` pair — one column valued, its sibling null
 * — throws instead of persisting an unresolvable reference. Fully-null
 * and fully-set pairs flush unchanged.
 */
final class MorphPairIntegrityTest extends DatabaseTestCase
{
    /**
     * Create the fixture tables.
     */
    protected function setUpDatabase(): void
    {
        $this->createTables(MorphPairMedia::class, MorphPairTypedMedia::class, PolyPost::class);
    }

    // ---- Synthetic columns (setAttribute / runtime store) ----

    /**
     * Setting only the pair's key and flushing throws — the type half
     * would stay null.
     */
    public function testInsertWithOnlyKeySetThrows(): void
    {
        $media = new MorphPairMedia();
        $media->path = 'a.png';
        $media->setAttribute('attachable_id', 1);

        $exception = Expectation::throws(
            fn () => $media->save(),
            \InvalidArgumentException::class,
        );

        self::assertStringContainsString('half-set', $exception->getMessage());
        self::assertStringContainsString('[attachable_type, attachable_id]', $exception->getMessage());
    }

    /**
     * Setting only the pair's type and flushing throws — the key half
     * would stay null.
     */
    public function testInsertWithOnlyTypeSetThrows(): void
    {
        $media = new MorphPairMedia();
        $media->path = 'a.png';
        $media->setAttribute('attachable_type', PolyPost::class);

        $exception = Expectation::throws(
            fn () => $media->save(),
            \InvalidArgumentException::class,
        );

        self::assertStringContainsString('half-set', $exception->getMessage());
        self::assertStringContainsString('[attachable_type, attachable_id]', $exception->getMessage());
    }

    /**
     * A fully-set pair flushes and lands intact.
     */
    public function testInsertWithBothSetFlushes(): void
    {
        $media = new MorphPairMedia();
        $media->path = 'a.png';
        $media->setAttribute('attachable_type', PolyPost::class);
        $media->setAttribute('attachable_id', 1);
        $media->save();

        self::assertTrue(ModelIntrospection::existsOf($media));

        $fresh = MorphPairMedia::newQuery()->findOrFail($media->id);
        self::assertSame(PolyPost::class, $fresh->attribute('attachable_type'));
        self::assertSame(1, $fresh->attribute('attachable_id'));
    }

    /**
     * A fully-null pair flushes — the optional morph target.
     */
    public function testInsertWithBothNullFlushes(): void
    {
        $media = new MorphPairMedia();
        $media->path = 'a.png';
        $media->save();

        self::assertTrue(ModelIntrospection::existsOf($media));

        $fresh = MorphPairMedia::newQuery()->findOrFail($media->id);
        self::assertNull($fresh->attribute('attachable_type'));
        self::assertNull($fresh->attribute('attachable_id'));
    }

    /**
     * Nulling one half of a SET pair on an update throws.
     */
    public function testUpdateNullingOneHalfThrows(): void
    {
        $media = new MorphPairMedia();
        $media->path = 'a.png';
        $media->setAttribute('attachable_type', PolyPost::class);
        $media->setAttribute('attachable_id', 1);
        $media->save();

        $media->setAttribute('attachable_id', null);

        $exception = Expectation::throws(
            fn () => $media->save(),
            \InvalidArgumentException::class,
        );

        self::assertStringContainsString('half-set', $exception->getMessage());
    }

    /**
     * Nulling BOTH halves on an update flushes — the detach shape.
     */
    public function testUpdateNullingBothFlushes(): void
    {
        $media = new MorphPairMedia();
        $media->path = 'a.png';
        $media->setAttribute('attachable_type', PolyPost::class);
        $media->setAttribute('attachable_id', 1);
        $media->save();

        $media->setAttribute('attachable_type', null);
        $media->setAttribute('attachable_id', null);
        $media->save();

        $fresh = MorphPairMedia::newQuery()->findOrFail($media->id);
        self::assertNull($fresh->attribute('attachable_type'));
        self::assertNull($fresh->attribute('attachable_id'));
    }

    /**
     * Re-pointing a pair (both halves change together) flushes.
     */
    public function testUpdateRepointingBothFlushes(): void
    {
        $media = new MorphPairMedia();
        $media->path = 'a.png';
        $media->setAttribute('attachable_type', PolyPost::class);
        $media->setAttribute('attachable_id', 1);
        $media->save();

        $media->setAttribute('attachable_id', 2);
        $media->save();

        $fresh = MorphPairMedia::newQuery()->findOrFail($media->id);
        self::assertSame(2, $fresh->attribute('attachable_id'));
        self::assertSame(PolyPost::class, $fresh->attribute('attachable_type'));
    }

    // ---- Typed-property columns ----

    /**
     * The typed-property path throws identically — writing the key
     * property while the type property stays uninitialized (read as
     * null) leaves the pair half-set.
     */
    public function testTypedInsertWithOnlyKeySetThrows(): void
    {
        $media = new MorphPairTypedMedia();
        $media->path = 'a.png';
        $media->attachableId = 1;

        $exception = Expectation::throws(
            fn () => $media->save(),
            \InvalidArgumentException::class,
        );

        self::assertStringContainsString('half-set', $exception->getMessage());
    }

    /**
     * The typed-property path flushes a fully-set pair.
     */
    public function testTypedInsertWithBothSetFlushes(): void
    {
        $media = new MorphPairTypedMedia();
        $media->path = 'a.png';
        $media->attachableType = PolyPost::class;
        $media->attachableId = 1;
        $media->save();

        self::assertTrue(ModelIntrospection::existsOf($media));

        $fresh = MorphPairTypedMedia::newQuery()->findOrFail($media->id);
        self::assertSame(PolyPost::class, $fresh->attachableType);
        self::assertSame(1, $fresh->attachableId);
    }

    /**
     * Untyped (left uninitialized) BOTH halves flush as null — the
     * unset-typed-property reads as NULL on both sides.
     */
    public function testTypedInsertWithNeitherSetFlushes(): void
    {
        $media = new MorphPairTypedMedia();
        $media->path = 'a.png';
        $media->save();

        self::assertTrue(ModelIntrospection::existsOf($media));
    }

    /**
     * A typed UPDATE nulling one half throws.
     */
    public function testTypedUpdateNullingOneHalfThrows(): void
    {
        $media = new MorphPairTypedMedia();
        $media->path = 'a.png';
        $media->attachableType = PolyPost::class;
        $media->attachableId = 1;
        $media->save();

        $media->attachableType = null;

        $exception = Expectation::throws(
            fn () => $media->save(),
            \InvalidArgumentException::class,
        );

        self::assertStringContainsString('half-set', $exception->getMessage());
    }

    // ---- Snapshot-space sanity ----

    /**
     * The guard is flush-time: mutating a half WITHOUT saving never
     * throws — the row in the database stays intact.
     */
    public function testUnsavedHalfWriteDoesNotThrowUntilFlush(): void
    {
        $media = new MorphPairMedia();
        $media->path = 'a.png';
        $media->setAttribute('attachable_type', PolyPost::class);
        $media->setAttribute('attachable_id', 1);
        $media->save();

        // The illegal in-memory state is fine until save().
        $media->setAttribute('attachable_id', null);

        self::assertSame(
            PolyPost::class,
            MorphPairMedia::newQuery()->findOrFail($media->id)->attribute('attachable_type'),
            'the stored row is untouched until the guarded flush',
        );
    }
}
