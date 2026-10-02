<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Model\Fixtures;

use BlueprintAU\Radiant\Relations\BelongsTo;
use BlueprintAU\Radiant\Relations\MorphMany;
use BlueprintAU\Radiant\Relations\MorphTo;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\PolyPost;

/**
 * Fixture: a scalar-PK model exposing the protected relation methods as
 * public probes — the convention-throw tests need to call them from
 * outside the class hierarchy.
 */
class ScalarPkRelationProbe extends PolyPost
{
    /**
     * Probe: expose the protected morphMany.
     *
     * @template TRelated of \BlueprintAU\Radiant\Model
     *
     * @param  class-string<TRelated>  $related
     * @param  string|null  $morphName
     * @param  string|null  $foreignKey
     * @param  string|null  $localKey
     * @param  string|null  $typeColumn
     * @return MorphMany<TRelated>
     */
    public function probeMorphMany(
        string $related,
        ?string $morphName = null,
        ?string $foreignKey = null,
        ?string $localKey = null,
        ?string $typeColumn = null,
    ): MorphMany {
        return $this->morphMany($related, $morphName, $foreignKey, $localKey, $typeColumn);
    }

    /**
     * Probe: expose the protected morphTo.
     *
     * @param  string|null  $morphName
     * @param  string|null  $foreignKey
     * @param  string|null  $typeColumn
     * @return MorphTo<\BlueprintAU\Radiant\Model>
     */
    public function probeMorphTo(
        ?string $morphName = null,
        ?string $foreignKey = null,
        ?string $typeColumn = null,
    ): MorphTo {
        return $this->morphTo($morphName, $typeColumn, $foreignKey);
    }

    /**
     * Probe: expose the protected belongsTo.
     *
     * @template TRelated of \BlueprintAU\Radiant\Model
     *
     * @param  class-string<TRelated>  $related
     * @return BelongsTo<TRelated>
     */
    public function probeBelongsTo(string $related): BelongsTo
    {
        return $this->belongsTo($related);
    }
}
