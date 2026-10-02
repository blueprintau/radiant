<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Model;

use BlueprintAU\Radiant\Tests\Support\DatabaseTestCase;
use BlueprintAU\Radiant\Tests\Support\Expectation;
use BlueprintAU\Radiant\Tests\Unit\Model\Fixtures\CompositePkRelationProbe;
use BlueprintAU\Radiant\Tests\Unit\Model\Fixtures\NoPkRelationTarget;
use BlueprintAU\Radiant\Tests\Unit\Model\Fixtures\ScalarPkRelationProbe;
use BlueprintAU\Radiant\Tests\Unit\Model\Fixtures\StringKeyMorphTarget;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\CmpRegion;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\PolyComment;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\PolyPost;
use PHPUnit\Framework\Attributes\DoesNotPerformAssertions;

/**
 * The relation-convention throw arms on {@see \BlueprintAU\Radiant\Model} —
 * the fail-fast guards that fire when a convention cannot derive its
 * counterpart: composite keys handed to scalar-key relations, morph pairs
 * without a derivable name, and relation endpoints without a usable
 * primary key.
 *
 * The relation methods are protected, so each test probes them through a
 * named fixture subclass ({@see CompositePkRelationProbe},
 * {@see ScalarPkRelationProbe}) — named classes keep Intelephense's
 * completion working, where anonymous subclasses do not.
 */
final class ModelConventionThrowsTest extends DatabaseTestCase
{
    /**
     * morphMany() over a composite local key fails fast — the morph
     * (type, key) pair is a scalar-key convention.
     */
    public function testMorphManyCompositeLocalKeyThrows(): void
    {
        Expectation::throwsWithMessage(
            fn () => (new CompositePkRelationProbe())->probeMorphMany(PolyPost::class, 'commentable'),
            \LogicException::class,
            'morphMany() does not support composite keys',
        );
    }

    /**
     * morphOne() over a composite local key fails fast the same way.
     */
    public function testMorphOneCompositeLocalKeyThrows(): void
    {
        Expectation::throwsWithMessage(
            fn () => (new CompositePkRelationProbe())->probeMorphOne(PolyPost::class, 'commentable'),
            \LogicException::class,
            'morphOne() does not support composite keys',
        );
    }

    /**
     * A morph relation with neither a morph name nor a `_type`-suffixed
     * type column cannot derive its FK — fail fast.
     */
    public function testMorphForeignKeyWithoutNameOrTypeColumnThrows(): void
    {
        Expectation::throwsWithMessage(
            fn () => (new ScalarPkRelationProbe())->probeMorphMany(PolyComment::class, null, typeColumn: 'not_type_suffixed'),
            \InvalidArgumentException::class,
            'needs a morph name (or an explicit `_type`-suffixed type column)',
        );
    }

    /**
     * A morph relation with neither a morph name nor an `_id`-suffixed FK
     * cannot derive its type column — fail fast.
     */
    public function testMorphTypeColumnWithoutNameOrForeignKeyThrows(): void
    {
        Expectation::throwsWithMessage(
            fn () => (new ScalarPkRelationProbe())->probeMorphMany(PolyComment::class, null, foreignKey: 'no_suffix'),
            \InvalidArgumentException::class,
            'needs a morph name (or an explicit `_id`-suffixed foreign key)',
        );
    }

    /**
     * A morphMany with an EXPLICIT `_id`-suffixed FK and no morph name
     * derives the type column by mirroring `_id` → `_type` — the
     * convention's success path.
     */
    #[DoesNotPerformAssertions]
    public function testMorphManyDerivesTypeColumnFromExplicitForeignKey(): void
    {
        // The call itself is the subject: the derivation succeeds and the
        // relation builds (PolyComment declares both morph columns).
        (new ScalarPkRelationProbe())->probeMorphMany(PolyComment::class, null, foreignKey: 'commentable_id');
    }

    /**
     * A morphTo with an EXPLICIT `_id`-suffixed FK and no morph name
     * cannot derive its columns — the FK derivation runs FIRST on the
     * inverse side and needs the type column (or a name), so the
     * `_id` → `_type` mirror is unreachable there.
     */
    public function testMorphToWithForeignKeyOnlyThrows(): void
    {
        Expectation::throwsWithMessage(
            fn () => (new ScalarPkRelationProbe())->probeMorphTo(foreignKey: 'commentable_id'),
            \InvalidArgumentException::class,
            'needs a morph name (or an explicit `_id`-suffixed foreign key)',
        );
    }

    /**
     * A hasMany over a composite local key cannot derive its FK columns
     * by convention — fail fast.
     */
    public function testHasManyCompositeLocalKeyWithoutExplicitForeignThrows(): void
    {
        Expectation::throwsWithMessage(
            fn () => (new CompositePkRelationProbe())->probeHasMany(PolyPost::class),
            \LogicException::class,
            'cannot derive its counterpart columns by convention',
        );
    }

    /**
     * A belongsTo over a composite owner key cannot derive its FK by
     * convention — fail fast.
     */
    public function testBelongsToCompositeOwnerKeyWithoutExplicitForeignThrows(): void
    {
        Expectation::throwsWithMessage(
            fn () => (new ScalarPkRelationProbe())->probeBelongsTo(CmpRegion::class),
            \LogicException::class,
            'cannot derive its counterpart columns by convention',
        );
    }

    /**
     * A relation endpoint without any primary key fails fast — the owner-
     * key convention cannot read one.
     */
    public function testRelationEndpointWithoutPrimaryKeyThrows(): void
    {
        Expectation::throwsWithMessage(
            fn () => (new ScalarPkRelationProbe())->probeBelongsTo(NoPkRelationTarget::class),
            \LogicException::class,
            'Relation endpoints require a primary key',
        );
    }

    /**
     * A morph relation declared ON a composite-PK model fails fast — the
     * morph key column holds one scalar. The explicit scalar localKey
     * bypasses the composite-localKey guard so the PK-shape guard fires.
     */
    public function testMorphOnCompositePkModelThrows(): void
    {
        Expectation::throwsWithMessage(
            fn () => (new CompositePkRelationProbe())->probeMorphMany(PolyComment::class, 'commentable', localKey: 'id'),
            \LogicException::class,
            'A morph target requires a single named primary key',
        );
    }

    /**
     * A morph key column whose type differs from the parent's primary-key
     * type fails fast.
     */
    public function testMorphKeyTypeMismatchThrows(): void
    {
        Expectation::throwsWithMessage(
            fn () => (new ScalarPkRelationProbe())->probeMorphMany(StringKeyMorphTarget::class, 'commentable'),
            \InvalidArgumentException::class,
            'A morph pair can only point at models whose primary-key type matches',
        );
    }

    /**
     * relationLoaded() reports false for a relation that was never
     * loaded — the negative arm of the eager-load check.
     */
    #[DoesNotPerformAssertions]
    public function testRelationLoadedFalseForUnloadedRelation(): void
    {
        $post = new PolyPost();

        // The call itself is the subject: no exception, returns false.
        if ($post->relationLoaded('comments')) {
            self::fail('an unloaded relation must not report as loaded');
        }
    }
}
