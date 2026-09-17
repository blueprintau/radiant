<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Relations;

use BlueprintAU\Radiant\Collection;
use BlueprintAU\Radiant\Concerns\FiltersQuery;
use BlueprintAU\Radiant\Database\Query\QueryBuilder;
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
     * Create a relation.
     *
     * @param Model $parent The model owning the relation.
     * @param class-string<TRelated> $related The related model class.
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

        $this->query = $this->related::newQuery();
        $this->addConstraints();
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
     *        scalars, or column => value maps for a composite key.
     * @return EagerResult The related models, with (for through relations)
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

        return new EagerResult(Collection::make($models), $parentKeysOut);
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
     * @return EagerResult The related models for this chunk.
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

            return EagerResult::fromModels($query->get()->all());
        }

        return EagerResult::fromModels($query->whereIn($this->getForeignKey(), $parentKeys)->get()->all());
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
     * Run the constrained query and return the related models.
     *
     * @return Collection<TRelated> The related models (a single model wraps
     *         in a one-element collection; HasOne unwraps at the accessor).
     */
    public function getResults(): Collection
    {
        return $this->query->get();
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
     * @param string $column The column to compare.
     * @param WhereOperator|string $operator The comparison operator.
     * @param mixed $value The value to compare against.
     * @param WhereBoolean $boolean The boolean connector.
     * @return static The relation (chainable).
     */
    public function where(
        string $column,
        WhereOperator|string $operator,
        mixed $value,
        WhereBoolean $boolean = WhereBoolean::And,
    ): static {
        $this->query->where($column, $operator, $value, $boolean);

        return $this;
    }

    /**
     * Add a nested where group on the constrained builder — the second
     * sink; the `orWhereNested` sugar delegates here.
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
        $this->query->whereNested($callback, $boolean);

        return $this;
    }

    /**
     * Add an order-by clause on the constrained builder.
     *
     * @param string $column The column to order by.
     * @param SortDirection|string $direction `ASC` or `DESC`.
     * @return static The relation (chainable).
     */
    public function orderBy(string $column, SortDirection|string $direction = SortDirection::Asc): static
    {
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
        $this->query->offset($offset);

        return $this;
    }

    /**
     * Set an explicit column selection on the constrained builder.
     *
     * @param array<int, string>|string $columns A column list, or a single column.
     * @return static The relation (chainable).
     */
    public function select(array|string $columns = ['*']): static
    {
        $this->query->select($columns);

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
        $this->query->groupBy($columns);

        return $this;
    }

    /**
     * Filter groups after aggregation on the constrained builder.
     *
     * @param string $column The column (or aggregate expression) to compare.
     * @param WhereOperator|string $operator The comparison operator.
     * @param mixed $value The value to compare against.
     * @return static The relation (chainable).
     */
    public function having(string $column, WhereOperator|string $operator, mixed $value): static
    {
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
