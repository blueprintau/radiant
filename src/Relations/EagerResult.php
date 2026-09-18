<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Relations;

use BlueprintAU\Radiant\Collection;

/**
 * The result of one eager load: the related models plus, for through
 * relations, the per-row parent key each model belongs to.
 *
 * This exists so {@see HasManyThrough} does not have to record its
 * parent-key list on the relation instance itself. A relation object is
 * CACHED and shared across eager loads (ModelQueryBuilder::$relationCache);
 * per-call state on the instance meant two interleaved loads of the same
 * through-relation — sequential in one coroutine, concurrent under
 * Swoole/Fiber — could read each other's keys and distribute children to
 * the wrong parents. Returning per-call state instead makes the whole
 * eager-load path stateless with respect to the cached relation.
 */
final class EagerResult
{
    /**
     * The loaded related models, in query order.
     *
     * @var Collection<\BlueprintAU\Radiant\Model>
     */
    public readonly Collection $models;

    /**
     * The parent key for each model, positionally paired with $models —
     * index i of this list is the key of the parent that $models[i]
     * belongs to. Null for relations that re-derive the key from the model
     * itself (HasOne/HasMany/BelongsTo read the model's FK attribute);
     * through relations set it (the key travels through the join, not on
     * the related model).
     *
     * @var list<int|string|null|list<int|string|null>>|null
     */
    public readonly ?array $parentKeys;

    /**
     * @param Collection<\BlueprintAU\Radiant\Model> $models The models.
     * @param list<int|string|null|list<int|string|null>>|null $parentKeys The per-row parent keys, or null.
     */
    public function __construct(Collection $models, ?array $parentKeys = null)
    {
        $this->models = $models;
        $this->parentKeys = $parentKeys;
    }

    /**
     * A models-only result — no per-row parent keys.
     *
     * @param list<\BlueprintAU\Radiant\Model>|array<int,\BlueprintAU\Radiant\Model> $models The models (list or map — re-indexed by
     *        the collection constructor).
     * @return self The result.
     */
    public static function fromModels(array $models): self
    {
        return new self(Collection::make(array_values($models)));
    }
}
