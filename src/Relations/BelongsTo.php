<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Relations;

use BlueprintAU\Radiant\Collection;
use BlueprintAU\Radiant\Database\Query\Enums\WhereOperator;
use BlueprintAU\Radiant\Database\Query\WhereBuilder;
use BlueprintAU\Radiant\Model;

/**
 * The inverse one-to-one/one-to-many: the PARENT table holds the FK.
 *
 * `Post::author()` → `User::newQuery()->where('id', '=', $post->user_id)`.
 * A null FK (a legitimately optional relation) resolves to no results —
 * standard SQL semantics, not an error.
 *
 * @template TRelated of Model
 * @extends Relation<TRelated>
 * @phpstan-import-type KeyValue from \BlueprintAU\Radiant\Model
 */
final class BelongsTo extends Relation
{
    /**
     * Constrain the related query to the parent's FK value.
     *
     * @return void
     */
    protected function addConstraints(): void
    {
        if ($this->isComposite()) {
            $values = $this->parentKeyValues($this->getForeignKeys());

            if (!in_array(null, $values, true)) {
                // The tuple lands inside a whereNested GROUP — the relation
                // constraint is ONE unit. Flat, a caller's later
                // `->orWhere(...)` would OR against the tuple's PARTS
                // ((fk1 = ? AND fk2 = ?) OR x — matching the wrong rows);
                // grouped, the parts AND within the parens and the caller's
                // OR stays at the constraint's edges.
                //
                // local side = the RELATED table's owner columns, foreign
                // side = THIS table's FK columns.
                $this->query = $this->query->whereNested(fn (WhereBuilder $nested): WhereBuilder => self::applyKeyTuple(
                    $nested,
                    $this->getLocalKeys(),
                    $this->getForeignKeys(),
                    $values,
                ));
            } else {
                // Null FK component → no results, without compiling a
                // meaningless query.
                $this->query = $this->query->whereRaw('1 = 0', []);
            }

            return;
        }

        $fkValue = $this->parent->attribute($this->getForeignKey());

        if ($fkValue !== null) {
            $this->query = $this->query->where($this->getLocalKey(), WhereOperator::Eq, $fkValue);
        } else {
            // Null FK → no results, without compiling a meaningless query.
            $this->query = $this->query->whereRaw('1 = 0', []);
        }
    }

    /**
     * Run the constrained query — a single model or none.
     *
     * @return Collection<TRelated>
     */
    #[\Override]
    protected function executeResults(): Collection
    {
        if ($this->isComposite()) {
            if (in_array(null, $this->parentKeyValues($this->getForeignKeys()), true)) {
                return Collection::make([]);
            }
        } elseif ($this->parent->attribute($this->getForeignKey()) === null) {
            return Collection::make([]);
        }

        $first = $this->query->first();

        return Collection::make($first === null ? [] : [$first]);
    }

    /**
     * Run the eager query — the FK lives on the PARENT, so the IN clause
     * targets the related table's owner key ({@see BelongsTo::$localKey}).
     * A composite key widens to an OR of AND-groups.
     *
     * @param  list<KeyValue>  $parentKeys
     * @return EagerResult<TRelated>
     */
    #[\Override]
    protected function eagerLoadChunk(array $parentKeys): EagerResult
    {
        if (!$this->isComposite()) {
            return EagerResult::fromCollection(
                $this->related::newQuery()
                    ->whereIn($this->getLocalKey(), $parentKeys)
                    ->get(),
            );
        }

        $localKeys = $this->getLocalKeys();
        $foreignKeys = $this->getForeignKeys();
        $query = $this->related::newQuery();

        foreach ($parentKeys as $parentKey) {
            if (!is_array($parentKey)) {
                throw new \InvalidArgumentException(
                    'A composite relation key requires column => value key maps for eager loading; '
                    . 'got ' . get_debug_type($parentKey) . '.'
                );
            }

            $query = $query->orWhereNested(fn (WhereBuilder $nested): WhereBuilder => self::applyKeyTuple(
                $nested,
                $localKeys,
                $foreignKeys,
                $parentKey,
            ));
        }

        return EagerResult::fromCollection($query->get());
    }

    /**
     * The parent column(s) the eager loader collects key values from.
     *
     * @return string|list<string>
     */
    public function eagerKeyColumn(): string|array
    {
        return $this->foreignKey;
    }

    /**
     * Distribute eager results onto parents by FK value.
     *
     * @param  list<Model>  $parents
     * @param  Collection<TRelated>  $results
     * @param  string  $name
     * @param  list<int|string|null|list<int|string|null>>|null  $eagerParentKeys  Unused.
     * @return void
     */
    public function match(array $parents, Collection $results, string $name, ?array $eagerParentKeys = null): void
    {
        $byKey = [];

        foreach ($results as $related) {
            $key = $this->isComposite()
                ? self::tupleValues($related, $this->getLocalKeys())
                : $related->attribute($this->getLocalKey());
            $byKey[self::serializeKey($key)] = $related;
        }

        foreach ($parents as $parent) {
            $key = $this->isComposite()
                ? self::tupleValues($parent, $this->getForeignKeys())
                : $parent->attribute($this->getForeignKey());
            $related = $byKey[self::serializeKey($key)] ?? null;
            $parent->setRelation($name, $related);
        }
    }
}
