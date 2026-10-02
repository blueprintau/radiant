<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Model\Fixtures;

use BlueprintAU\Radiant\Relations\HasMany;
use BlueprintAU\Radiant\Relations\MorphMany;
use BlueprintAU\Radiant\Relations\MorphOne;
use BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures\CmpRegion;

/**
 * Fixture: a composite-PK model exposing the protected relation methods
 * as public probes — the convention-throw tests need to call them from
 * outside the class hierarchy.
 */
class CompositePkRelationProbe extends CmpRegion
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
     * Probe: expose the protected morphOne.
     *
     * @template TRelated of \BlueprintAU\Radiant\Model
     *
     * @param  class-string<TRelated>  $related
     * @param  string|null  $morphName
     * @return MorphOne<TRelated>
     */
    public function probeMorphOne(string $related, ?string $morphName = null): MorphOne
    {
        return $this->morphOne($related, $morphName);
    }

    /**
     * Probe: expose the protected hasMany.
     *
     * @template TRelated of \BlueprintAU\Radiant\Model
     *
     * @param  class-string<TRelated>  $related
     * @return HasMany<TRelated>
     */
    public function probeHasMany(string $related): HasMany
    {
        return $this->hasMany($related);
    }
}
