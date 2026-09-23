<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Relations;

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
 * A relation between two models — the query plus the stitching rules.
 *
 * A relation is a lazily-executed query: constructing it runs nothing;
 * {@see Relation::getResults()} materializes it. The constraint (the FK
 * match against the parent's key) is applied to the relation's builder in
 * the constructor, so the builder composes like any other —
 * `$user->posts()->getQuery()->orderBy(...)` keeps the constraint AND adds
 * to it.
 *
 * Eager loading shares the machinery: the loader runs the same FK match as
 * an `IN` over many parents' keys once, then {@see Relation::match()}
 * distributes the results back onto each parent by FK value. No JOINs, no
 * row multiplication — pagination stays correct.
 *
 * HasOne/HasMany/BelongsTo ride the portable core (`whereIn` + `select`
 * work on every backend, CSV included). Through relations need joins and
 * are SQL-only at execution.
 *
 * Keys are scalar by default. A relation over a composite key declares
 * BOTH sides as column lists (`['region_id', 'country']`) — the constraint
 * compiles as per-column `=` wheres and the eager load as an OR of AND
 * groups. Tuple `IN` (`(a, b) IN ((?, ?), ...)`) is deliberately avoided:
 * support and placeholder semantics differ per dialect, so the portable
 * shape wins. Scalar and composite are mutually exclusive — one side
 * cannot be a list while the other is a single column.
 *
 * **Relations are immutable.** Every filter (`where()`, `orderBy()`,
 * `limit()`, ...) and every configurator (`withName()`, `withPivot()`,
 * ...) returns a NEW relation — the original is never modified. A
 * discarded call is a no-op, and a composed chain can never affect the
 * shared cached relation (the cache holds the un-composed original;
 * composition happens on copies).
 *
 * @template TRelated of Model
 * @phpstan-import-type KeyValue from \BlueprintAU\Radiant\Model
 */
abstract class Relation
{
    use FiltersQuery;

    /**
     * How many parent keys to include in one eager-load query.
     *
     * Databases cap how many values a single query can hold (SQLite
     * allows 999, MySQL limits total query size). If a load needs more
     * keys than this, it simply runs a few queries instead of failing.
     */
    protected const EAGER_KEY_CHUNK = 500;

    /**
     * The constrained builder on the related model.
     *
     * @var ModelQueryBuilder<TRelated>
     */
    protected ModelQueryBuilder $query;

    /**
     * The relation-method name this relation was built from (e.g. `posts`),
     * used to find eagerly-loaded results on the parent. Null when the
     * relation was created outside a relation method — in that case every
     * read runs a fresh query.
     *
     * @var string|null
     */
    private ?string $name = null;

    /**
     * Whether a filter has been added to this relation. A filtered
     * relation always runs a fresh query — the eagerly-loaded result was
     * fetched WITHOUT the filter, so serving it would silently ignore
     * what you asked for.
     *
     * @var bool
     */
    private bool $composed = false;

    /**
     * Create a relation.
     *
     * @param Model $parent The model owning the relation.
     * @param class-string<TRelated> $related The related model class. For
     *        the DYNAMIC-related {@see MorphTo} the abstract
     *        {@see Model::class} marker passes through a documented
     *        narrowing in its constructor — no fixed class exists, the
     *        real one resolves per row, and MorphTo's own template (bound
     *        to its allowlist) carries the static type.
     * @param string|list<string> $foreignKey The FK column carrying the
     *        link — or the composite column list.
     * @param string|list<string> $localKey The parent-side key column —
     *        or the composite column list (same shape as `$foreignKey`).
     * @throws \InvalidArgumentException When one side is composite and the
     *         other is not, a composite list is empty, or the lists'
     *         arities differ.
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
     * @return bool True to skip building the query in the constructor.
     */
    protected function defersConstraints(): bool
    {
        return false;
    }

    /**
     * Name this relation after the model method that created it.
     *
     * This lets `getResults()` reuse an eagerly-loaded result when one
     * exists. Passing null does nothing — it will not remove an existing
     * name.
     *
     * @param string|null $name The relation method's name.
     * @return static A NEW relation with the name set; the same instance
     *         when $name is null.
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
     * Return a copy of this relation marked as "modified" — used by
     * configurators like {@see BelongsToMany::withPivot()} that change what
     * a fresh read returns without adding a where clause. A modified
     * relation never reuses an eagerly-loaded result.
     *
     * @return static A NEW relation marked as modified; the original is
     *         unchanged.
     */
    protected function markComposed(): static
    {
        $clone = clone $this;
        $clone->composed = true;

        return $clone;
    }

    /**
     * The builder that filters are added to.
     *
     * MorphTo has no fixed related model, so it has no builder to filter —
     * this throws a clear error for that case instead of failing with a
     * confusing internal message.
     *
     * @return ModelQueryBuilder<TRelated> The constrained builder.
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
     * Apply the relation's constraint (the FK match) to the builder.
     *
     * Called once from the constructor, so every relation starts out
     * correctly constrained and any filters you add are on top of it.
     *
     * @return void
     */
    abstract protected function addConstraints(): void;

    /**
     * Distribute eagerly-loaded results onto their parents.
     *
     * @param list<Model> $parents The parents to populate.
     * @param Collection<TRelated> $results The related models.
     * @param string $name The relation name (the cache key on the parents).
     * @param list<int|string|null|list<int|string|null>>|null $eagerParentKeys
     *        The per-row parent keys from {@see eagerLoad()}, positionally
     *        paired with $results. Only through relations consume it (their
     *        models do not carry the parent key themselves); the others
     *        ignore it.
     * @return void
     */
    abstract public function match(array $parents, Collection $results, string $name, ?array $eagerParentKeys = null): void;

    /**
     * Run the eager query for MANY parents at once.
     *
     * Instead of one query per parent, this fetches everything in a
     * single `IN (...)` query and lets {@see match()} hand each parent its
     * own results. Relations that need joins (the "through" family)
     * override this with their own strategy.
     *
     * Very large key lists are split into batches of
     * {@see EAGER_KEY_CHUNK} so no database limit is ever hit — a huge
     * load just runs a few queries instead of failing.
     *
     * @param list<KeyValue> $parentKeys The parents' key values.
     * @return EagerResult<TRelated> The related models, ready for match()
     *         to distribute.
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
     * @param list<KeyValue> $parentKeys The batch's key values.
     * @return EagerResult<TRelated> The related models for this batch.
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
     * Apply the eager query's ordering. One-to-one relations override
     * this to order by the related model's primary key, so "take the
     * first match" always picks the same row.
     *
     * @param ModelQueryBuilder<TRelated> $query The eager query.
     * @return ModelQueryBuilder<TRelated> The (possibly re-ordered) query.
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
     * The loaded result is reused only when ALL of these hold: the
     * relation was named (via {@see withName()}), no filter has been
     * added, and the parent actually has the relation loaded (an eager
     * `with()` ran). Everything else runs a fresh query — a filtered
     * chain must hit the database, since the loaded result was fetched
     * unfiltered.
     *
     * @return Collection<TRelated> The related models (a single model wraps
     *         in a one-element collection; HasOne unwraps at the accessor).
     */
    final public function getResults(): Collection
    {
        if ($this->name !== null && !$this->composed && $this->parent->relationLoaded($this->name)) {
            return self::wrapCached($this->parent->cachedRelation($this->name));
        }

        return $this->executeResults();
    }

    /**
     * Wrap a cached relation value (a model, a collection, or null) into
     * the collection shape {@see getResults()} returns.
     *
     * @param Model|Collection<Model>|null $value The cached entry.
     * @return Collection<TRelated> The wrapped shape.
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
     * Run the query and return the related models — the always-executes
     * read behind {@see getResults()}.
     *
     * @return Collection<TRelated> The related models.
     */
    protected function executeResults(): Collection
    {
        return $this->query->get();
    }

    /**
     * The first related model — or throw when the relation matches none.
     *
     * On a one-to-one relation this is the natural "must exist" read; on
     * a to-many relation it takes the first of the matches (use
     * {@see sole()} when there must be exactly one).
     *
     * @return TRelated The first related model.
     *
     * @throws \BlueprintAU\Radiant\Database\Exceptions\ModelNotFoundException When the relation matches no rows.
     */
    final public function firstOrFail(): Model
    {
        return $this->getQuery()->firstOrFail();
    }

    /**
     * Require the relation to match EXACTLY ONE related model.
     *
     * Zero matches throw
n     * {@see \BlueprintAU\Radiant\Database\Exceptions\ModelNotFoundException};
     * more than one throw
n     * {@see \BlueprintAU\Radiant\Database\Exceptions\MultipleRecordsFoundException}.
     *
     * @return TRelated The single related model.
     *
     * @throws \BlueprintAU\Radiant\Database\Exceptions\ModelNotFoundException When the relation matches no rows.
     * @throws \BlueprintAU\Radiant\Database\Exceptions\MultipleRecordsFoundException When the relation matches multiple rows.
     */
    final public function sole(): Model
    {
        return $this->getQuery()->sole();
    }

    /**
     * The underlying query builder for the related model — already
     * constrained to this parent. Filters added to it are on top of the
n     * relation's own constraint.
     *
     * @return ModelQueryBuilder<TRelated> The builder.
     */
    final public function getQuery(): ModelQueryBuilder
    {
        return $this->query;
    }

    /**
     * Add a where clause to the relation's query.
     *
     * @param string|Expression $column The column to compare — or a raw
     *        SQL fragment wrapped in an Expression.
     * @param WhereOperator|string $operator The comparison operator.
     * @param mixed $value The value to compare against.
     * @param WhereBoolean $boolean The boolean connector.
     * @return static A NEW relation with the filter added; the original
     *         is unchanged.
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
     * Add a nested where group — a parenthesized set of conditions.
     *
     * The callback receives the group's builder and MUST return it:
     *
     * ```php
     * $relation->whereNested(fn ($nested) => $nested->where('a', '=', 1)->orWhere('b', '=', 2));
     * ```
     *
     * @param callable(\BlueprintAU\Radiant\Database\Query\WhereBuilder): \BlueprintAU\Radiant\Database\Query\WhereBuilder $callback Receives the group's
     *        builder and RETURNS the constrained group.
     * @param WhereBoolean $boolean The boolean connector.
     * @return static A NEW relation with the group added; the original is
     *         unchanged.
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
     * Add an order-by clause to the relation's query.
     *
     * @param string|Expression $column The column to order by — or a raw
     *        SQL fragment wrapped in an Expression.
     * @param SortDirection|string $direction `ASC` or `DESC`.
     * @return static A NEW relation with the ordering added; the original
     *         is unchanged.
     */
    final public function orderBy(string|Expression $column, SortDirection|string $direction = SortDirection::Asc): static
    {
        $clone = clone $this;
        $clone->composed = true;
        $clone->query = $this->compositionQuery()->orderBy($column, $direction);

        return $clone;
    }

    /**
     * Set the maximum number of rows to return.
     *
     * @param int $limit The row limit.
     * @return static A NEW relation with the limit added; the original is
     *         unchanged.
     */
    final public function limit(int $limit): static
    {
        $clone = clone $this;
        $clone->composed = true;
        $clone->query = $this->compositionQuery()->limit($limit);

        return $clone;
    }

    /**
     * Set the number of rows to skip.
     *
     * @param int $offset The number of rows to skip.
     * @return static A NEW relation with the offset added; the original is
     *         unchanged.
     */
    final public function offset(int $offset): static
    {
        $clone = clone $this;
        $clone->composed = true;
        $clone->query = $this->compositionQuery()->offset($offset);

        return $clone;
    }

    /**
     * Set an explicit column selection on the relation's query.
     *
     * @param string|Expression|Aggregate ...$columns Each column as its own argument, or none to reset to `*`.
     * @return static A NEW relation with the selection added; the original
     *         is unchanged.
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
     * Group the results by one or more columns (for use with aggregates).
     *
     * @param string|array<int, string> $columns The column(s) to group by.
     * @return static A NEW relation with the grouping added; the original
     *         is unchanged.
     */
    final public function groupBy(string|array $columns): static
    {
        $clone = clone $this;
        $clone->composed = true;
        $clone->query = $this->compositionQuery()->groupBy($columns);

        return $clone;
    }

    /**
     * Filter groups after aggregation (HAVING).
     *
     * @param string|Expression|Aggregate $column The column (or aggregate) to compare.
     * @param WhereOperator|string $operator The comparison operator.
     * @param mixed $value The value to compare against.
     * @return static A NEW relation with the filter added; the original is
     *         unchanged.
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
     * @return class-string<TRelated> The class.
     */
    final public function getRelated(): string
    {
        return $this->related;
    }

    /**
     * The related classes a dotted eager-load path's deeper segments
     * resolve against.
     *
     * A single-element list for every relation with a fixed related model.
     * An EMPTY list means the related model varies per row (MorphTo) —
     * deeper path segments are then resolved from the actually-loaded
n     * models at runtime.
     *
     * @return list<class-string<Model>> The related classes, or [] when
     *         dynamic.
     */
    public function relatedClasses(): array
    {
        return [$this->related];
    }

    /**
     * The scalar form of a relation key.
     *
     * @return string The single column.
     * @throws \LogicException When the key is composite (call the plural
     *         accessor instead).
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
     * The scalar form of the parent-side key.
     *
     * @return string The single column.
     * @throws \LogicException When the key is composite (call the plural
     *         accessor instead).
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
     * The composite form of the FK columns.
     *
     * @return list<string> The column list.
     * @throws \LogicException When the key is scalar (call getForeignKey()
     *         instead).
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
     * The composite form of the parent-side key columns.
     *
     * @return list<string> The column list.
     * @throws \LogicException When the key is scalar (call getLocalKey()
     *         instead).
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
     * @return bool True when both key sides are column lists.
     */
    final public function isComposite(): bool
    {
        return is_array($this->foreignKey);
    }

    /**
     * The parent column(s) the eager loader collects key values from.
     *
     * The default is the parent's local key. {@see BelongsTo} overrides
n     * this: there the FK lives on the PARENT, so the loader must collect
     * the parent's FK values instead.
     *
     * @return string|list<string> The column (or columns) on the parent.
     */
    public function eagerKeyColumn(): string|array
    {
        return $this->localKey;
    }

    /**
     * Apply one composite key match to a builder — every FK column must
     * equal the corresponding parent value (a null component becomes IS
     * NULL, since SQL `= NULL` never matches).
     *
     * @param WhereBuilder $query The builder to constrain.
     * @param list<string> $foreignKeys The FK columns (related side).
     * @param list<string> $localKeys The local columns (parent side).
     * @param array<string, int|string|null> $values The parent's key values
     *        keyed by local column name.
     * @return \BlueprintAU\Radiant\Database\Query\WhereBuilder The constrained group.
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
     * @param list<string> $localKeys The local key columns.
     * @return array<string, int|string|null> The key map.
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
     * Read a model's composite key tuple as a POSITIONAL value list.
     *
     * Matching is position-based, not name-based: the related side's
     * values are keyed by FK column names while the parent's are keyed by
     * local column names — the two maps would never compare equal even
     * for a genuine match. Positional lists match because both sides
     * declare their columns in the same order.
     *
     * @param Model $model The model to read.
     * @param list<string> $columns The key columns, in declared order.
     * @return list<int|string|null> The values, in declared order.
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
     * @param mixed $key The scalar or column => value map.
     * @return string The serialized key.
     * @throws \JsonException When a composite key cannot be encoded.
     */
    final protected static function serializeKey(mixed $key): string
    {
        if (!is_array($key)) {
            return (string) $key;
        }

        return json_encode($key, JSON_THROW_ON_ERROR);
    }
}
