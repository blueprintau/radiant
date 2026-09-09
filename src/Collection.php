<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant;

use BlueprintAU\Collections\Collection as BaseCollection;

/**
 * Model-aware subclass of the base {@see BaseCollection}.
 *
 * The base class is the external dependency (the ONLY one) — a pure array
 * wrapper with immutable transforms. Radiant adds model-shaped conveniences
 * (`find()`/`modelKeys()` need the primary key; `load()`/`fresh()` are the
 * relation-era hooks). Everything else
 * (map/filter/pluck/…) is inherited unchanged — Radiant never overrides the
 * base's semantics, it only adds model awareness.
 *
 * @template TValue of Model
 * @extends BaseCollection<int, TValue>
 * @phpstan-import-type KeyValue from \BlueprintAU\Radiant\Model
 */
final class Collection extends BaseCollection
{
    /**
     * Find a model in the collection by its primary key.
     *
     * A composite key matches by SHAPE — the same column set, compared
     * pair-wise — so map ordering never matters. A null component matches
     * only null (loose `==` would let `null` match `0` or `''`); a scalar
     * component compares cross-type (`42` matches `'42'`) because PK
     * values round-trip dialect bytes through the codec.
     *
     * @param KeyValue $key The primary-key value.
     * @return Model|null The model, or null when not present.
     */
    public function find(mixed $key): ?Model
    {
        /** @var Model|null */
        return $this->first(fn (Model $model) => self::keyMatches($model->getKeyForRefresh(), $key));
    }

    /**
     * Compare two primary-key values for a {@see Collection::find()} match.
     *
     * @param mixed $modelKey The value off the model ({@see Model::getKeyForRefresh()}).
     * @param mixed $key The value the caller is looking for.
     * @return bool True when the values identify the same row.
     */
    private static function keyMatches(mixed $modelKey, mixed $key): bool
    {
        if (is_array($modelKey)) {
            if (!is_array($key) || count($modelKey) !== count($key)) {
                return false;
            }

            foreach ($modelKey as $column => $value) {
                if (!array_key_exists($column, $key) || !self::keyMatches($value, $key[$column])) {
                    return false;
                }
            }

            return true;
        }

        if (is_array($key)) {
            return false;
        }

        if ($modelKey === null || $key === null) {
            return $modelKey === $key;
        }

        return $modelKey == $key;
    }

    /**
     * Every model's primary-key value.
     *
     * @return list<KeyValue> The key values.
     */
    public function modelKeys(): array
    {
        /** @var list<KeyValue> */
        return $this->map(fn (Model $model) => $model->getKeyForRefresh())->values()->toArray();
    }

    /**
     * Eager-load relations on every model in the collection.
     *
     * Same loader `with()` uses — one `IN` query per relation path, then
     * the relation's match() distributes results onto every model. Empty
     * collections are a no-op.
     *
     * @param string ...$relations The relation paths (dot-notation nests).
     * @return static The collection.
     * @throws \InvalidArgumentException When a path does not resolve to a
     *         relation method.
     */
    public function load(string ...$relations): static
    {
        if ($this->items === []) {
            return $this;
        }

        $query = $this->first()->newQuery();

        foreach ($relations as $path) {
            $query->loadRelationPath($this, $path);
        }

        return $this;
    }

    /**
     * Re-query every model by its key and replace the items.
     *
     * The re-query honors the model's default scope — a soft-deleted model
     * resolves to no fresh row and its item is kept as-is (removing it
     * would silently shrink a collection the caller is iterating).
     *
     * @return static The collection.
     */
    public function fresh(): static
    {
        if ($this->items === []) {
            return $this;
        }

        $models = [];

        foreach ($this->items as $model) {
            /** @var Model|null $fresh */
            $fresh = $model::find($model->getKeyForRefresh());
            $models[] = $fresh ?? $model;
        }

        /** @var list<TValue> $models */
        $this->items = $models;

        return $this;
    }
}
