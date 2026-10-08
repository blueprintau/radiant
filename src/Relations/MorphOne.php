<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Relations;

use BlueprintAU\Radiant\Collection;
use BlueprintAU\Radiant\Metadata\MetadataFactory;
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\ModelQueryBuilder;

/**
 * One-to-one polymorphic: MorphOneOrMany's first row, stably ordered.
 *
 * `Post::image()` → `Image::newQuery()->where(imageable_id, $post->id)
 * ->where(imageable_type, Post::class)`. Multiple matching rows are a data
 * concern — the first row wins, ordered by the related PK for determinism
 * (the same contract {@see \BlueprintAU\Radiant\Relations\HasOne} applies).
 *
 * @template TRelated of Model
 * @extends MorphOneOrMany<TRelated>
 */
final class MorphOne extends MorphOneOrMany
{
    /**
     * Order the lazy query stably by the related PK — first-wins must be
     * deterministic.
     *
     * @return void
     */
    #[\Override]
    protected function addConstraints(): void
    {
        $primaryKeys = MetadataFactory::for($this->getRelated())->primaryKeys;

        if (count($primaryKeys) === 1 && $primaryKeys[0]->name !== null) {
            $this->query = $this->query->orderBy($primaryKeys[0]->name);
        }

        parent::addConstraints();
    }

    /**
     * Run the constrained query and keep only the first match.
     *
     * @return Collection<int, TRelated>
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
    #[\Override]
    protected function applyEagerOrdering(ModelQueryBuilder $query): ModelQueryBuilder
    {
        $primaryKeys = MetadataFactory::for($this->getRelated())->primaryKeys;

        if (count($primaryKeys) === 1 && $primaryKeys[0]->name !== null) {
            $query = $query->orderBy($primaryKeys[0]->name);
        }

        return parent::applyEagerOrdering($query);
    }

    /**
     * Distribute eager results onto parents — first match per FK value.
     *
     * @param  list<Model>  $parents
     * @param  Collection<int, TRelated>  $results
     * @param  string  $name
     * @param  list<int|string|null|list<int|string|null>>|null  $eagerParentKeys  Unused.
     * @return void
     */
    #[\Override]
    public function match(array $parents, Collection $results, string $name, ?array $eagerParentKeys = null): void
    {
        $first = [];

        foreach ($results as $related) {
            $key = self::serializeKey($related->attribute($this->getForeignKey()));

            if (!isset($first[$key])) {
                $first[$key] = $related;
            }
        }

        foreach ($parents as $parent) {
            $key = self::serializeKey($parent->attribute($this->getLocalKey()));
            $parent->setRelation($name, $first[$key] ?? null);
        }
    }
}
