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
 *
 * @template TModel of \BlueprintAU\Radiant\Model The related model class.
 */
final class EagerResult
{
    /**
     * The loaded related models, in query order.
     *
     * @var Collection<TModel>
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
     * @param Collection<TModel> $models The models.
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
     * @template TRelatedModel of \BlueprintAU\Radiant\Model
     *
     * @param list<TRelatedModel>|array<int,TRelatedModel> $models The models (list or map — re-indexed by
     *        the collection constructor).
     * @return self<TRelatedModel> The result.
     */
    public static function fromModels(array $models): self
    {
        return new self(self::listToCollection($models));
    }

    /**
     * A models-only result built from an EXISTING collection — no per-row
     * parent keys.
     *
     * The no-copy path: {@see fromModels()} forces callers holding a
     * collection to `->all()` it first, dismantling and re-wrapping the
     * same items. This accepts the collection as-is (the query's `get()`
     * result is already a 0-based list, so no re-indexing is needed).
     *
     * @template TRelatedModel of \BlueprintAU\Radiant\Model
     *
     * @param Collection<TRelatedModel> $models The models, in query order.
     * @return self<TRelatedModel> The result.
     */
    public static function fromCollection(Collection $models): self
    {
        return new self($models);
    }

    /**
     * Wrap a model list into a collection, preserving the element template.
     *
     * PHPStan loses the element type when `Collection::make(...)` is passed
     * straight into a generic constructor (the argument is inferred against
     * the constructor's template before `make()`'s own inference settles,
     * collapsing to the bound). Routing through a helper whose RETURN is
     * explicitly `Collection<T>` keeps the template intact so the
     * constructor infers `TModel` correctly.
     *
     * @template TRelatedModel of \BlueprintAU\Radiant\Model
     *
     * @param list<TRelatedModel>|array<int,TRelatedModel> $models The models (list or map — re-indexed).
     * @return Collection<TRelatedModel> The models as a 0-based list collection.
     *
     * @internal Construction detail of the eager-load path; not public API.
     */
    public static function listToCollection(array $models): Collection
    {
        return Collection::make(array_values($models));
    }
}
