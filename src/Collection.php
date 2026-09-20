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

        // Strict after int/numeric-string normalization. PK values
        // round-trip dialect bytes through the codec, so `42` must match
        // `'42'` — but plain `==` over-matched: `find(0)` matched the key
        // `'0e1'` (scientific notation, `== 0`) and `true` matched `'1'`.
        // Both sides normalize numeric strings to int before a strict
        // compare; non-numeric scalars compare strictly as-is.
        return self::normalizeKey($modelKey) === self::normalizeKey($key);
    }

    /**
     * Normalize a scalar PK value for strict comparison — numeric strings
     * collapse to int (canonical), everything else passes through.
     *
     * @param mixed $value The scalar key value.
     * @return mixed The normalized value.
     */
    private static function normalizeKey(mixed $value): mixed
    {
        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        return $value;
    }

    /**
     * Every model's primary-key value.
     *
     * @return list<KeyValue> The key values.
     */
    public function modelKeys(): array
    {
        $keys = [];

        foreach ($this->items as $model) {
            $keys[] = $model->getKeyForRefresh();
        }

        /** @var list<KeyValue> */
        return $keys;
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

        /** @var TValue $first */
        $first = $this->first();

        $query = $first->newQuery();

        foreach ($relations as $path) {
            $query->loadRelationPath($this, $path);
        }

        return $this;
    }

    /**
     * Re-query every model by its key and replace the items.
     *
     * ONE query, not N: the keys go into a single `whereKey(...)` on the
     * first model's builder, and the re-hydrated rows are re-attached to
     * the collection's original positions by serialized key — a row that
     * was deleted externally leaves its ORIGINAL model in place (removing
     * it would silently shrink a collection the caller is iterating),
     * preserving the documented staleness contract while eliminating the
     * per-model round trip (an N+1 storm beyond a few dozen items).
     *
     * Registered eager loads are not re-applied — the fresh rows are
     * plain hydrations; call `load()` again if relations are needed.
     *
     * Composite keys re-query via the same builder path (`whereKey`
     * accepts the full key map) and re-attach by serialized tuple.
     *
     * @return static The collection.
     */
    public function fresh(): static
    {
        if ($this->items === []) {
            return $this;
        }

        /** @var TValue $first */
        $first = $this->first();

        $query = $first->newQuery()->withTrashed();

        /** @var list<KeyValue> $keys */
        $keys = [];

        foreach ($this->items as $model) {
            $keys[] = $model->getKeyForRefresh();
        }

        $query->whereKey($keys);

        $freshBySerializedKey = [];

        foreach ($query->get() as $fresh) {
            $freshBySerializedKey[self::serializeKeyValue($fresh->getKeyForRefresh())] = $fresh;
        }

        $models = [];

        foreach ($this->items as $model) {
            $serialized = self::serializeKeyValue($model->getKeyForRefresh());
            $models[] = $freshBySerializedKey[$serialized] ?? $model;
        }

        /** @var list<TValue> $models */
        $this->items = $models;

        return $this;
    }

    /**
     * Serialize a key value to a stable string — scalars stringify;
     * composite maps JSON-encode (order-stable per the shape contract).
     *
     * @param KeyValue $key The key value.
     * @return string The serialized key.
     * @throws \JsonException When a composite key cannot be encoded.
     */
    private static function serializeKeyValue(mixed $key): string
    {
        return is_array($key) ? json_encode($key, JSON_THROW_ON_ERROR) : (string) $key;
    }
}
