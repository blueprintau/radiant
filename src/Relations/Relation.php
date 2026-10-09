<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Relations;

use BlueprintAU\Radiant\Collection;
use BlueprintAU\Radiant\Concerns\FetchesResults;
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
 * {@see Relation::get()} runs it. The FK constraint against the
 * parent's key is applied in the constructor, so any filters you add are
 * on top of it. Eager loading matches all parents' keys in one `IN` query
 * instead of joining.
 *
 * Keys are scalar by default. A relation over a composite key declares
 * both sides as column lists (`['region_id', 'country']`) — the constraint
 * compiles as per-column `=` wheres and the eager load as an OR of AND
 * groups. Scalar and composite are mutually exclusive.
 *
 * @template TRelated of Model
 * @phpstan-import-type KeyValue from \BlueprintAU\Radiant\Model
 */
abstract class Relation
{
    use FiltersQuery;

    /** @use FetchesResults<TRelated> */
    use FetchesResults;

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
     * This lets `get()` reuse an eagerly-loaded result when one
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
     * Whether an eagerly-loaded result can serve this relation's reads.
     *
     * @return bool
     */
    final protected function servesCache(): bool
    {
        return $this->name !== null && !$this->composed && $this->parent->relationLoaded($this->name);
    }

    /**
     * The eagerly-loaded result as a collection.
     *
     * @return Collection<int, TRelated>
     */
    protected function eagerCache(): Collection
    {
        if ($this->name === null) {
            return Collection::make([]);
        }

        return $this->wrapCached($this->parent->cachedRelation($this->name));
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
                . 'resolved type; read the results with get() instead.'
            );
        }

        return $this->query;
    }

    /**
     * The query the row reads run against.
     *
     * @return ModelQueryBuilder<TRelated>
     */
    protected function readQuery(): ModelQueryBuilder
    {
        return $this->getQuery();
    }

    /**
     * The related model class — the fail-fast exceptions' identity.
     *
     * @return class-string<TRelated>
     */
    protected function relatedClass(): string
    {
        return $this->related;
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
     * @param  Collection<int, TRelated>  $results
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

            // The OR-of-groups lands INSIDE one outer AND-group: the key
            // set is ONE constraint unit. The related builder auto-applies
            // trait scopes (e.g. soft-delete `deleted_at IS NULL`) as
            // leading AND-groups — flat top-level ORs would compile to
            // `(scope) OR (fk = ? AND ...) OR ...` and let a scope-excluded
            // row back in whenever its key matched. Grouped, the scope
            // ANDs against the whole set.
            return EagerResult::fromCollection($query->whereNested(
                function (WhereBuilder $nested) use ($foreignKeys, $localKeys, $parentKeys): WhereBuilder {
                    $grouped = $nested;

                    foreach ($parentKeys as $parentKey) {
                        if (!is_array($parentKey)) {
                            throw new \InvalidArgumentException(
                                'A composite relation key requires column => value key maps for eager loading; '
                                    . 'got ' . get_debug_type($parentKey) . '.'
                            );
                        }

                        $grouped = $grouped->orWhereNested(
                            fn (WhereBuilder $keyGroup): WhereBuilder => self::applyKeyTuple(
                                $keyGroup,
                                $foreignKeys,
                                $localKeys,
                                $parentKey,
                            )
                        );
                    }

                    return $grouped;
                }
            )->get());
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
     * Unlike the query builder's `get()`, this may return a cached
     * eagerly-loaded result without running SQL. Pass `fresh: true` to
     * bypass the cache and always run the query.
     *
     * @param  bool  $fresh  Bypass the eagerly-loaded result and run the query.
     * @return Collection<int, TRelated>
     */
    final public function get(bool $fresh = false): Collection
    {
        if (!$fresh && $this->servesCache()) {
            return $this->eagerCache();
        }

        return $this->executeResults();
    }

    /**
     * Wrap a cached relation value into the collection shape.
     *
     * @param  Model|Collection<int, Model>|null  $value
     * @return Collection<int, TRelated>
     */
    private function wrapCached(Model|Collection|null $value): Collection
    {
        if ($value instanceof Model) {
            /** @var Collection<int, TRelated> */
            return Collection::make([$value]);
        }

        /** @var Collection<int, TRelated> */
        return $value ?? Collection::make([]);
    }

    /**
     * Run the query and return the related models.
     *
     * @return Collection<int, TRelated>
     */
    protected function executeResults(): Collection
    {
        return $this->query->get();
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
     * Add an `EXISTS (subquery)` clause to the relation's query.
     *
     * @param  \BlueprintAU\Radiant\Database\Query\QueryBuilder  $query  The existential subquery.
     * @param  WhereBoolean  $boolean
     * @param  bool  $negated  True renders `NOT EXISTS`.
     * @return static
     */
    final public function whereExists(
        \BlueprintAU\Radiant\Database\Query\QueryBuilder $query,
        WhereBoolean $boolean = WhereBoolean::And,
        bool $negated = false,
    ): static {
        $clone = clone $this;
        $clone->composed = true;
        $clone->query = $this->compositionQuery()->whereExists($query, $boolean, $negated);

        return $clone;
    }

    /**
     * Add a `column IN (subquery)` clause to the relation's query.
     *
     * @param  string  $column  The outer column the IN constrains.
     * @param  \BlueprintAU\Radiant\Database\Query\QueryBuilder  $query  The single-column value subquery.
     * @param  WhereBoolean  $boolean
     * @param  bool  $negated  True renders `NOT IN`.
     * @return static
     */
    final public function whereInQuery(
        string $column,
        \BlueprintAU\Radiant\Database\Query\QueryBuilder $query,
        WhereBoolean $boolean = WhereBoolean::And,
        bool $negated = false,
    ): static {
        $clone = clone $this;
        $clone->composed = true;
        $clone->query = $this->compositionQuery()->whereInQuery($column, $query, $boolean, $negated);

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
