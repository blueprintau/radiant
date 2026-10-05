<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Concerns;

use BlueprintAU\Collections\Collection as BaseCollection;
use BlueprintAU\Radiant\Collection;
use BlueprintAU\Radiant\Database\Exceptions\ModelNotFoundException;
use BlueprintAU\Radiant\Database\Exceptions\MultipleRecordsFoundException;
use BlueprintAU\Radiant\Database\Query\Aggregate;
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\ModelQueryBuilder;

/**
 * The shared read vocabulary for a wrapper over a model query builder —
 * the row reads, the scalar reads, and the grouped aggregates.
 *
 * @template TRelated of Model
 * @phpstan-import-type KeyValue from \BlueprintAU\Radiant\Model
 */
trait FetchesResults
{
    /**
     * The query the row reads run against.
     *
     * @return ModelQueryBuilder<TRelated>
     */
    abstract protected function readQuery(): ModelQueryBuilder;

    /**
     * The query the scalar reads run against.
     *
     * A wrapper whose query is built lazily per row (MorphTo) throws
     * here — the scalar reads have no stable table to target.
     *
     * @return ModelQueryBuilder<TRelated>
     */
    abstract protected function compositionQuery(): ModelQueryBuilder;

    /**
     * The eagerly-loaded result — the row reads' cache path.
     *
     * @return Collection<TRelated>
     */
    abstract protected function eagerCache(): Collection;

    /**
     * Whether the eagerly-loaded result can serve the row reads.
     *
     * @return bool
     */
    abstract protected function servesCache(): bool;

    /**
     * The related model class — the fail-fast exceptions' identity.
     *
     * @return class-string<TRelated>
     */
    abstract protected function relatedClass(): string;

    /**
     * Run the query and hydrate the first related model.
     *
     * Served from an eagerly-loaded result when one applies — the
     * relation was named, no filter composed, and the parent carries the
     * relation loaded. Pass `fresh: true` to always run the query.
     *
     * @param  bool  $fresh  Bypass the eagerly-loaded result and run the query.
     * @return TRelated|null
     */
    final public function first(bool $fresh = false): ?Model
    {
        if (!$fresh && $this->servesCache()) {
            /** @var TRelated|null */
            return $this->eagerCache()->first();
        }

        return $this->readQuery()->first();
    }

    /**
     * Find a related model by its primary key, scoped to the relation's
     * constraint.
     *
     * Served from an eagerly-loaded result when one applies (the lookup
     * is then membership of the loaded set). Pass `fresh: true` to
     * always run the query.
     *
     * @param  KeyValue  $id  The primary-key value, or a column => value map for a composite key.
     * @param  bool  $fresh  Bypass the eagerly-loaded result and run the query.
     * @return TRelated|null
     */
    final public function find(int|string|null|array $id, bool $fresh = false): ?Model
    {
        if (!$fresh && $this->servesCache()) {
            /** @var TRelated|null */
            return $this->eagerCache()->find($id);
        }

        return $this->readQuery()->find($id);
    }

    /**
     * Find a related model by its primary key or throw if it does not
     * exist.
     *
     * Served from an eagerly-loaded result when one applies — a miss is
     * then the loaded set holding no match. Pass `fresh: true` to
     * always run the query.
     *
     * @param  KeyValue  $id  The primary-key value, or a column => value map for a composite key.
     * @param  bool  $fresh  Bypass the eagerly-loaded result and run the query.
     * @return TRelated
     *
     * @throws \BlueprintAU\Radiant\Database\Exceptions\ModelNotFoundException
     */
    final public function findOrFail(int|string|null|array $id, bool $fresh = false): Model
    {
        $model = $this->find($id, $fresh);

        if ($model === null) {
            throw new ModelNotFoundException($this->relatedClass(), $id);
        }

        return $model;
    }

    /**
     * Get the first related model or throw if no related models exist.
     *
     * Served from an eagerly-loaded result when one applies — a miss is
     * then the loaded set being empty. Pass `fresh: true` to always run
     * the query.
     *
     * @param  bool  $fresh  Bypass the eagerly-loaded result and run the query.
     * @return TRelated
     *
     * @throws \BlueprintAU\Radiant\Database\Exceptions\ModelNotFoundException
     */
    final public function firstOrFail(bool $fresh = false): Model
    {
        $model = $this->first($fresh);

        if ($model === null) {
            throw new ModelNotFoundException($this->relatedClass());
        }

        return $model;
    }

    /**
     * Require the relation to match exactly one related model.
     *
     * Served from an eagerly-loaded result when one applies — the loaded
     * set's size decides (zero throws, more than one throws). Pass
     * `fresh: true` to always run the query.
     *
     * @param  bool  $fresh  Bypass the eagerly-loaded result and run the query.
     * @return TRelated
     *
     * @throws \BlueprintAU\Radiant\Database\Exceptions\ModelNotFoundException
     * @throws \BlueprintAU\Radiant\Database\Exceptions\MultipleRecordsFoundException
     */
    final public function sole(bool $fresh = false): Model
    {
        if (!$fresh && $this->servesCache()) {
            $models = $this->eagerCache();
            $count = $models->count();

            if ($count === 0) {
                throw new ModelNotFoundException($this->relatedClass());
            }

            if ($count > 1) {
                throw new MultipleRecordsFoundException($count, $this->relatedClass());
            }

            /** @var TRelated */
            return $models->first();
        }

        return $this->readQuery()->sole();
    }

    /**
     * Count the related rows matching the relation's constraint.
     *
     * Served from an eagerly-loaded result when one applies — the count
     * is then the loaded set's size (a snapshot). Pass `fresh: true` to
     * always run the query.
     *
     * @param  bool  $fresh  Bypass the eagerly-loaded result and run the query.
     * @return int
     */
    final public function count(bool $fresh = false): int
    {
        if (!$fresh && $this->servesCache()) {
            return $this->eagerCache()->count();
        }

        return $this->readQuery()->count();
    }

    /**
     * Whether any related row matches the relation's constraint.
     *
     * Served from an eagerly-loaded result when one applies. Pass
     * `fresh: true` to always run the query.
     *
     * @param  bool  $fresh  Bypass the eagerly-loaded result and run the query.
     * @return bool
     */
    final public function exists(bool $fresh = false): bool
    {
        if (!$fresh && $this->servesCache()) {
            return $this->eagerCache()->count() !== 0;
        }

        return $this->readQuery()->exists();
    }

    /**
     * Stream the related models, hydrating each row as it arrives.
     *
     * Served from an eagerly-loaded result when one applies. Pass
     * `fresh: true` to always run the query.
     *
     * @param  bool  $fresh  Bypass the eagerly-loaded result and run the query.
     * @return \Generator<int, TRelated>
     */
    final public function cursor(bool $fresh = false): \Generator
    {
        if (!$fresh && $this->servesCache()) {
            yield from $this->eagerCache();

            return;
        }

        // A body holding yield makes this a generator — the live path
        // forwards the builder's stream item by item (a plain return
        // would terminate this generator empty).
        yield from $this->readQuery()->cursor();
    }

    /**
     * The value of a single column from the first related row, decoded
     * through the column's cast.
     *
     * @param  string|Aggregate  $column
     * @return mixed
     */
    final public function value(string|Aggregate $column): mixed
    {
        return $this->compositionQuery()->value($column);
    }

    /**
     * A collection of a single column's values, decoded through the casts.
     *
     * @param  string  $column
     * @return BaseCollection<int, mixed>
     */
    final public function pluck(string $column): BaseCollection
    {
        return $this->compositionQuery()->pluck($column);
    }

    /**
     * The maximum value of a column, decoded through the cast for
     * declared columns.
     *
     * @param  string  $column
     * @return mixed
     */
    final public function max(string $column): mixed
    {
        return $this->compositionQuery()->max($column);
    }

    /**
     * The minimum value of a column, decoded through the cast for
     * declared columns.
     *
     * @param  string  $column
     * @return mixed
     */
    final public function min(string $column): mixed
    {
        return $this->compositionQuery()->min($column);
    }

    /**
     * The sum of a column's values, decoded through the cast for
     * declared columns.
     *
     * @param  string  $column
     * @return mixed
     */
    final public function sum(string $column): mixed
    {
        return $this->compositionQuery()->sum($column);
    }

    /**
     * The average of a column's values, decoded through the cast for
     * declared columns.
     *
     * @param  string  $column
     * @return mixed
     */
    final public function avg(string $column): mixed
    {
        return $this->compositionQuery()->avg($column);
    }

    /**
     * Multiple aggregates in one query, decoded through each aggregate's
     * column cast.
     *
     * @param  Aggregate  ...$aggregates
     * @return \stdClass
     */
    final public function aggregates(Aggregate ...$aggregates): \stdClass
    {
        return $this->compositionQuery()->aggregates(...$aggregates);
    }

    /**
     * Run one aggregate per group of the related rows — a grouped
     * aggregate in a single query.
     *
     * The FK constraint rides along automatically: the groups only ever
     * cover THIS parent's related rows. The result is keyed by the group
     * column's value, so the aggregate's own alias is ignored here (it
     * matters only for the multi-aggregate row shape of the builder's
     * aggregates()).
     *
     * The value type follows the aggregate: `count` yields int;
     * `sum`/`avg` over numeric columns yield int|float; `min`/`max` yield
     * the column's decoded type (a datetime column yields Carbon); custom
     * functions and Expression arguments yield the raw driver value. For
     * a guaranteed-numeric grouped count, use {@see self::countBy()}.
     *
     * @param  Aggregate  $aggregate
     * @param  string  $groupBy
     * @return BaseCollection<string, mixed>
     *
     * @throws \LogicException
     */
    final public function aggregateBy(Aggregate $aggregate, string $groupBy): BaseCollection
    {
        return $this->compositionQuery()->aggregateBy($aggregate, $groupBy);
    }

    /**
     * Count the related rows per group of a column — in a single query.
     *
     * The FK constraint rides along automatically: the counts only ever
     * cover THIS parent's related rows. The result is keyed by the group
     * column's value with int counts.
     *
     * The optional seed lists group values that must appear even when the
     * database has no rows for them — each seeded key absent from the
     * result becomes 0. The seed is ADDITIVE: database rows always win,
     * and group values found in the data but missing from the seed still
     * appear. (Only counts can be seeded — an absent group has no honest
     * min, max, or average.)
     *
     * @param  string  $column
     * @param  list<int|string>|null  $seed  Group values guaranteed to appear (0 when absent).
     * @return BaseCollection<string, int>
     *
     * @throws \LogicException
     */
    final public function countBy(string $column, ?array $seed = null): BaseCollection
    {
        return $this->compositionQuery()->countBy($column, $seed);
    }
}
