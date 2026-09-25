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
     * A composite key matches by shape — the same column set, compared
     * pair-wise — so map ordering never matters.
     *
     * @param  KeyValue  $key
     * @return Model|null
     */
    public function find(int|string|null|array $key): ?Model
    {
        /** @var Model|null */
        return $this->first(fn (Model $model) => self::keyMatches($model->getKeyForRefresh(), $key));
    }

    /**
     * Compare two primary-key values for a {@see Collection::find()} match.
     *
     * @param  KeyValue  $modelKey
     * @param  KeyValue  $key
     * @return bool
     */
    private static function keyMatches(int|string|null|array $modelKey, int|string|null|array $key): bool
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
     * collapse to int, everything else passes through.
     *
     * @param  int|string|null  $value
     * @return int|string|null
     */
    private static function normalizeKey(int|string|null $value): int|string|null
    {
        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        return $value;
    }

    /**
     * Every model's primary-key value.
     *
     * @return list<KeyValue>
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
     * @param  string  ...$relations
     * @return static
     * @throws \InvalidArgumentException
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
     * ONE query, not N: the keys go into a single `whereKey(...)` and the
     * re-hydrated rows are re-attached to the collection's original
     * positions by serialized key — a row deleted externally leaves its
     * original model in place. Registered eager loads are not re-applied.
     *
     * @return static
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

        $query = $query->whereKey($keys);

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
     * composite maps JSON-encode.
     *
     * @param  KeyValue  $key
     * @return string
     * @throws \JsonException
     */
    private static function serializeKeyValue(int|string|null|array $key): string
    {
        return is_array($key) ? json_encode($key, JSON_THROW_ON_ERROR) : (string) $key;
    }
}
