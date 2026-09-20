<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Relations;

use BlueprintAU\Radiant\Collection;
use BlueprintAU\Radiant\Metadata\MetadataFactory;
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\ModelQueryBuilder;

/**
 * One-to-one through an intermediate model.
 *
 * Same join shape as {@see HasManyThrough}; the results reduce to one row
 * per parent (the first match per parent key from the eager query, stably
 * ordered by the related PK — the uniqueness contract is the schema's job,
 * mirroring {@see HasOne}).
 *
 * @template TRelated of Model
 * @extends HasManyThrough<TRelated>
 */
final class HasOneThrough extends HasManyThrough
{
    /**
     * Constrain the related query, stably ordered by the related PK — the
     * first-row guarantee for the lazy path.
     *
     * @return void
     */
    protected function addConstraints(): void
    {
        parent::addConstraints();

        $primaryKeys = MetadataFactory::for($this->related)->primaryKeys;

        if (count($primaryKeys) === 1 && $primaryKeys[0]->name !== null) {
            $this->query->orderBy($primaryKeys[0]->name);
        }
    }

    /**
     * Run the constrained query and keep only the first match.
     *
     * @return Collection<TRelated> A one-element (or empty) collection.
     */
    #[\Override]
    protected function executeResults(): Collection
    {
        $first = $this->query->first();

        return Collection::make($first === null ? [] : [$first]);
    }

    /**
     * Order the eager-load query stably, mirroring the lazy path.
     *
     * Same rationale as {@see \BlueprintAU\Radiant\Relations\HasOne::applyEagerOrdering()}:
     * the lazy path orders by the related PK before `first()`; the eager
     * path must too, or `match()` keeps whichever duplicate row the
     * database returned first.
     *
     * @param ModelQueryBuilder<TRelated> $query The chunk's eager query.
     * @return void
     */
    protected function applyEagerOrdering(ModelQueryBuilder $query): void
    {
        $primaryKeys = MetadataFactory::for($this->related)->primaryKeys;

        if (count($primaryKeys) === 1 && $primaryKeys[0]->name !== null) {
            $query->orderBy($primaryKeys[0]->name);
        }
    }

    /**
     * Distribute eager results — first match per parent key.
     *
     * The per-row parent keys come from the {@see EagerResult} (per-call
     * state — the relation object is cached and shared, so nothing mutable
     * lands on the instance).
     *
     * @param list<Model> $parents The parents to populate.
     * @param Collection<TRelated> $results The related models.
     * @param string $name The relation name (the cache key).
     * @param list<int|string|null|list<int|string|null>>|null $eagerParentKeys The per-row parent keys from eagerLoad().
     * @return void
     */
    public function match(array $parents, Collection $results, string $name, ?array $eagerParentKeys = null): void
    {
        if ($eagerParentKeys === null) {
            throw new \LogicException(
                static::class . '::match() requires the EagerResult parent keys; '
                . 'call it with the array returned by eagerLoad(), not the models alone.'
            );
        }

        $first = [];

        foreach ($results as $i => $model) {
            $parentKey = $eagerParentKeys[$i] ?? null;

            if ($parentKey === null || isset($first[self::serializeKey($parentKey)])) {
                continue;
            }

            $first[self::serializeKey($parentKey)] = $model;
        }

        $localKeys = $this->isComposite() ? $this->getLocalKeys() : [$this->getLocalKey()];

        foreach ($parents as $parent) {
            $key = $this->isComposite()
                ? self::tupleValues($parent, $localKeys)
                : $parent->attribute($localKeys[0]);
            $parent->setRelation($name, $first[self::serializeKey($key)] ?? null);
        }
    }
}
