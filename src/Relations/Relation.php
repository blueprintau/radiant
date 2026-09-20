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
 * @template TRelated of Model
 * @phpstan-import-type KeyValue from \BlueprintAU\Radiant\Model
 */
abstract class Relation
{
    use FiltersQuery;

    /**
     * The parent-key chunk size for eager loading.
     *
     * Bounded so one oversized load cannot exceed driver caps (SQLite's
     * 999 placeholders, MySQL's max_allowed_packet) — an eager load
     * degrades to N queries, not a hard failure. Composite keys multiply
     * the placeholder count by arity, so the bound stays conservative.
     */
    protected const EAGER_KEY_CHUNK = 500;

    /**
     * The constrained builder on the related model.
     *
     * @var ModelQueryBuilder<TRelated>
     */
    protected ModelQueryBuilder $query;

    /**
     * The relation-method name this relation was built from — the cache
     * key {@see getResults()} consults. Stamped by the model factories
     * (the calling relation method's name); null when a factory was
     * reached outside a relation method, in which case the cache path is
     * skipped and every read executes.
     *
     * @var string|null
     */
    private ?string $name = null;

    /**
     * Whether a filter has been composed onto the relation since
     * construction. A composed relation's {@see getResults()} executes
     * fresh — the cached eager result was loaded for the UNFILTERED
     * constraint, and serving it to a filtered chain would silently
     * ignore the filters.
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

        // A deferring subclass (MorphTo) has no resolved related class yet —
        // the constrained builder and the constraint both need one. It
        // constructs through this ctor with the abstract Model::class marker
        // and builds its query lazily per resolved type.
        if ($this->defersConstraints()) {
            return;
        }

        $this->query = $this->related::newQuery();
        $this->addConstraints();
    }

    /**
     * Whether the subclass defers the base constructor's query build.
     *
     * False by default — every fixed-related relation builds its constrained
     * builder in the constructor. {@see MorphTo} overrides this to true: its
     * related class resolves per parent from the type column, so neither the
     * builder nor the constraint can exist at construction. The base ctor
     * still assigns the promoted properties (the marker included) before the
     * early return, so the subclass starts from a fully-initialized base.
     *
     * @return bool True to skip the query build + addConstraints() pass.
     */
    protected function defersConstraints(): bool
    {
        return false;
    }

    /**
     * Stamp the relation-method name this relation was built from.
     *
     * Called by the model factories right after construction — the name
     * is the cache key the eager loader writes (via match()) and
     * {@see getResults()} reads. Per-instance state is safe here: the
     * LAZY path builds a fresh relation per access, and the loader's
     * cached prototype relation never reaches getResults().
     *
     * A null arg is a NO-OP, not an un-stamp: the factories pass
     * `relationName()`, whose null means "not built from a relation
     * method" — erasing a stamp nothing wrote would only widen the
     * surface for accidental un-caching. A caller who wants an
     * always-fresh read bypasses the cache by composing a filter (any
     * filter marks the relation composed) or reading the builder directly
     * ({@see getQuery()} → get()); withName stays single-purpose.
     *
     * @param string|null $name The relation method's name (null changes
     *        nothing — an unstamped relation simply has no cache path).
     * @return static The relation (chainable).
     */
    final public function withName(?string $name): static
    {
        if ($name !== null) {
            $this->name = $name;
        }

        return $this;
    }

    /**
     * Mark the relation as composed — for subclasses whose modifiers
     * change what a fresh read would return without riding the where
     * sinks ({@see BelongsToMany::withPivot()} widens the select, so a
     * cache loaded WITHOUT pivot columns must not serve a withPivot
     * chain).
     *
     * @return void
     */
    protected function markComposed(): void
    {
        $this->composed = true;
    }

    /**
     * Apply the relation's constraint to {@see Relation::$query}.
     *
     * Called from the constructor — the builder starts constrained, so
     * composition adds to it.
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
     * The default strategy is the same FK match as the lazy constraint,
     * widened from `=` to `IN` — one round trip, no joins. A composite key
     * widens to an OR of AND-groups (one nested group per parent key —
     * portable across dialects, unlike tuple `IN`). Through relations
     * override the whole method (they must join the intermediate table and
     * record which parent each row belongs to).
     *
     * The key list is CHUNKED: SQL text and placeholder count grow
     * linearly with parent count, and drivers enforce hard caps (SQLite's
     * 999-parameter limit, MySQL's max_allowed_packet). An oversized load
     * used to raise a hard QueryException; it now degrades to one query
     * per chunk, with results merged in encounter order.
     *
     * @param list<KeyValue> $parentKeys The parents' local-key values —
     *        scalars, or column => value maps for a composite key. A
     *        relation whose eager strategy needs per-parent state beyond
     *        the key values folds that state INTO the key tuple
     *        ({@see MorphTo::eagerKeyColumn()} adds the type column, so
     *        the (type, key) pair travels with the keys) — the eager
     *        contract stays key-shaped, and no parent objects cross it.
     * @return EagerResult<TRelated> The related models, with (for through relations)
     *         the per-row parent key that {@see match()} distributes by.
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
     * Run one eager-load query for a CHUNK of parent keys.
     *
     * Subclasses hook {@see applyEagerOrdering()} to keep the eager path's
     * row selection deterministic (HasOne/HasOneThrough order by the
     * related PK so `match()`'s first-wins keeps the same row the lazy
     * path's `first()` would take).
     *
     * @param list<KeyValue> $parentKeys The chunk's key values.
     * @return EagerResult<TRelated> The related models for this chunk.
     */
    protected function eagerLoadChunk(array $parentKeys): EagerResult
    {
        $query = $this->related::newQuery();

        $this->applyEagerOrdering($query);

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

                $query->orWhereNested(fn(WhereBuilder $nested) => self::applyKeyTuple(
                    $nested,
                    $foreignKeys,
                    $localKeys,
                    $parentKey,
                ));
            }

            return EagerResult::fromCollection($query->get());
        }

        return EagerResult::fromCollection($query->whereIn($this->getForeignKey(), $parentKeys)->get());
    }

    /**
     * Apply this relation's eager-path ordering to the chunk query.
     *
     * Base relation: no ordering — the eager result set is whole (every
     * matching row is distributed), so order is irrelevant. One-to-one
     * subclasses override this to order by the related PK so first-wins
     * matching stays deterministic. Kept as a separate hook (rather than
     * ordering inside eagerLoadChunk) so composite-key subclass logic in
     * the OR-group path gets the same ordering.
     *
     * @param ModelQueryBuilder<TRelated> $query The chunk's eager query.
     * @return void
     */
    protected function applyEagerOrdering(ModelQueryBuilder $query): void
    {
        // No default ordering.
    }

    /**
     * Run the constrained query and return the related models — or the
     * eagerly-loaded cache when it applies.
     *
     * The cache path engages only when ALL of these hold: the relation
     * was stamped with its method name (the model factories do this), no
     * filter has been composed onto it, and the parent has the relation
     * loaded (an eager `with()` ran). A single-valued cache entry
     * (HasOne/BelongsTo/MorphTo store a model or null) wraps into the
     * same one-element-or-empty collection the lazy path returns, so both
     * paths share one shape.
     *
     * Everything else executes fresh: an unstamped relation, a composed
     * chain (`->where(...)` — the cache was loaded unfiltered), a
     * relation not loaded on this instance (lazy access always
     * executes), and the fail-fast reads {@see firstOrFail()}/{@see sole()}
     * (they delegate to the builder directly, never the cache).
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
     * Wrap a cached relation value into {@see getResults()}'s collection
     * shape.
     *
     * The template rides the PARAMETER (not the return): PHPStan infers
     * `TCached` from the argument's runtime union, and the union's
     * collection arm is `Collection<Model>` — so the inferred element
     * type is `Model`, which is exactly what the cache holds (the cache
     * is keyed by name, not by relation template). The declared
     * `Collection<TRelated>` return of {@see getResults()} is satisfied
     * because every cached entry for a relation name was produced by that
     * relation's own match() — the runtime guarantee the type describes.
     *
     * @param Model|Collection<Model>|null $value The cached entry — a
     *        model (single-valued relations), a collection (to-many), or
     *        null (an empty single-valued relation).
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
     * Run the constrained query — the always-executes read behind
     * {@see getResults()}'s cache path.
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
     * Delegates to the constrained builder's {@see ModelQueryBuilder::firstOrFail()}.
     * For one-to-one relations this is the natural "must exist" read; on
     * a to-many relation it takes the first of the matches (use
     * {@see sole()} when there must be exactly one).
     *
     * @return TRelated The first related model.
     *
     * @throws \BlueprintAU\Radiant\Database\Exceptions\ModelNotFoundException When the relation matches no rows.
     */
    public function firstOrFail(): Model
    {
        return $this->getQuery()->firstOrFail();
    }

    /**
     * Require the relation to match EXACTLY ONE related model.
     *
     * Delegates to the constrained builder's {@see ModelQueryBuilder::sole()}:
     * zero related rows raise
     * {@see \BlueprintAU\Radiant\Database\Exceptions\ModelNotFoundException};
     * more than one raise
     * {@see \BlueprintAU\Radiant\Database\Exceptions\MultipleRecordsFoundException}.
     *
     * @return TRelated The single related model.
     *
     * @throws \BlueprintAU\Radiant\Database\Exceptions\ModelNotFoundException When the relation matches no rows.
     * @throws \BlueprintAU\Radiant\Database\Exceptions\MultipleRecordsFoundException When the relation matches multiple rows.
     */
    public function sole(): Model
    {
        return $this->getQuery()->sole();
    }

    /**
     * The constrained builder — the trait's orderBy/limit/offset delegate
     * here; the sink methods below funnel into the builder's validated
     * where() directly.
     *
     * @return ModelQueryBuilder<TRelated> The builder.
     */
    final public function getQuery(): ModelQueryBuilder
    {
        return $this->query;
    }

    /**
     * Add a where clause — the trait's single sink; the `or*`/`where*`
     * helpers are default implementations over it. Validation and the
     * clause live on the builder (the builder's validated where() runs).
     *
     * @param string|Expression $column The column to compare — or a raw
     *        SQL fragment wrapped in an Expression.
     * @param WhereOperator|string $operator The comparison operator.
     * @param mixed $value The value to compare against.
     * @param WhereBoolean $boolean The boolean connector.
     * @return static The relation (chainable).
     */
    public function where(
        string|Expression $column,
        WhereOperator|string $operator,
        mixed $value,
        WhereBoolean $boolean = WhereBoolean::And,
    ): static {
        $this->composed = true;
        $this->query->where($column, $operator, $value, $boolean);

        return $this;
    }

    /**
     * Add a nested where group on the constrained builder — the second
     * sink; the trait's `orWhereNested` default delegates here.
     *
     * @param callable(\BlueprintAU\Radiant\Database\Query\WhereBuilder): void $callback Receives the group's
     *        where-family facade to constrain.
     * @param WhereBoolean $boolean The boolean connector.
     * @return static The relation (chainable).
     */
    public function whereNested(
        callable $callback,
        WhereBoolean $boolean = WhereBoolean::And,
    ): static {
        $this->composed = true;
        $this->query->whereNested($callback, $boolean);

        return $this;
    }

    /**
     * Add an order-by clause on the constrained builder.
     *
     * @param string|Expression $column The column to order by — or a raw
     *        SQL fragment wrapped in an Expression.
     * @param SortDirection|string $direction `ASC` or `DESC`.
     * @return static The relation (chainable).
     */
    public function orderBy(string|Expression $column, SortDirection|string $direction = SortDirection::Asc): static
    {
        $this->composed = true;
        $this->query->orderBy($column, $direction);

        return $this;
    }

    /**
     * Set the maximum number of rows to return.
     *
     * @param int $limit The row limit.
     * @return static The relation (chainable).
     */
    public function limit(int $limit): static
    {
        $this->composed = true;
        $this->query->limit($limit);

        return $this;
    }

    /**
     * Set the number of rows to skip.
     *
     * @param int $offset The number of rows to skip.
     * @return static The relation (chainable).
     */
    public function offset(int $offset): static
    {
        $this->composed = true;
        $this->query->offset($offset);

        return $this;
    }

    /**
     * Set an explicit column selection on the constrained builder.
     *
     * @param string|Expression|Aggregate ...$columns Each column as its own argument, or none to reset to `*`.
     * @return static The relation (chainable).
     */
    public function select(string|Expression|Aggregate ...$columns): static
    {
        // No args → the default `['*']` select (a variadic list cannot have
        // a default, so the empty case is handled here).
        $this->composed = true;
        $this->query->select(...$columns);

        return $this;
    }

    /**
     * Group by columns on the constrained builder.
     *
     * @param string|array<int, string> $columns The column(s) to group by.
     * @return static The relation (chainable).
     */
    public function groupBy(string|array $columns): static
    {
        $this->composed = true;
        $this->query->groupBy($columns);

        return $this;
    }

    /**
     * Filter groups after aggregation on the constrained builder.
     *
     * @param string|Expression|Aggregate $column The column (or aggregate) to compare.
     * @param WhereOperator|string $operator The comparison operator.
     * @param mixed $value The value to compare against.
     * @return static The relation (chainable).
     */
    public function having(string|Expression|Aggregate $column, WhereOperator|string $operator, mixed $value): static
    {
        $this->composed = true;
        $this->query->having($column, $operator, $value);

        return $this;
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
     * The related classes a dotted path's DEEPER segments resolve against.
     *
     * A single-element list for every fixed-related relation (the base
     * and all but the polymorphic inverse). An EMPTY list means the
     * related set is dynamic ({@see MorphTo} resolves per row) — the
     * path validator stops there and the runtime recursion resolves the
     * deeper segments off the actually-loaded models.
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
     * The default is the parent's local key — the related table's FK points
     * at it, so the `IN` clause matches the parent's own key values.
     * {@see BelongsTo} overrides it: there the FK lives on the PARENT and
     * points at the related table's owner key, so the loader must collect
     * the parent's FK values instead.
     *
     * @return string|list<string> The column (or columns) on the parent.
     */
    public function eagerKeyColumn(): string|array
    {
        return $this->localKey;
    }

    /**
     * Apply one key tuple onto a builder — the composite constraint shape.
     *
     * A composite key match is per-column equality: every FK column equals
     * the corresponding local value (and a null component is IS NULL —
     * SQL `= NULL` never matches). Used by the lazy constraints and the
     * eager OR-groups alike — one tuple shape, both directions.
     *
     * @param WhereBuilder $query The builder to constrain.
     * @param list<string> $foreignKeys The FK columns (related side).
     * @param list<string> $localKeys The local columns (parent side).
     * @param array<string, int|string|null> $values The parent's key values
     *        keyed by local column name.
     * @return void
     */
    final protected static function applyKeyTuple(
        WhereBuilder $query,
        array $foreignKeys,
        array $localKeys,
        array $values,
    ): void {
        foreach ($foreignKeys as $i => $foreignKey) {
            $value = $values[$localKeys[$i]] ?? null;
            $query->where($foreignKey, $value === null ? WhereOperator::Null : WhereOperator::Eq, $value);
        }
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
     * Matching must be position-based, not name-based: the related side's
     * tuple is keyed by FK column names while the parent's is keyed by
     * local column names — the two maps would never serialize equal even
     * for a genuine match. Positional lists serialize identically because
     * both sides declare their columns in the same order (the relation's
     * constructor enforces matching arity).
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
