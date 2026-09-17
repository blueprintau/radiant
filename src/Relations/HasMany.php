<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Relations;

use BlueprintAU\Radiant\Collection;
use BlueprintAU\Radiant\Database\Query\WhereBuilder;
use BlueprintAU\Radiant\Model;

/**
 * One-to-many: the parent's key is referenced by the related table's FK.
 *
 * `User::posts()` → `Post::newQuery()->where('user_id', '=', $user->id)`.
 * The FK lives on the RELATED table; the local key lives on the parent.
 *
 * @template TRelated of Model
 * @extends Relation<TRelated>
 */
class HasMany extends Relation
{
    /**
     * Constrain the related query to the parent's key.
     *
     * A composite key applies the full tuple: every FK column equals the
     * parent's corresponding local value (null components are IS NULL).
     *
     * @return void
     */
    protected function addConstraints(): void
    {
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

        $parentKey = $this->parent->attribute($this->getLocalKey());

        if ($parentKey === null) {
            // Null parent key → no results, without compiling a meaningless
            // query (BelongsTo's convention; `fk = NULL` matches no rows).
            $this->query->whereRaw('1 = 0', []);
            return;
        }

        $this->query->where($this->getForeignKey(), '=', $parentKey);
    }

    /**
     * Run the constrained query.
     *
     * @return Collection<TRelated> Every related model matching the parent's key.
     */
    public function getResults(): Collection
    {
        return $this->query->get();
    }

    /**
     * Distribute eager results onto parents, keyed by the FK value.
     *
     * Parents with no matching children get an empty collection — the
     * relation is loaded either way. A composite key groups by the full
     * FK tuple (serialized to a stable string key).
     *
     * @param list<Model> $parents The parents to populate.
     * @param Collection<TRelated> $results The related models.
     * @param string $name The relation name (the cache key).
     * @param list<int|string|null|list<int|string|null>>|null $eagerParentKeys
     *        Unused here — the FK lives on each related model, so the key is
     *        re-derived from the model itself (accepted for signature parity
     *        with the through relations, which need it).
     * @return void
     */
    public function match(array $parents, Collection $results, string $name, ?array $eagerParentKeys = null): void
    {
        $grouped = [];

        foreach ($results as $related) {
            $key = $this->isComposite()
                ? self::serializeKey(self::tupleValues($related, $this->getForeignKeys()))
                : $related->attribute($this->getForeignKey());
            $grouped[self::serializeKey($key)][] = $related;
        }

        foreach ($parents as $parent) {
            $key = $this->isComposite()
                ? self::tupleValues($parent, $this->getLocalKeys())
                : $parent->attribute($this->getLocalKey());
            $parent->setRelation($name, Collection::make($grouped[self::serializeKey($key)] ?? []));
        }
    }
}
