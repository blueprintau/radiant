<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Relations;

use BlueprintAU\Radiant\Collection;
use BlueprintAU\Radiant\Database\Query\Enums\WhereBoolean;
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
                $this->query->whereNested(fn (WhereBuilder $nested) => self::applyKeyTuple(
                    $nested,
                    $this->getLocalKeys(),
                    $this->getForeignKeys(),
                    $values,
                ));
            } else {
                // Null FK component → no results, without compiling a
                // meaningless query.
                $this->query->whereRaw('1 = 0', []);
            }

            return;
        }

        $fkValue = $this->parent->attribute($this->getForeignKey());

        if ($fkValue !== null) {
            $this->query->where($this->getLocalKey(), '=', $fkValue);
        } else {
            // Null FK → no results, without compiling a meaningless query.
            $this->query->whereRaw('1 = 0', []);
        }
    }

    /**
     * Run the constrained query — a single model or none.
     *
     * @return Collection<TRelated> A one-element (or empty) collection.
     */
    public function getResults(): Collection
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
     * A composite key widens to an OR of AND-groups (one per parent tuple).
     *
     * Chunking is inherited from the base: this method is the per-chunk
     * strategy ({@see Relation::eagerLoadChunk()} overrides), called once
     * per bounded key list.
     *
     * @param list<KeyValue> $parentKeys The parents' FK values.
     * @return EagerResult The related models — no per-row parent keys;
     *         {@see match()} re-derives the key from each model's FK
     *         attribute, which the select carries.
     */
    #[\Override]
    protected function eagerLoadChunk(array $parentKeys): EagerResult
    {
        if (!$this->isComposite()) {
            return EagerResult::fromModels(
                $this->related::newQuery()
                    ->whereIn($this->getLocalKey(), $parentKeys)
                    ->get()
                    ->all(),
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

            $query->orWhereNested(fn (WhereBuilder $nested) => self::applyKeyTuple(
                $nested,
                $localKeys,
                $foreignKeys,
                $parentKey,
            ));
        }

        return EagerResult::fromModels($query->get()->all());
    }

    /**
     * The parent column(s) the eager loader collects key values from.
     *
     * The FK lives on the PARENT and points at the related table's owner
     * key ({@see BelongsTo::$localKey}) — so the loader must collect the
     * parents' FK values, not their own primary keys. Overriding this is
     * what makes eager `belongsTo` correct: the base implementation would
     * collect the parents' local keys and match the wrong rows.
     *
     * @return string|list<string> The parent's FK column (or columns).
     */
    public function eagerKeyColumn(): string|array
    {
        return $this->foreignKey;
    }

    /**
     * Distribute eager results onto parents by FK value.
     *
     * A composite key matches by the full tuple, serialized to a stable
     * string key.
     *
     * @param list<Model> $parents The parents to populate.
     * @param Collection<TRelated> $results The related models.
     * @param string $name The relation name (the cache key).
     * @param list<int|string|null|list<int|string|null>>|null $eagerParentKeys
     *        Unused here — the owner key lives on each related model, so the
     *        key is re-derived from the model itself (accepted for signature
     *        parity with the through relations, which need it).
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
