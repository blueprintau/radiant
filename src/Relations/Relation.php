<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Relations;

use BlueprintAU\Collections\Collection as BaseCollection;
use BlueprintAU\Radiant\Collection;
use BlueprintAU\Radiant\Concerns\FiltersQuery;
use BlueprintAU\Radiant\Database\Query\Aggregate;
use BlueprintAU\Radiant\Database\Query\Expression;
use BlueprintAU\Radiant\Database\Query\WhereBuilder;
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\ModelQueryBuilder;
use BlueprintAU\Radiant\Database\Query\Enums\SortDirection;
use BlueprintAU\Radiant\Database\Query\Enums\WhereBoolean;
use BlueprintAU\Radiant\Database\Query\Enums\WhereOperator;

/**
 * A relation between two models.
 *
 * A relation is a lazily-executed query: constructing it runs nothing;
 * {@see Relation::getResults()} runs it. The FK constraint against the
 * parent's key is applied in the constructor, so any filters you add are
 * on top of it.
 *
 * Eager loading runs the same FK match as an `IN` over many parents' keys
 * once, then {@see Relation::match()} distributes the results back onto
 * each parent — no joins, no row multiplication.
 *
 * Keys are scalar by default. A relation over a composite key declares
 * BOTH sides as column lists (`['region_id', 'country']`) — the constraint
 * compiles as per-column `=` wheres and the eager load as an OR of AND
 * groups. Scalar and composite are mutually exclusive.
 *
 * **Relations are immutable.** Every filter and configurator returns a
 * NEW relation — the original is never modified, and a discarded call is
 * a no-op.
 *
 * @template TRelated of Model
 * @phpstan-import-type KeyValue from \BlueprintAU\Radiant\Model
 */
abstract class Relation
{
    use FiltersQuery;

    /**
     * The maximum number of parent keys per eager-load query.
     *
     * Databases cap how many values a single query can hold. A larger
     * load simply runs a few queries instead of failing.
     */
    protected const EAGER_KEY_CHUNK = 500;

    /**
     * The query builder for the related model.
     *
     * @var ModelQueryBuilder<TRelated>
     */
    protected ModelQueryBuilder $query;

    /**
     * The relation method name this relation was built from.
     *
     * @var string|null
     */
    private ?string $name = null;

    /**
     * Whether a filter has been added to this relation.
     *
     * @var bool
     */
    private bool $composed = false;

    /**
     * Create a relation.
     *
     * @param  Model  $parent
     * @param  class-string<TRelated>  $related
     * @param  string|list<string>  $foreignKey
     * @param  string|list<string>  $localKey
     * @throws \InvalidArgumentException
     */
    public function __construct(
        protected readonly Model $parent,
        protected readonly string $related,
        protected readonly string|array $foreignKey,
        protected readonly string|array $localKey,
    ) {
        if (is_array($foreignKey) !== is_array($localKey)) {
            throw new \InvalidArgumentException(
                "A relation's foreign key and local key must be BOTH single columns or BOTH "
                    . "composite column lists; got one of each on [{$related}]."
            );
        }

        if ($foreignKey === [] || $localKey === []) {
            throw new \InvalidArgumentException(
                'A composite relation key requires at least one column; got an empty list.'
            );
        }

        if (is_array($foreignKey) && is_array($localKey) && count($foreignKey) !== count($localKey)) {
            throw new \InvalidArgumentException(
                "A composite relation key's foreign and local columns must have matching "
                    . 'arity; got ' . count($foreignKey) . ' and ' . count($localKey) . '.'
            );
        }

        // MorphTo resolves its related model per row, so it has no query
        // to build here — it handles that itself, lazily.
        if ($this->defersConstraints()) {
            return;
        }

        $this->query = $this->related::newQuery();
        $this->addConstraints();
    }

    /**
     * Whether the related model is resolved per row (MorphTo) rather than
     * fixed at construction.
     *
     * @return bool
     */
    protected function defersConstraints(): bool
    {
        return false;
    }

    /**
     * Name this relation after the model method that created it.
     *
     * This lets `getResults()` reuse an eagerly-loaded result when one
     * exists. Passing null does nothing.
     *
     * @param  string|null  $name
     * @return static
     */
    final public function withName(?string $name): static
    {
        if ($name === null) {
            return $this;
        }

        $clone = clone $this;
        $clone->name = $name;

        return $clone;
    }

    /**
     * Return a copy of this relation marked as modified.
     *
     * Used by configurators like {@see BelongsToMany::withPivot()} that
     * change what a fresh read returns without adding a where clause. A
     * modified relation never reuses an eagerly-loaded result.
     *
     * @return static
     */
    protected function markComposed(): static
    {
        $clone = clone $this;
        $clone->composed = true;

        return $clone;
    }

    /**
     * The query builder that filters are added to.
     *
     * @return ModelQueryBuilder<TRelated>
     */
    protected function compositionQuery(): ModelQueryBuilder
    {
        if (!isset($this->query)) {
            throw new \LogicException(
                static::class . ' cannot compose filters — its query is built lazily per '
                . 'resolved type; read the results with getResults() instead.'
            );
        }

        return $this->query;
    }

    /**
     * Apply the relation's FK constraint to the query.
     *
     * Called once from the constructor.
     *
     * @return void
     */
    abstract protected function addConstraints(): void;

    /**
     * Distribute eagerly-loaded results onto their parents.
     *
     * @param  list<Model>  $parents
     * @param  Collection<TRelated>  $results
     * @param  string  $name
     * @param  list<int|string|null|list<int|string|null>>|null  $eagerParentKeys
     * @return void
     */
    abstract public function match(array $parents, Collection $results, string $name, ?array $eagerParentKeys = null): void;

    /**
     * Run the eager query for many parents at once.
     *
     * Instead of one query per parent, this fetches everything in a
     * single `IN (...)` query and lets {@see match()} hand each parent its
     * own results. Very large key lists are split into batches of
     * {@see EAGER_KEY_CHUNK} so no database limit is ever hit.
     *
     * @param  list<KeyValue>  $parentKeys
     * @return EagerResult<TRelated>
     */
    public function eagerLoad(array $parentKeys): EagerResult
    {
        if ($parentKeys === []) {
            return EagerResult::fromModels([]);
        }

        $models = [];
        $parentKeysOut = null;

        foreach (array_chunk($parentKeys, self::EAGER_KEY_CHUNK) as $chunk) {
            $chunkResult = $this->eagerLoadChunk($chunk);
            array_push($models, ...$chunkResult->models->all());

            if ($chunkResult->parentKeys !== null) {
                $parentKeysOut ??= [];
                array_push($parentKeysOut, ...$chunkResult->parentKeys);
            }
        }

        return new EagerResult(EagerResult::listToCollection($models), $parentKeysOut);
    }

    /**
     * Run one eager-load query for a batch of parent keys.
     *
     * @param  list<KeyValue>  $parentKeys
     * @return EagerResult<TRelated>
     */
    protected function eagerLoadChunk(array $parentKeys): EagerResult
    {
        $query = $this->related::newQuery();

        $query = $this->applyEagerOrdering($query);

        if ($this->isComposite()) {
            $foreignKeys = $this->getForeignKeys();
            $localKeys = $this->getLocalKeys();

            foreach ($parentKeys as $parentKey) {
                if (!is_array($parentKey)) {
                    throw new \InvalidArgumentException(
                        'A composite relation key requires column => value key maps for eager loading; '
                            . 'got ' . get_debug_type($parentKey) . '.'
                    );
                }

                $query = $query->orWhereNested(
                    fn(WhereBuilder $nested): WhereBuilder => self::applyKeyTuple(
                        $nested,
                        $foreignKeys,
                        $localKeys,
                        $parentKey,
                    )
                );
            }

            return EagerResult::fromCollection($query->get());
        }

        return EagerResult::fromCollection($query->whereIn($this->getForeignKey(), $parentKeys)->get());
    }

    /**
     * Apply the eager query's ordering.
     *
     * One-to-one relations override this to order by the related model's
     * primary key, so "take the first match" always picks the same row.
     *
     * @param  ModelQueryBuilder<TRelated>  $query
     * @return ModelQueryBuilder<TRelated>
     */
    protected function applyEagerOrdering(ModelQueryBuilder $query): ModelQueryBuilder
    {
        // No default ordering.
        return $query;
    }

    /**
     * Get the related models — reusing an eagerly-loaded result when one
     * applies.
     *
     * The loaded result is reused only when the relation was named, no
     * filter has been added, and the parent actually has the relation
     * loaded. Everything else runs a fresh query.
     *
     * @return Collection<TRelated>
     */
    final public function getResults(): Collection
    {
        if ($this->name !== null && !$this->composed && $this->parent->relationLoaded($this->name)) {
            return self::wrapCached($this->parent->cachedRelation($this->name));
        }

        return $this->executeResults();
    }

    /**
     * Wrap a cached relation value into the collection shape.
     *
     * @param  Model|Collection<Model>|null  $value
     * @return Collection<TRelated>
     */
    private function wrapCached(Model|Collection|null $value): Collection
    {
        if ($value instanceof Model) {
            /** @var Collection<TRelated> */
            return Collection::make([$value]);
        }

        /** @var Collection<TRelated> */
        return $value ?? Collection::make([]);
    }

    /**
     * Run the query and return the related models.
     *
     * @return Collection<TRelated>
     */
    protected function executeResults(): Collection
    {
        return $this->query->get();
    }

    /**
     * Get the first related model or throw if no related models exist.
     *
     * @return TRelated
n     *
     * @throws \BlueprintAU\Radiant\Database\Exceptions\ModelNotFoundException
     */
    final public function firstOrFail(): Model
    {
        return $this->getQuery()->firstOrFail();
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
        return $this->getQuery()->sole();
    }

    /**
     * The underlying query builder for the related model.
     *
     * @return ModelQueryBuilder<TRelated>
     */
    final public function getQuery(): ModelQueryBuilder
    {
        return $this->query;
    }

    /**
     * Add a where clause to the relation's query.
     *
     * @param  string|Expression  $column
     * @param  WhereOperator|string  $operator
     * @param  mixed  $value
     * @param  WhereBoolean  $boolean
     * @return static
     */
    final public function where(
        string|Expression $column,
        WhereOperator|string $operator,
        mixed $value,
        WhereBoolean $boolean = WhereBoolean::And,
    ): static {
        $clone = clone $this;
        $clone->composed = true;
        $clone->query = $this->compositionQuery()->where($column, $operator, $value, $boolean);

        return $clone;
    }

    /**
     * Add a nested (parenthesized) where group to the relation's query.
     *
     * The callback receives the group's builder and must return it.
     *
     * @param  callable(\BlueprintAU\Radiant\Database\Query\WhereBuilder): \BlueprintAU\Radiant\Database\Query\WhereBuilder  $callback
     * @param  WhereBoolean  $boolean
     * @return static
     */
    final public function whereNested(
        callable $callback,
        WhereBoolean $boolean = WhereBoolean::And,
    ): static {
        $clone = clone $this;
        $clone->composed = true;
        $clone->query = $this->compositionQuery()->whereNested($callback, $boolean);

        return $clone;
    }

    /**
     * Add an "order by" clause to the relation's query.
     *
     * @param  string|Expression  $column
     * @param  SortDirection|string  $direction
     * @return static
     */
    final public function orderBy(string|Expression $column, SortDirection|string $direction = SortDirection::Asc): static
    {
        $clone = clone $this;
        $clone->composed = true;
        $clone->query = $this->compositionQuery()->orderBy($column, $direction);

        return $clone;
    }

    /**
     * Set the "limit" value of the relation's query.
     *
     * @param  int  $limit
     * @return static
     */
    final public function limit(int $limit): static
    {
        $clone = clone $this;
        $clone->composed = true;
        $clone->query = $this->compositionQuery()->limit($limit);

        return $clone;
    }

    /**
     * Set the "offset" value of the relation's query.
     *
     * @param  int  $offset
     * @return static
     */
    final public function offset(int $offset): static
    {
        $clone = clone $this;
        $clone->composed = true;
        $clone->query = $this->compositionQuery()->offset($offset);

        return $clone;
    }

    /**
     * Set the columns to be selected.
     *
     * @param  string|Expression|Aggregate  ...$columns
     * @return static
     */
    final public function select(string|Expression|Aggregate ...$columns): static
    {
        // No args → the default `['*']` select.
        $clone = clone $this;
        $clone->composed = true;
        $clone->query = $this->compositionQuery()->select(...$columns);

        return $clone;
    }

    /**
     * Add a "group by" clause to the relation's query.
     *
     * @param  string|array<int, string>  $columns
     * @return static
     */
    final public function groupBy(string|array $columns): static
    {
        $clone = clone $this;
        $clone->composed = true;
        $clone->query = $this->compositionQuery()->groupBy($columns);

        return $clone;
    }

    /**
     * Add a "having" clause to the relation's query.
     *
     * @param  string|Expression|Aggregate  $column
     * @param  WhereOperator|string  $operator
     * @param  mixed  $value
     * @return static
     */
    final public function having(string|Expression|Aggregate $column, WhereOperator|string $operator, mixed $value): static
    {
        $clone = clone $this;
        $clone->composed = true;
        $clone->query = $this->compositionQuery()->having($column, $operator, $value);

        return $clone;
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
     * a guaranteed-numeric grouped count, use {@see Relation::countBy()}.
     *
     * @param  Aggregate  $aggregate  The aggregate to compute per group.
     * @param  string  $groupBy  The column whose values key the result.
     * @return BaseCollection<string, mixed>
     * @throws \LogicException  On a relation whose query is built lazily (MorphTo).
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
     * @param  string  $column  The column whose values key the result.
     * @param  list<int|string>|null  $seed  Group values guaranteed to appear (0 when absent).
     * @return BaseCollection<string, int>
     * @throws \LogicException  On a relation whose query is built lazily (MorphTo).
     */
    final public function countBy(string $column, ?array $seed = null): BaseCollection
    {
        return $this->compositionQuery()->countBy($column, $seed);
    }

    /**
     * The related model class.
     *
     * @return class-string<TRelated>
     */
    final public function getRelated(): string
    {
        return $this->related;
    }

    /**
     * The related classes a dotted eager-load path's deeper segments
     * resolve against.
     *
     * An empty list means the related model varies per row (MorphTo).
     *
     * @return list<class-string<Model>>
     */
    public function relatedClasses(): array
    {
        return [$this->related];
    }

    /**
     * The scalar form of a relation key.
     *
     * @return string
     * @throws \LogicException
     */
    final public function getForeignKey(): string
    {
        return is_string($this->foreignKey)
            ? $this->foreignKey
            : throw new \LogicException(
                'This relation uses a composite foreign key; call getForeignKeys() instead.'
            );
    }

    /**
     * The single parent-side key column.
     *
     * @return string
     * @throws \LogicException
     */
    final public function getLocalKey(): string
    {
        return is_string($this->localKey)
            ? $this->localKey
            : throw new \LogicException(
                'This relation uses a composite local key; call getLocalKeys() instead.'
            );
    }

    /**
     * The composite foreign key columns.
     *
     * @return list<string>
     * @throws \LogicException
     */
    final public function getForeignKeys(): array
    {
        return is_array($this->foreignKey)
            ? $this->foreignKey
            : throw new \LogicException(
                'This relation uses a single foreign key; call getForeignKey() instead.'
            );
    }

    /**
     * The composite parent-side key columns.
     *
     * @return list<string>
     * @throws \LogicException
     */
    final public function getLocalKeys(): array
    {
        return is_array($this->localKey)
            ? $this->localKey
            : throw new \LogicException(
                'This relation uses a single local key; call getLocalKey() instead.'
            );
    }

    /**
     * Whether the relation is keyed by a composite key.
     *
     * @return bool
     */
    final public function isComposite(): bool
    {
        return is_array($this->foreignKey);
    }

    /**
     * The parent column(s) the eager loader collects key values from.
     *
     * @return string|list<string>
     */
    public function eagerKeyColumn(): string|array
    {
        return $this->localKey;
    }

    /**
     * Apply one composite key match to a builder.
     *
     * Every FK column must equal the corresponding parent value; a null
     * component becomes IS NULL (SQL `= NULL` never matches).
     *
     * @param  WhereBuilder  $query
     * @param  list<string>  $foreignKeys
     * @param  list<string>  $localKeys
     * @param  array<string, int|string|null>  $values
     * @return WhereBuilder
     */
    final protected static function applyKeyTuple(
        WhereBuilder $query,
        array $foreignKeys,
        array $localKeys,
        array $values,
    ): WhereBuilder {
        foreach ($foreignKeys as $i => $foreignKey) {
            $value = $values[$localKeys[$i]] ?? null;
            $query = $query->where($foreignKey, $value === null ? WhereOperator::Null : WhereOperator::Eq, $value);
        }

        return $query;
    }

    /**
     * Collect the parent's key tuple as a column => value map.
     *
     * @param  list<string>  $localKeys
     * @return array<string, int|string|null>
     */
    final protected function parentKeyValues(array $localKeys): array
    {
        $values = [];

        foreach ($localKeys as $localKey) {
            $values[$localKey] = $this->parent->attribute($localKey);
        }

        return $values;
    }

    /**
     * Read a model's composite key tuple as a positional value list.
     *
     * Matching is position-based: the related side's values are keyed by
     * FK column names while the parent's are keyed by local column names,
     * so name-based comparison would never match.
     *
     * @param  Model  $model
     * @param  list<string>  $columns
     * @return list<int|string|null>
     */
    final protected static function tupleValues(Model $model, array $columns): array
    {
        $values = [];

        foreach ($columns as $column) {
            $values[] = $model->attribute($column);
        }

        return $values;
    }

    /**
     * Serialize a key value to a stable string for array indexing.
     *
     * Scalars and column => value maps are the {@see KeyValue} shapes;
     * through relations serialize POSITIONAL key tuples as well.
     *
     * @param  KeyValue|list<int|string|null>  $key
     * @return string
     *
     * @throws \JsonException
     */
    final protected static function serializeKey(int|string|null|array $key): string
    {
        if (!is_array($key)) {
            return (string) $key;
        }

        return json_encode($key, JSON_THROW_ON_ERROR);
    }
}
