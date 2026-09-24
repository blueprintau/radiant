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
            $this->query = $this->query->orderBy($primaryKeys[0]->name);
        }
    }

    /**
     * Run the constrained query and keep only the first match.
     *
     * @return Collection<TRelated>
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
     * @param  ModelQueryBuilder<TRelated>  $query
     * @return ModelQueryBuilder<TRelated>
     */
    protected function applyEagerOrdering(ModelQueryBuilder $query): ModelQueryBuilder
    {
        $primaryKeys = MetadataFactory::for($this->related)->primaryKeys;

        if (count($primaryKeys) === 1 && $primaryKeys[0]->name !== null) {
            $query = $query->orderBy($primaryKeys[0]->name);
        }

        return $query;
    }

    /**
     * Distribute eager results — first match per parent key.
     *
     * @param  list<Model>  $parents
     * @param  Collection<TRelated>  $results
     * @param  string  $name
     * @param  list<int|string|null|list<int|string|null>>|null  $eagerParentKeys
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
