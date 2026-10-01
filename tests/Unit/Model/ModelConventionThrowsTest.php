<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Model;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\Tests\Support\DatabaseTestCase;
use BlueprintAU\Radiant\Tests\Support\Expectation;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\CmpRegion;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\PolyComment;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\PolyPost;
use PHPUnit\Framework\Attributes\DoesNotPerformAssertions;

/**
 * The relation-convention throw arms on {@see Model} — the fail-fast
 * guards that fire when a convention cannot derive its counterpart:
 * composite keys handed to scalar-key relations, morph pairs without a
 * derivable name, and relation endpoints without a usable primary key.
 *
 * The relation methods are protected, so each test probes them through an
 * anonymous subclass.
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
            fn () => (new /** composite-PK holder */ class extends CmpRegion {
                /** Probe: expose the protected morphMany.
                 *
                 * @return mixed
                 */
                public function probe(): mixed
                {
                    return $this->morphMany(PolyPost::class, 'commentable');
                }
            })->probe(),
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
            fn () => (new /** composite-PK holder */ class extends CmpRegion {
                /** Probe: expose the protected morphOne.
                 *
                 * @return mixed
                 */
                public function probe(): mixed
                {
                    return $this->morphOne(PolyPost::class, 'commentable');
                }
            })->probe(),
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
            fn () => (new /** probe holder */ class extends PolyPost {
                /** Probe: expose the protected morphMany.
                 *
                 * @return mixed
                 */
                public function probe(): mixed
                {
                    return $this->morphMany(PolyComment::class, '', typeColumn: 'not_type_suffixed');
                }
            })->probe(),
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
            fn () => (new /** probe holder */ class extends PolyPost {
                /** Probe: expose the protected morphMany.
                 *
                 * @return mixed
                 */
                public function probe(): mixed
                {
                    return $this->morphMany(PolyComment::class, '', foreignKey: 'no_suffix');
                }
            })->probe(),
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
            fn () => (new /** composite-PK holder */ class extends CmpRegion {
                /** Probe: expose the protected hasMany.
                 *
                 * @return mixed
                 */
                public function probe(): mixed
                {
                    return $this->hasMany(PolyPost::class);
                }
            })->probe(),
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
            fn () => (new /** probe holder */ class extends PolyPost {
                /** Probe: expose the protected belongsTo.
                 *
                 * @return mixed
                 */
                public function probe(): mixed
                {
                    return $this->belongsTo(CmpRegion::class);
                }
            })->probe(),
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
            fn () => (new /** probe holder */ class extends PolyPost {
                /** Probe: expose the protected belongsTo.
                 *
                 * @return mixed
                 */
                public function probe(): mixed
                {
                    return $this->belongsTo(NoPkRelationTarget::class);
                }
            })->probe(),
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
            fn () => (new /** composite-PK holder */ class extends CmpRegion {
                /** Probe: expose the protected morphMany.
                 *
                 * @return mixed
                 */
                public function probe(): mixed
                {
                    return $this->morphMany(PolyComment::class, 'commentable', localKey: 'id');
                }
            })->probe(),
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
            fn () => (new /** probe holder */ class extends PolyPost {
                /** Probe: expose the protected morphMany.
                 *
                 * @return mixed
                 */
                public function probe(): mixed
                {
                    return $this->morphMany(StringKeyMorphTarget::class, 'commentable');
                }
            })->probe(),
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

/**
 * Fixture: a model with a declared FK column but NO primary key — the
 * owner-key convention cannot derive one.
 */
class NoPkRelationTarget extends Model
{
    /**
     * The FK column back to the parent.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt)]
    public int $post_id;
}

/**
 * Fixture: a morph child whose morph key column is a string — mismatching
 * PolyPost's bigint primary key, so the type guard fires.
 */
class StringKeyMorphTarget extends Model
{
    /**
     * The primary key.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * The morph FK column — a STRING, mismatching the parent's bigint PK.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 36)]
    public string $commentable_id;

    /**
     * The morph type column.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 255)]
    public string $commentable_type;
}
