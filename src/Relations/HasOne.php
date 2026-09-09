<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Relations;

use BlueprintAU\Radiant\Collection;
use BlueprintAU\Radiant\Database\Query\WhereBuilder;
use BlueprintAU\Radiant\Metadata\MetadataFactory;
use BlueprintAU\Radiant\Model;

/**
 * One-to-one: HasMany's first row, stably ordered by the related PK.
 *
 * Multiple matching rows are a data concern, not a relation error — the
 * first row wins (ordered by the related model's PK for determinism) and
 * uniqueness is the schema's job (`#[Column(unique: true)]` on the FK).
 *
 * @template TRelated of Model
 * @extends HasMany<TRelated>
 */
final class HasOne extends HasMany
{
    /**
     * Constrain the related query to the parent's key, stably ordered.
     *
     * @return void
     */
    protected function addConstraints(): void
    {
        $primaryKeys = MetadataFactory::for($this->related)->primaryKeys;

        if (count($primaryKeys) === 1 && $primaryKeys[0]->name !== null) {
            $this->query->orderBy($primaryKeys[0]->name);
        }

        if ($this->isComposite()) {
            // The tuple lands inside a whereNested GROUP — the relation
            // constraint is ONE unit: the parts AND within the parens, and
            // a caller's later `->orWhere(...)` ORs at the constraint's
            // edges instead of against the tuple's PARTS.
            $this->query->whereNested(fn (WhereBuilder $nested) => self::applyKeyTuple(
                $nested,
                $this->getForeignKeys(),
                $this->getLocalKeys(),
                $this->parentKeyValues($this->getLocalKeys()),
            ));

            return;
        }

        $this->query->where($this->getForeignKey(), '=', $this->parent->attribute($this->getLocalKey()));
    }

    /**
     * Run the constrained query and keep only the first match.
     *
     * @return Collection<TRelated> A one-element (or empty) collection.
     */
    public function getResults(): Collection
    {
        $first = $this->query->first();

        return Collection::make($first === null ? [] : [$first]);
    }

    /**
     * Distribute eager results onto parents — first match per FK value.
     *
     * @param list<Model> $parents The parents to populate.
     * @param Collection<TRelated> $results The related models.
     * @param string $name The relation name (the cache key).
     * @return void
     */
    public function match(array $parents, Collection $results, string $name): void
    {
        $first = [];

        foreach ($results as $related) {
            $key = $this->isComposite()
                ? self::serializeKey(self::tupleValues($related, $this->getForeignKeys()))
                : $related->attribute($this->getForeignKey());

            if (!isset($first[self::serializeKey($key)])) {
                $first[self::serializeKey($key)] = $related;
            }
        }

        foreach ($parents as $parent) {
            $key = $this->isComposite()
                ? self::tupleValues($parent, $this->getLocalKeys())
                : $parent->attribute($this->getLocalKey());
            $related = $first[self::serializeKey($key)] ?? null;
            $parent->setRelation($name, $related);
        }
    }
}
