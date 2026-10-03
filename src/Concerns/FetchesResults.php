<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Concerns;

use BlueprintAU\Collections\Collection as BaseCollection;
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
     * Run the query and hydrate the first related model.
     *
     * @return TRelated|null
     */
    final public function first(): ?Model
    {
        return $this->readQuery()->first();
    }

    /**
     * Find a related model by its primary key, scoped to the relation's
     * constraint.
     *
     * @param  KeyValue  $id  The primary-key value, or a column => value map for a composite key.
     * @return TRelated|null
     */
    final public function find(int|string|null|array $id): ?Model
    {
        return $this->readQuery()->find($id);
    }

    /**
     * Find a related model by its primary key or throw if it does not
     * exist.
     *
     * @param  KeyValue  $id  The primary-key value, or a column => value map for a composite key.
     * @return TRelated
     *
     * @throws \BlueprintAU\Radiant\Database\Exceptions\ModelNotFoundException
     */
    final public function findOrFail(int|string|null|array $id): Model
    {
        return $this->readQuery()->findOrFail($id);
    }

    /**
     * Get the first related model or throw if no related models exist.
     *
     * @return TRelated
     *
     * @throws \BlueprintAU\Radiant\Database\Exceptions\ModelNotFoundException
     */
    final public function firstOrFail(): Model
    {
        return $this->readQuery()->firstOrFail();
    }

    /**
     * Require the relation to match exactly one related model.
     *
     * @return TRelated
     *
     * @throws \BlueprintAU\Radiant\Database\Exceptions\ModelNotFoundException
     * @throws \BlueprintAU\Radiant\Database\Exceptions\MultipleRecordsFoundException
     */
    final public function sole(): Model
    {
        return $this->readQuery()->sole();
    }

    /**
     * Count the related rows matching the relation's constraint.
     *
     * @return int
     */
    final public function count(): int
    {
        return $this->readQuery()->count();
    }

    /**
     * Whether any related row matches the relation's constraint.
     *
     * @return bool
     */
    final public function exists(): bool
    {
        return $this->readQuery()->exists();
    }

    /**
     * Stream the related models, hydrating each row as it arrives.
     *
     * @return \Generator<int, TRelated>
     */
    final public function cursor(): \Generator
    {
        return $this->readQuery()->cursor();
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
