<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant;

use BlueprintAU\Collections\Collection as BaseCollection;
use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Connections\ConnectionInterface;
use BlueprintAU\Radiant\Database\Exceptions\ModelNotFoundException;
use BlueprintAU\Radiant\Database\Exceptions\MultipleRecordsFoundException;
use BlueprintAU\Radiant\Database\Query\Aggregate;
use BlueprintAU\Radiant\Database\Query\Enums\ColumnOperator;
use BlueprintAU\Radiant\Database\Query\Enums\JoinType;
use BlueprintAU\Radiant\Database\Query\Enums\SortDirection;
use BlueprintAU\Radiant\Database\Query\Enums\WhereBoolean;
use BlueprintAU\Radiant\Database\Query\Enums\WhereOperator;
use BlueprintAU\Radiant\Database\Query\Enums\WhereType;
use BlueprintAU\Radiant\Database\Query\Expression;
use BlueprintAU\Radiant\Database\Query\QueryBuilder;
use BlueprintAU\Radiant\Database\Query\WhereBuilder;
use BlueprintAU\Radiant\Metadata\MetadataFactory;

/**
 * A query builder bound to a model class — hydrates rows into models.
 *
 * Extends the base {@see QueryBuilder} with model awareness: the model's
 * OWN columns as the default select (`*` maps to every declared column,
 * PK always present so hydration and `whereKey()` work), fail-fast column
 * validation on EVERY column-accepting method, and hydration of every
 * result row into a model instance via {@see Model::fromRow()}.
 *
 * The soft-delete scope (`whereNull` on the delete column) is auto-applied
 * here so every execution path (`get`, `first`, `count`, `exists`, …)
 * respects it. `withTrashed()` removes it; `onlyTrashed()` replaces it
 * with a `whereNotNull`.
 *
 * `TModel` is covariant: a builder bound to a subclass is everywhere a
 * builder bound to its ancestor is accepted — the template only READS the
 * model class (hydration target + validation), never writes it, and the
 * static filter forwarders (`Model::orWhere(...)` etc., trait-supplied)
 * return `ModelQueryBuilder<static>` where the shared trait declares
 * `ModelQueryBuilder<Model>`. Without covariance those default
 * implementations fail `return.type` against every subclass.
 *
 * @template-covariant TModel of Model
 * @phpstan-import-type KeyValue from \BlueprintAU\Radiant\Model
 */
final class ModelQueryBuilder extends QueryBuilder
{
    /**
     * The PK column names — always force-selected so hydration and
     * `whereKey()` have the identity columns available.
     *
     * @var list<string>
     */
    protected array $forcedKeys = [];

    /**
     * Every declared column name — the default select (`*` → these) and
     * the validation set for every column-accepting method.
     *
     * @var list<string>
     */
    protected array $modelColumns = [];

    /**
     * The eager-loaded relation paths (validated at with() time).
     *
     * @var list<string>
     */
    protected array $eagerLoad = [];

    /**
     * The column → owning-table partition map (MTI only; empty otherwise).
     *
     * @var array<string, string>
     */
    protected array $partitions = [];

    /**
     * Chunk size for key-list operations ({@see whereKey()} with a list).
     *
     * Matches the eager loader's bound ({@see \BlueprintAU\Radiant\Relations\Relation::EAGER_KEY_CHUNK}):
     * drivers cap placeholder counts (SQLite's 999 variables, MySQL's
     * max_allowed_packet), so an oversized key list must not build a single
     * unbounded statement.
     */
    protected const KEY_CHUNK = 500;

    /**
     * The MTI ancestor chain (nearest parent first), each as
     * [class, table]. Empty for non-MTI models.
     *
     * @var list<array{class-string<Model>, string}>
     */
    protected array $mtiChain = [];

    /**
     * Memoized relation resolutions, keyed by "class::method".
     *
     * Relation DECLARATIONS are static per class, but resolution used to
     * re-run the reflection (ReflectionMethod + prototype + invoke) on
     * every eager load — visible on long-running workers that loop the
     * same `with('posts')` query. The cache holds resolved relation
     * objects; they are immutable value objects over (parent, related,
     * keys), and `loadRelation` re-parents nothing — the relation's own
     * query is re-derived per call through `eagerLoad()` on a fresh
     * builder, so sharing the declaration cache is safe. Static, bounded
     * by class count, holds no per-request data.
     *
     * @var array<string, Relations\Relation<Model>>
     */
    protected static array $relationCache = [];

    /**
     * Invalidate the memoized relation-resolution cache.
     *
     * The lifecycle hook for processes that regenerate model classes at
     * runtime (hot reload, codegen): the cache is bounded by class count
     * for a fixed class set, but unbounded for dynamically generated ones,
     * and stale entries pin old class definitions in memory. Pairs with
     * {@see \BlueprintAU\Radiant\Metadata\MetadataFactory::clear()} — call
     * both when classes are redefined.
     *
     * @param string|null $class Clear only this class's relations
     *        ("class::method" entries); null clears everything.
     * @return void
     */
    public static function clearRelationCache(?string $class = null): void
    {
        if ($class === null) {
            static::$relationCache = [];
            return;
        }

        foreach (array_keys(static::$relationCache) as $key) {
            if (str_starts_with($key, $class . '::')) {
                unset(static::$relationCache[$key]);
            }
        }
    }

    /**
     * Declared-column hash set for {@see validateColumn()} (lazy).
     *
     * @var array<string, true>|null
     */
    protected ?array $columnSet = null;

    /**
     * Forced-PK hash set for {@see validateColumn()} (lazy).
     *
     * @var array<string, true>|null
     */
    protected ?array $forcedKeySet = null;

    /**
     * Create a builder bound to a model class on a connection.
     *
     * The ORM's core is portable, so the builder binds to the generic
     * connection; SQL-only extras remain gated upstream.
     *
     * The model class is a promoted READONLY property: it is written once
     * here and only read afterwards, so the covariant `TModel` occurs in
     * a read position (`class-string<TModel>` on a readonly property is
     * variance-safe — the strict typing the whole builder hangs off).
     *
     * @param class-string<TModel> $modelClass The model class.
     * @param ConnectionInterface $connection The connection to run on.
     */
    public function __construct(
        public readonly string $modelClass,
        ConnectionInterface $connection,
    ) {
        $metadata = MetadataFactory::for($modelClass);

        $this->forcedKeys = array_values(array_filter(array_map(
            fn(Column $column) => $column->name ?? '',
            $metadata->primaryKeys,
        ), fn(string $name) => $name !== ''));
        $this->modelColumns = array_map(
            fn($mapping) => $mapping->columnName,
            array_values($metadata->properties),
        );

        parent::__construct($connection, $modelClass::table());

        // MTI: partition the merged columns per table and join the ancestor
        // chain so the read path sees ONE virtual row spanning all levels.
        if ($metadata->isMtiChild()) {
            $this->partitions = $this->buildPartitions($metadata);
            $this->applyMtiJoins($metadata);
        }

        // Tell the connection which column to return on insert (RETURNING /
        // lastInsertId). A single auto-increment PK is server-generated; a
        // single caller-assigned PK declares itself too — with the
        // auto-increment flag false, so insertGetId()'s lastInsertId()
        // fallback fails fast instead of returning a stale id (a composite
        // PK declares nothing: no single column identifies the row).
        $primaryKeys = $metadata->primaryKeys;
        if (count($primaryKeys) === 1 && $primaryKeys[0]->name !== null) {
            // Constructor context: assign directly (the immutable clone API
            // is for post-construction callers).
            $this->insertIdColumn = $primaryKeys[0]->name;
            $this->insertIdAutoIncrement = $primaryKeys[0]->autoIncrement;
        }
        // Auto-apply the soft-delete scope. The clause carries the
        // `softDelete` marker so withTrashed()/onlyTrashed() can find and
        // remove it by MARKER, not positional index — index-independent
        // removal is robust under the builder's immutability (clones
        // reindex nothing). The column name comes off the metadata — no
        // trait static call on a class that may not have it. MTI: the
        // scope qualifies to the OWNING table (the synthetic column lives
        // where the trait declared it).
        if ($metadata->softDeleteColumn !== null) {
            $scopeColumn = $metadata->softDeleteColumn;

            if (isset($this->partitions[$scopeColumn])) {
                $scopeColumn = $this->partitions[$scopeColumn] . '.' . $scopeColumn;
            }

            // The constructor is the ONE place a builder finalizes its own
            // state: the scope rides the instance being built, then is
            // marked for withTrashed()/onlyTrashed() marker-based removal.
            $scoped = $this->whereNull($scopeColumn);
            $scoped->markLastWhereSoftDelete();
            $this->wheres = $scoped->getWheres();
        }
    }

    // ---- Soft-delete scope ----

    /**
     * Register relations to eager-load after hydration.
     *
     * Validation happens HERE — an unknown relation is a typo and fails
     * fast at the with() call, not at hydration. Dot-notation nests:
     * `'posts.comments'` loads posts, then each post's comments.
     *
     * @param list<string> $relations The relation paths.
     * @return static A new builder with the eager loads registered; the original is unchanged.
     * @throws \InvalidArgumentException When a path does not resolve to a
     *         chain of relation methods.
     */
    public function with(array $relations): static
    {
        foreach ($relations as $path) {
            $this->assertRelationPath($path);
            $this->validateRelationPath($path);
        }

        $clone = clone $this;

        foreach ($relations as $path) {
            $clone->eagerLoad[] = $path;
        }

        return $clone;
    }

    /**
     * Runtime boundary for one eager-load path.
     *
     * The `list<string>` element contract is PHPDoc-only — callers without
     * a static analyzer can pass anything — so the shape is checked here,
     * where the parameter is genuinely untyped and PHPStan cannot call the
     * check redundant.
     *
     * @param mixed $path The path as the caller supplied it.
     * @return string The validated path.
     * @throws \InvalidArgumentException When the path is not a non-empty
     *         string.
     */
    private function assertRelationPath(mixed $path): string
    {
        if (!is_string($path) || $path === '') {
            throw new \InvalidArgumentException(
                'Relation paths must be non-empty strings; got '
                    . (is_string($path) ? 'an empty path' : get_debug_type($path)) . '.'
            );
        }

        return $path;
    }

    /**
     * Assert a dotted relation path resolves to relation methods.
     *
     * @param string $path The dot-notation path (e.g. `posts.comments`).
     * @return void
     * @throws \InvalidArgumentException When any segment is not a relation
     *         method.
     */
    protected function validateRelationPath(string $path): void
    {
        $class = $this->modelClass;

        foreach (explode('.', $path) as $segment) {
            $relation = static::resolveRelation($class, $segment, $path);
            $classes = $relation->relatedClasses();

            if ($classes === []) {
                // A DYNAMIC related set (MorphTo) — the deeper segments
                // cannot be validated statically; the runtime recursion
                // resolves them off the actual loaded models.
                return;
            }

            $class = $classes[0];
        }
    }

    /**
     * Resolve one relation-method name on a model class.
     *
     * @param class-string<Model> $class The model declaring the method.
     * @param string $name The relation method name.
     * @param string $path The full dotted path (for the error message).
     * @return Relations\Relation<Model> The relation.
     * @throws \InvalidArgumentException When the method is missing, not
     *         public, or does not return a Relation.
     */
    protected static function resolveRelation(string $class, string $name, string $path): Relations\Relation
    {
        $cacheKey = $class . '::' . $name;

        if (isset(static::$relationCache[$cacheKey])) {
            return static::$relationCache[$cacheKey];
        }

        if (!method_exists($class, $name)) {
            throw new \InvalidArgumentException(
                "Unknown relation [{$path}] — model [{$class}] has no method [{$name}()]."
            );
        }

        $reflection = new \ReflectionMethod($class, $name);

        if (!$reflection->isPublic()) {
            throw new \InvalidArgumentException(
                "Relation [{$path}] — method [{$class}::{$name}()] is not public; relations must be callable."
            );
        }

        // A relation method takes no arguments and returns a Relation.
        // Invoke it on a detached instance (hydration without constructor —
        // the same trick fromRow() uses) so the parent's attribute reads
        // see nulls rather than an uninitialized-property error.
        $prototype = (new \ReflectionClass($class))->newInstanceWithoutConstructor();

        /** @var mixed $result */
        $result = $reflection->invoke($prototype);

        if (!$result instanceof Relations\Relation) {
            throw new \InvalidArgumentException(
                "Unknown relation [{$path}] — method [{$class}::{$name}()] does not return a Relation."
            );
        }

        return static::$relationCache[$cacheKey] = $result;
    }

    /**
     * Eager-load the registered relations onto a hydrated collection.
     *
     * One extra query per relation path — an `IN` on the FK, no joins, no
     * row multiplication. Nested paths recurse on the freshly-loaded
     * related models.
     *
     * @param Collection<Model> $models The hydrated parents.
     * @return void
     */
    protected function eagerLoadRelations(Collection $models): void
    {
        foreach ($this->eagerLoad as $path) {
            $this->loadRelationPath($models, $path);
        }
    }

    /**
     * Load one dotted relation path onto the models.
     *
     * Public so {@see Collection::load()} shares the exact loader `with()`
     * runs — one implementation, two entry points.
     *
     * @param Collection<Model> $models The models to populate.
     * @param string $path The dot-notation relation path.
     * @return void
     */
    public function loadRelationPath(Collection $models, string $path): void
    {
        [$name, $nested] = $this->splitPath($path);

        /** @var list<Model> $modelsArray */
        $modelsArray = $models->all();

        if ($modelsArray === []) {
            return;
        }

        // The relation prototype comes off the FIRST model (all models in
        // a collection are the same class — that is the hydration contract).
        $first = $modelsArray[0];
        $relation = static::resolveRelation($first::class, $name, $path);

        $this->loadRelation($modelsArray, $relation, $name, $nested, $path);
    }

    /**
     * Split a dotted path into its first segment and the nested remainder.
     *
     * @param string $path The full path.
     * @return array{string, string|null} The first segment + the remainder
     *         (null when the path has one segment).
     */
    protected function splitPath(string $path): array
    {
        $dot = strpos($path, '.');

        if ($dot === false) {
            return [$path, null];
        }

        return [substr($path, 0, $dot), substr($path, $dot + 1)];
    }

    /**
     * Run the eager query for a relation and stitch the results.
     *
     * The relation owns its eager strategy: the default widens the FK
     * match from `=` to `IN`; through relations override with a joined
     * query. After the query, `match()` distributes results onto parents
     * and nested paths recurse per level.
     *
     * @param list<Model> $parents The parents.
     * @param Relations\Relation<Model> $relation The relation to load.
     * @param string $name The relation name (cache key).
     * @param string|null $nested The remaining dotted path (null = leaf).
     * @param string $path The full path (for error messages).
     * @return void
     */
    protected function loadRelation(array $parents, Relations\Relation $relation, string $name, ?string $nested, string $path): void
    {
        // Collect the parents' key values for the IN clause. The column the
        // keys come from is relation-specific: HasOne/HasMany/through read
        // the parent's LOCAL key (the related table's FK points at it), while
        // BelongsTo reads the parent's FOREIGN key (it points at the related
        // table's owner key). BelongsTo overrides the accessor so the loader
        // stays key-agnostic. A composite key collects the full tuple —
        // deduped by its serialized form, distinct values in first-seen order.
        $keys = [];
        $keyIndex = [];

        foreach ($parents as $parent) {
            $column = $relation->eagerKeyColumn();

            if (is_string($column)) {
                $value = $parent->attribute($column);

                if ($value === null) {
                    continue;
                }

                $serialized = (string) $value;
                $keys[$serialized] = true;
                $keyIndex[$serialized] = $value;

                continue;
            }

            $tuple = [];

            foreach ($column as $keyColumn) {
                $tuple[$keyColumn] = $parent->attribute($keyColumn);
            }

            if (in_array(null, $tuple, true)) {
                continue; // a null key component matches nothing — skip
            }

            $serialized = json_encode($tuple, JSON_THROW_ON_ERROR);
            $keys[$serialized] = true;
            $keyIndex[$serialized] = $tuple;
        }

        if ($keys === []) {
            foreach ($parents as $parent) {
                // A single-valued relation (HasOne/BelongsTo/MorphTo) loads
                // as NULL when no parent carries a key; a to-many relation
                // loads as an EMPTY collection. The relation's cardinality
                // decides — a probe match against an empty result set
                // keeps the shapes honest without special-casing names.
                $relation->match([$parent], Collection::make([]), $name, []);
            }

            return;
        }

        $keyList = array_values($keyIndex);

        $result = $relation->eagerLoad($keyList);

        $relation->match($parents, $result->models, $name, $result->parentKeys);

        if ($nested !== null) {
            // Recurse onto the freshly-loaded related models.
            /** @var list<Model> $children */
            $children = [];

            foreach ($parents as $parent) {
                $value = $parent->cachedRelation($name);

                if ($value instanceof Collection) {
                    foreach ($value as $child) {
                        $children[] = $child;
                    }
                } elseif ($value instanceof Model) {
                    $children[] = $value;
                }
            }

            if ($children !== []) {
                // Recurse ONE segment at a time: split the remaining dotted
                // path, resolve only its first segment as a method name, and
                // pass the rest down as the next level's nested path. Passing
                // the whole remainder (`b.c`) as a method name would fail for
                // any path three or more levels deep.
                [$childName, $childNested] = $this->splitPath($nested);
                $childRelation = static::resolveRelation($children[0]::class, $childName, $path);
                $this->loadRelation($children, $childRelation, $childName, $childNested, $path);
            }
        }
    }

    // ---- MTI (multi-table inheritance) reads ----

    /**
     * Build the column → owning-table partition map from the metadata.
     *
     * @param \BlueprintAU\Radiant\Metadata\ClassMetadata $metadata The model's metadata.
     * @return array<string, string> column => table
     */
    protected function buildPartitions(\BlueprintAU\Radiant\Metadata\ClassMetadata $metadata): array
    {
        $partitions = [];

        foreach ($metadata->properties as $mapping) {
            $partitions[$mapping->columnName] = $metadata->tableFor($mapping->columnName);
        }

        return $partitions;
    }

    /**
     * INNER JOIN every ancestor table on the shared PK and select each
     * level's columns qualified + aliased back to the plain columnName —
     * one virtual row, hydration unchanged.
     *
     * The FK + ON DELETE CASCADE the factory emits guarantees every
     * ancestor row exists, so INNER is always correct.
     *
     * @param \BlueprintAU\Radiant\Metadata\ClassMetadata $metadata The model's metadata.
     * @return void
     */
    protected function applyMtiJoins(\BlueprintAU\Radiant\Metadata\ClassMetadata $metadata): void
    {
        $table = $metadata->tableName;
        $this->mtiChain = [];

        // Walk the parent chain; each table-owning level joins on the
        // shared PK (same-named key columns ARE the link).
        $parent = $metadata->parentModel;

        while ($parent !== null) {
            $parentMetadata = MetadataFactory::for($parent);
            $parentTable = $parentMetadata->tableName;

            if ($parentTable !== null) {
                $pk = $parentMetadata->primaryKeys[0]->name ?? 'id';

                $this->joins[] = [
                    'type' => JoinType::Inner,
                    'table' => $parentTable,
                    'wheres' => [[
                        'type' => WhereType::Column,
                        'first' => "{$table}.{$pk}",
                        'operator' => ColumnOperator::Eq,
                        'second' => "{$parentTable}.{$pk}",
                        'boolean' => WhereBoolean::And,
                    ]],
                ];

                $this->mtiChain[] = [$parent, $parentTable];
            }

            $parent = $parentMetadata->parentModel;
        }

        // Re-select every column qualified + aliased back to its plain
        // columnName — raw keys stay unambiguous across all levels. Plain
        // `table.column as column` specs: the Grammar's wrapColumn() owns
        // the quoting AND the AS rendering. Constructor-time: the builder
        // finalizes its own state here (the one self-mutation point).
        $selects = [];

        foreach ($this->modelColumns as $column) {
            $ownerTable = $this->partitions[$column] ?? $table;
            $selects[] = "{$ownerTable}.{$column} as {$column}";
        }

        $this->columns = $selects;
    }

    /**
     * Add a column-to-column comparison with model-aware column validation.
     *
     * The operator is interpolated verbatim between two identifiers in the
     * compiled SQL, so it is resolved to a {@see ColumnOperator} — either
     * passed as the enum directly, or validated from a string. The enum is
     * stored, not a string: nothing raw ever reaches the SQL. Both columns
     * are validated like any other column-accepting method: a bare name
     * must be a declared model column; a qualified `table.column` must name
     * the model's own table or a table this query JOINs.
     *
     * @param string $first The first column.
     * @param ColumnOperator|string $operator The comparison operator (=, !=, <, <=, >, >=).
     * @param string $second The second column.
     * @param WhereBoolean $boolean The boolean connector.
     * @return static The builder.
     * @throws \InvalidArgumentException When the operator is not a valid
     *         column comparison, or a column is not declared on the model
     *         (or a valid qualified reference).
     */
    public function whereColumn(string $first, ColumnOperator|string $operator = '=', string $second = '', WhereBoolean $boolean = WhereBoolean::And): static
    {
        $this->validateColumn($first);
        $this->validateColumn($second);

        return parent::whereColumn($first, $operator, $second, $boolean);
    }
    /**
     * Add a raw SQL where clause.
     *
     * NOT overridden on purpose: the SQL is spliced verbatim by design
     * (it is an expression, not a column reference, so there is nothing
     * to {@see validateColumn()}), and its bindings are POSITIONAL — the
     * column each belongs to is not knowable, so no per-column cast
     * applies. DateTime bindings pass through and the connection's codec
     * formats them, exactly as on the base builder.
     *
     * @param string $sql The raw SQL condition (e.g. `lower(email) = ?`).
     * @param array<int, mixed> $bindings The values to bind into the condition.
     * @param WhereBoolean $boolean The boolean connector.
     * @return static The builder.
     */
    public function whereRaw(string $sql, array $bindings = [], WhereBoolean $boolean = WhereBoolean::And): static
    {
        return parent::whereRaw($sql, $bindings, $boolean);
    }

    /**
     * Add a nested group of where clauses with model-aware column validation.
     *
     * NOT overridden: the base {@see QueryBuilder::whereNested()} owns the
     * whole group algorithm (build → callback → empty guard → store) and
     * delegates construction to {@see newNestedBuilder()} — which this
     * class overrides to construct a MODEL builder, so every
     * `$nested->where(...)` in the callback funnels through the validating
     * `where()`, exactly like the outer query. The callback and the stored
     * group share the ONE `WhereBuilder` instance.
     *
     * @return QueryBuilder The group's backing builder (a NEW builder, not
     *         `$this`; typed as the base because a group is clause storage,
     *         not a hydration target — the group's TModel is irrelevant to
     *         consumers, which only call `getWheres()`/`getBindings()`).
     * @throws \InvalidArgumentException When the callback added no clause,
     *         or a column in the group is not a declared model column.
     */
    protected function newNestedBuilder(): QueryBuilder
    {
        return new self($this->modelClass, $this->connection);
    }

    /**
     * Append an ON condition to the last added join, with validation.
     *
     * Both columns are validated before the condition is appended — the
     * same fail-fast the `where()` override gives every filter. Through
     * relations (`HasManyThrough::addConstraints()`) qualify their
     * conditions to JOINED tables, which the qualified branch of
     * {@see validateColumn()} accepts.
     *
     * @param string $first The first column of the condition.
     * @param ColumnOperator|string $operator The comparison operator.
     * @param string $second The second column of the condition.
     * @return static The builder.
     * @throws \LogicException When no join has been added yet.
     * @throws \InvalidArgumentException When the operator is not a valid
     *         column comparison, or a column is not a valid reference.
     */
    public function on(string $first, ColumnOperator|string $operator = '=', string $second = ''): static
    {
        $this->validateColumn($first);
        $this->validateColumn($second);

        return parent::on($first, $operator, $second);
    }

    /**
     * Append an OR-connected ON condition to the last added join, with
     * validation.
     *
     * @param string $first The first column of the condition.
     * @param ColumnOperator|string $operator The comparison operator.
     * @param string $second The second column of the condition.
     * @return static The builder.
     * @throws \LogicException When no join has been added yet.
     * @throws \InvalidArgumentException When the operator is not a valid
     *         column comparison, or a column is not a valid reference.
     */
    public function orOn(string $first, ColumnOperator|string $operator = '=', string $second = ''): static
    {
        $this->validateColumn($first);
        $this->validateColumn($second);

        return parent::orOn($first, $operator, $second);
    }

    // ---- Soft-delete scope ----

    /**
     * Include soft-deleted rows — removes the auto-applied scope (and any
     * `onlyTrashed()` NOT-NULL clause).
     *
     * The clauses are found by their `softDelete` MARKER, not a positional
     * index — removal is index-independent and survives any amount of
     * clause churn between calls.
     *
     * @return static A new builder without the soft-delete clauses; the original is unchanged.
     */
    public function withTrashed(): static
    {
        $wheres = $this->getWheres();

        $filtered = array_values(array_filter(
            $wheres,
            fn(array $where): bool => !($where['softDelete'] ?? false),
        ));

        if ($filtered === $wheres) {
            return $this; // nothing to remove — reuse the instance.
        }

        $clone = clone $this;
        $clone->wheres = $filtered;
        return $clone;
    }

    /**
     * Only soft-deleted rows — replaces the scope with a marked
     * `whereNotNull` so the toggle round-trips.
     *
     * The clause carries the same `softDelete` marker; a later
     * `withTrashed()` removes it. Without the marker, `Model::onlyTrashed()->withTrashed()`
     * silently kept the NOT-NULL clause and still returned only-trashed
     * rows. The column is qualified exactly like the constructor's scope —
     * on MTI models the joined query needs `table.column`, else the SQL
     * fails with an ambiguous-column error.
     *
     * @return static A new builder scoped to only-trashed rows; the original is unchanged.
     */
    public function onlyTrashed(): static
    {
        $metadata = MetadataFactory::for($this->modelClass);
        $column = $metadata->softDeleteColumn;

        if ($column === null) {
            throw new \LogicException(
                "Model [{$this->modelClass}] does not use SoftDeletes; onlyTrashed() is unavailable."
            );
        }

        // First clear any existing soft-delete state (scope and/or a
        // previous onlyTrashed clause) so the toggle is idempotent.
        $cleared = $this->withTrashed();

        if (isset($cleared->partitions[$column])) {
            $column = $cleared->partitions[$column] . '.' . $column;
        }

        $scoped = $cleared->whereNotNull($column);
        $scoped->markLastWhereSoftDelete();

        $clone = clone $scoped;
        return $clone;
    }

    // ---- Execution (hydration) ----

    /**
     * Run the query and hydrate every row into a model.
     *
     * Carries a scoped `phpstan-ignore` for `method.childReturnType` — the
     * KNOWN PHPStan limitation this package lives with: PHP has no
     * self-typed templates on native return types, so `Collection<TModel>`
     * cannot be declared covariant against the base
     * `QueryBuilder::get(): Collection<int, stdClass>`. The native return
     * types (`: Collection`) are identical, so this is a PHPDoc-only
     * divergence — PHP never validates it and nothing breaks at runtime.
     * Laravel solves the same shape by leaving `get()` untyped entirely;
     * the ignore is the smaller, honest escape hatch (see the project
     * convention on when ignores are acceptable).
     *
     * (The variance flag rides the same PHPDoc-only divergence: with
     * `TModel` covariant, `Collection<TModel>` in an output position is
     * fine, but PHPStan still counts the return TYPE of an overridden
     * method as an invariant position — the ignore below covers both.)
     *
     * @return Collection<TModel> The hydrated models.
     *
     * @phpstan-ignore method.childReturnType, generics.variance
     */
    public function get(): Collection
    {
        $rows = parent::get();

        $models = array_map(
            fn(\stdClass $row) => $this->modelClass::fromRow($row),
            $rows->all(),
        );

        $collection = Collection::make($models);

        $this->eagerLoadRelations($collection);

        return $collection;
    }

    /**
     * Raw rows (stdClass) — bypasses hydration.
     *
     * Use this for custom or aggregate columns that don't map onto model
     * properties (e.g. a `count(*) as total` select), where `fromRow()`
     * would have nothing to hydrate.
     *
     * @return BaseCollection<int, \stdClass> The raw rows.
     */
    public function getRaw(): BaseCollection
    {
        return parent::get();
    }

    /**
     * Stream the query, hydrating each row into a model as it arrives.
     *
     * Overrides the base {@see QueryBuilder::cursor()}: the base yields
     * raw `\stdClass` rows, but a model-bound builder's contract is
     * hydration — the streaming counterpart of {@see get()} must yield
     * the same instances. Eager loads CANNOT ride a stream (they need the
     * full parent collection to batch the `IN` queries); call
     * {@see with()}-less or accept un-populated relations, or use
     * `get()` when relations are required. `getRaw()` remains the raw-row
     * escape hatch.
     *
     * @return \Generator<int, TModel> The hydrated models, one at a time.
     *
     * @phpstan-ignore method.childReturnType
     */
    public function cursor(): \Generator
    {
        foreach ($this->connection->cursor($this) as $row) {
            yield $this->modelClass::fromRow($row);
        }
    }

    /**
     * Run the query and hydrate the first row.
     *
     * Fetches the raw row directly (not through `parent::first()`, which
     * would call this class's hydrated `get()` and re-hydrate a Model).
     *
     * @return TModel|null The first model, or null when none match.
     */
    public function first(): ?Model
    {
        // limit() is immutable — it returns a scoped clone, leaving this
        // builder's own limit untouched.
        $rows = $this->connection->select($this->limit(1));
        $row = $rows[0] ?? null;

        if ($row === null) {
            return null;
        }

        $model = $this->modelClass::fromRow($row);

        // Eager loads apply to single-model reads too.
        if ($this->eagerLoad !== []) {
            $this->eagerLoadRelations(Collection::make([$model]));
        }

        return $model;
    }

    /**
     * Find a model by primary key.
     *
     * @param KeyValue $id The primary-key value (or a column => value map
     *        for a composite key).
     * @return TModel|null The model, or null when not found.
     */
    public function find(mixed $id): ?Model
    {
        return $this->whereKey($id)->first();
    }

    /**
     * Run the query and hydrate the first row — or throw when none match.
     *
     * The fail-fast counterpart of {@see first()}: identical fetch and
     * hydration, but an empty result raises
     * {@see ModelNotFoundException} naming the model class. Use when an
     * empty result is a caller bug rather than an expected state.
     *
     * SIDE-EFFECT-FREE: the fetch (including its internal `limit(1)`)
     * runs on a shallow clone, so the builder's own limit, wheres, and
     * column state are untouched — unlike `first()`, which mutates the
     * limit. Safe to share a builder (or a relation's constrained query)
     * between a fail-fast read and a later full read.
     *
     * @return TModel The first model.
     *
     * @throws ModelNotFoundException When no row matches the query.
     */
    public function firstOrFail(): Model
    {
        return (clone $this)->firstOrFailWithKey(null);
    }

    /**
     * Find a model by primary key — or throw when it does not exist.
     *
     * The fail-fast counterpart of {@see find()}: same key handling
     * (scalar or composite map through {@see whereKey()}), but a missing
     * row raises {@see ModelNotFoundException} carrying BOTH the model
     * class and the key that was looked up.
     *
     * @param KeyValue $id The primary-key value (or a column => value map
     *        for a composite key).
     * @return TModel The model.
     *
     * @throws ModelNotFoundException When no row matches the key.
     */
    public function findOrFail(mixed $id): Model
    {
        // whereKey() also runs on the clone — the added wheres never land
        // on the shared builder.
        return (clone $this)->whereKey($id)->firstOrFailWithKey($id);
    }

    /**
     * Require the query to match EXACTLY ONE row, hydrated.
     *
     * Stricter than {@see firstOrFail()}: zero rows raise
     * {@see ModelNotFoundException}; MORE than one row raises
     * {@see MultipleRecordsFoundException} — the two failures are
     * distinct exception types so callers can catch them separately.
     * Intended for reads backed by a uniqueness guarantee (a unique
     * column, a one-to-one relation).
     *
     * SIDE-EFFECT-FREE like {@see firstOrFail()}: the `limit(2)` fetch
     * runs on a shallow clone, so the builder's own limit, wheres, and
     * column state are untouched.
     *
     * @return TModel The single matching model.
     *
     * @throws ModelNotFoundException When no row matches the query.
     * @throws MultipleRecordsFoundException When more than one row matches.
     */
    public function sole(): Model
    {
        // select() returns a Collection (not a bare array) — count it,
        // never `=== []`.
        $rows = $this->connection->select((clone $this)->limit(2));
        $rowCount = \count($rows);

        if ($rowCount === 0) {
            throw new ModelNotFoundException($this->modelClass);
        }

        if ($rowCount > 1) {
            throw new MultipleRecordsFoundException($rowCount, $this->modelClass);
        }

        $row = $rows[0] ?? null;

        if (!$row instanceof \stdClass) {
            throw new ModelNotFoundException($this->modelClass);
        }

        $model = $this->modelClass::fromRow($row);

        // Eager loads apply to single-model reads too — same tail as first().
        if ($this->eagerLoad !== []) {
            $this->eagerLoadRelations(Collection::make([$model]));
        }

        return $model;
    }

    /**
     * The shared fail-fast fetch behind {@see firstOrFail()} and
     * {@see findOrFail()}.
     *
     * The key is threaded through ONLY to build the exception message —
     * `firstOrFail()` passes null (no key involved), `findOrFail()` passes
     * the id it looked up. Building the exception once here (rather than
     * catching and re-wrapping) keeps the throw site single.
     *
     * @param mixed $keyForMessage The lookup key for the exception, or null.
     * @return TModel The first model.
     *
     * @throws ModelNotFoundException When no row matches the query.
     */
    private function firstOrFailWithKey(mixed $keyForMessage): Model
    {
        $model = $this->first();

        if ($model === null) {
            throw new ModelNotFoundException($this->modelClass, $keyForMessage);
        }

        return $model;
    }

    // ---- Scalar reads (decoded through the column casts) ----

    /**
     * The scalar method — the value of a single column from the first row,
     * DECODED through the column's cast.
     *
     * Overrides the base {@see QueryBuilder::value()}: the base returns the
     * raw driver value (SQLite hands datetimes back as strings), so
     * `max('created_at')` would return `'2026-09-11 10:00:00'` where the
     * model's own attribute reads a Carbon. Here, a bare declared column
     * name runs through its {@see Column::decode()} — the same cast
     * {@see Model::fromRow()} hydrates through, so builder scalar reads
     * and attribute reads agree. Raw SQL and user-aliased columns
     * (`sum(price) as total`) pass through raw — the model layer has no
     * cast for a computed value.
     *
     * An {@see Aggregate} argument DECODES through its column's cast when
     * that column is declared — `value(Aggregate::max('signed_up_at'))`
     * yields a Carbon, matching `max('signed_up_at')`.
     *
     * @param string|Aggregate $column The column to read — or an aggregate.
     * @return mixed The decoded column value, or null when no row matches.
     */
    public function value(string|Aggregate $column): mixed
    {
        if ($column instanceof Aggregate) {
            $rows = $this->connection->select(
                $this->scopedFor(new Aggregate($column->function, $column->column, 'radiant_scalar'))->limit(1),
            );
            $raw = $rows[0] ?? null;

            // An Expression argument is a computed value — no cast applies.
            return $column->column instanceof Expression
                ? ($raw === null ? null : $raw->radiant_scalar)
                : $this->decodeScalar($column->column, $raw === null ? null : $raw->radiant_scalar);
        }

        $sql = $this->scalarColumn($column);

        // Fetch the RAW column directly (mirroring first()'s raw-row
        // fetch): the hydrating first() would return a Model, which has no
        // scalar property to read the value back from. The columnar fetch
        // reads the single column positionally — no per-row object
        // materialized. The scoped builder keeps THIS builder's select
        // untouched.
        $raw = $this->connection->selectColumn($this->scopedFor($sql)->limit(1))->first();

        return $this->decodeScalar($column, $raw);
    }

    /**
     * A collection of a single column's values, DECODED through the casts.
     *
     * See {@see value()} for the decode policy. The returned collection
     * holds decoded values for declared columns (a `pluck('created_at')`
     * yields Carbons); anything else plucks the raw values. The return is
     * the RAW-row collection (values are not Models, so the model-typed
     * Collection cannot hold them).
     *
     * @param string $column The column to pluck.
     * @return BaseCollection<int, mixed> The decoded column values.
     */
    public function pluck(string $column): BaseCollection
    {
        $sql = $this->scalarColumn($column);

        // The columnar fetch: values come back positionally, one per row —
        // no per-row object materialized, no alias read per row. map()
        // preserves the 0-based list keys — the result is already a list,
        // so no trailing values() re-index (it would be a no-op copy).
        return $this->connection->selectColumn($this->scopedFor($sql))->map(
            fn(mixed $raw) => $this->decodeScalar($column, $raw),
        );
    }

    /**
     * A clone of this builder scoped to a scalar or aggregate select.
     *
     * `value()`/`pluck()`/`aggregates()` must run THEIR columns without
     * touching this builder's state — and on THIS builder, `select()`
     * cannot be used for that: it (a) re-merges the forced PK, dragging
     * extra columns into the query, and (b) validates every column against
     * the model's declared set, rejecting computed expressions like
     * `lower(email)` or `sum(price)`. The clone carries the constraints
     * (wheres, joins, soft-delete state) but owns its own column list —
     * the deliberate bypass primitive for internal scalar reads. The BASE
     * builder has no such helper (its `select()` is safe to use directly);
     * this is model-layer-only.
     *
     * VARIADIC: one column per argument — `aggregates()` spreads its
     * aggregate list directly.
     *
     * @param string|Aggregate ...$sql Each column expression or aggregate.
     * @return static The scoped clone.
     */
    protected function scopedFor(string|Aggregate ...$sql): static
    {
        $clone = clone $this;
        $clone->columns = $sql === [] ? ['*'] : array_values($sql);

        return $clone;
    }

    /**
     * Decode one scalar read when the column is a declared model column.
     *
     * Only a BARE declared column name (or `column as alias` over one)
     * decodes — the mapping is the single source of the cast. Aggregate
     * expressions, qualified specs, and raw SQL return the value
     * unchanged: the model layer cannot cast a computed value.
     *
     * @param string $column The column expression the caller asked for.
     * @param mixed $raw The raw driver value.
     * @return mixed The decoded value (or `$raw` unchanged).
     */
    private function decodeScalar(string $column, mixed $raw): mixed
    {
        $bare = trim((string) preg_replace('/\s+as\s+\S+$/i', '', $column));
        $metadata = MetadataFactory::for($this->modelClass);

        if (!$metadata->hasColumn($bare)) {
            return $raw;
        }

        return $metadata->mappingFor($bare)->column->decode($raw);
    }

    // ---- Aggregates (decoded like every other scalar read) ----

    /**
     * Count the matching rows.
     *
     * @return int The row count.
     */
    public function count(): int
    {
        return (int) $this->value(Aggregate::count());
    }

    /**
     * The maximum value of a column — decoded through the cast for
     * declared columns (e.g. a Carbon for a datetime column).
     *
     * @param string $column The column to aggregate.
     * @return mixed The maximum value.
     */
    public function max(string $column): mixed
    {
        return $this->value(Aggregate::max($column));
    }

    /**
     * The minimum value of a column — decoded through the cast for
     * declared columns.
     *
     * @param string $column The column to aggregate.
     * @return mixed The minimum value.
     */
    public function min(string $column): mixed
    {
        return $this->value(Aggregate::min($column));
    }

    /**
     * The sum of a column's values — decoded through the cast for
     * declared columns.
     *
     * @param string $column The column to aggregate.
     * @return mixed The sum.
     */
    public function sum(string $column): mixed
    {
        return $this->value(Aggregate::sum($column));
    }

    /**
     * The average of a column's values — decoded through the cast for
     * declared columns.
     *
     * @param string $column The column to aggregate.
     * @return mixed The average.
     */
    public function avg(string $column): mixed
    {
        return $this->value(Aggregate::avg($column));
    }

    /**
     * Multiple aggregates in one query — the raw values decoded through
     * each aggregate's column cast (see {@see decodeScalar()}); computed
     * targets (`count(*)`) and Expression arguments pass through raw.
     *
     * The aggregate's own ALIAS names its result column — one way to name
     * a column, no override layer:
     *
     *     User::query()->aggregates(
     *         Aggregate::count('*', 'total'),
     *         Aggregate::max('signed_up_at', 'latest'),
     *     )->latest; // a Carbon for a datetime column
     *
     * @param Aggregate ...$aggregates The aggregates to compute.
     * @return \stdClass The values as properties, keyed by each
     *         aggregate's result key (the explicit alias when given, else
     *         the derived call text). Property access on an unknown key
     *         throws — no silent null for a typo'd alias.
     */
    public function aggregates(Aggregate ...$aggregates): \stdClass
    {
        // Raw rows — a hydrated Model has no aggregate-alias properties to
        // read the values back from. The aggregate select rides the scoped
        // clone: this builder's own column list is untouched. The spread
        // forwards the variadic list directly.
        $row = $this->scopedFor(...$aggregates)->getRaw()->first();

        $out = new \stdClass();

        foreach ($aggregates as $aggregate) {
            $column = $aggregate->column;
            $isExpression = $column instanceof Expression;
            $columnKey = $isExpression ? $column->value : $column;
            $key = $aggregate->alias ?? "{$aggregate->function}({$columnKey})";

            // An Expression argument is a computed value — no cast applies;
            // declared-column arguments decode through the column's cast.
            $out->{$key} = $isExpression
                ? ($row === null ? null : $row->{$key})
                : $this->decodeScalar($column, $row === null ? null : $row->{$key});
        }

        return $out;
    }

    /**
     * Constrain the query to a primary-key value.
     *
     * The runtime boundary accepts WIDER shapes than the historical KeyValue
     * alias, because the PHPDoc type cannot be enforced natively:
     *
     * - a scalar (int|string|null) — the single-PK form;
     * - a column => value MAP — the composite-key form;
     * - a LIST of scalars or key maps — the batching form (match ANY),
     *   used by {@see \BlueprintAU\Radiant\Collection::fresh()}.
     *
     * @param KeyValue|list<KeyValue> $id The key value, a column => value
     *        map, or a list of either.
     * @return static The builder.
     * @throws \InvalidArgumentException When the key shape does not match
     *         the model's PK, or a column/value fails validation.
     */
    public function whereKey(mixed $id): static
    {
        $primaryKeys = MetadataFactory::for($this->modelClass)->primaryKeys;

        // PK-selection guard: whereKey's results feed save()/delete()
        // (getKeyForRefresh()), which need the PK hydrated. A caller-owned
        // select that omits the PK column hydrates models whose key
        // property is uninitialized — the key reads as null and the write
        // silently targets `WHERE pk IS NULL` (matching nothing, or worse).
        // Fail fast at the API boundary instead.
        $selected = $this->getColumns();
        if ($selected !== ['*']) {
            $pkNames = array_filter(
                array_map(fn($pk) => $pk->name, $primaryKeys),
                fn($name) => $name !== null,
            );

            foreach ($selected as $column) {
                if ($column instanceof Expression) {
                    continue; // raw expressions carry no column contract.
                }
                if ($column instanceof Aggregate) {
                    continue; // aggregates are computed columns, not the PK.
                }
                $bare = trim((string) preg_replace('/\s+as\s+\S+$/i', '', $column));

                if ($bare === $this->table . '.*') {
                    $pkNames = [];
                    break;
                }

                // Match the PK bare (`id`) or qualified (`table.id` — the
                // MTI builder's own default select qualifies every column).
                foreach ($pkNames as $pkName) {
                    if ($bare === $pkName || $bare === $this->table . '.' . $pkName) {
                        $pkNames = [];
                        break 2;
                    }
                }
            }

            if ($pkNames !== []) {
                throw new \InvalidArgumentException(
                    'whereKey() requires the primary key in the select list — the result feeds '
                        . 'save()/delete(), which need the key hydrated. Add the PK column '
                        . '[' . implode(', ', array_map(
                            fn($name) => $this->table . '.' . $name,
                            $pkNames,
                        )) . '] to the select, or use select(\'' . $this->table . '.*\').'
                );
            }
        }

        // A LIST of key values (scalars or key maps) constrains to ANY of
        // them — the batching path used by Collection::fresh(). An
        // associative map (string keys) is a composite key; a list (int
        // keys) is a key set. An empty list matches nothing (1 = 0).
        //
        // Each key becomes its own nested AND-group ORed at the edges
        // (mirroring the eager-load OR-of-groups shape): `pk = 1 OR
        // (a = ? AND b = ?) OR pk = 3`. Flattening would let one key's
        // parts AND against the NEXT key.
        //
        // The list is CHUNKED at the same 500-key bound eager loading uses:
        // SQL text and placeholder count grow linearly with key count, and
        // drivers enforce hard caps (SQLite's 999 variables, MySQL's
        // max_allowed_packet). An oversized list used to raise a hard
        // QueryException; it now compiles the same per-key OR-groups, just
        // produced from bounded chunks of the list — same match-any
        // semantics, linearly bounded memory during construction.
        // (Composite keys multiply arity, so the bound stays conservative.)
        if (is_array($id) && array_is_list($id)) {
            if ($id === []) {
                return $this->whereRaw('1 = 0');
            }

            $builder = $this;

            foreach (array_chunk($id, self::KEY_CHUNK) as $chunk) {
                foreach ($chunk as $key) {
                    $builder = $builder->orWhereNested(
                        fn (WhereBuilder $nested): WhereBuilder => $this->applyWhereKeyOn($nested, $key)
                    );
                }
            }

            return $builder;
        }

        // Composite PK → accept an associative array of column => value.
        // Every column MUST be a declared PK column (a typo'd column would
        // otherwise silently match nothing), and each where is qualified
        // for MTI (the PK exists on EVERY joined table — a bare column
        // would compile to an ambiguous-column error).
        //
        // The key map lands inside a whereNested GROUP — the tuple is ONE
        // constraint unit. Flat, a caller's later `->orWhere(...)` would OR
        // against the tuple's PARTS ((pk1 = ? AND pk2 = ?) OR x — matching
        // the wrong rows); grouped, the parts AND within the parens and the
        // caller's OR stays at the constraint's edges.
        if (is_array($id)) {
            return $this->whereNested(
                fn (WhereBuilder $nested): WhereBuilder => $this->applyWhereKeyOn($nested, $id)
            );
        }

        $single = $this->assertSingleKeyValue($id);

        if (count($primaryKeys) !== 1 || $primaryKeys[0]->name === null) {
            throw new \InvalidArgumentException(
                "Model [{$this->modelClass}] has a composite PK; pass an array of column => value."
            );
        }

        $pkName = $primaryKeys[0]->name;

        // MTI: the PK exists on EVERY joined table — qualify to avoid an
        // ambiguous-column error in the compiled SQL. The qualified form
        // validates through validateColumn's partition branch.
        if ($this->partitions !== []) {
            return $this->where(
                ($this->partitions[$pkName] ?? $this->table) . '.' . $pkName,
                WhereOperator::Eq,
                $single,
            );
        }

        return $this->where($pkName, WhereOperator::Eq, $single);
    }

    /**
     * Apply ONE key value onto a where-group — the shared body of
     * {@see whereKey()}'s single and list branches.
     *
     * A scalar key applies the model's single PK column; a column => value
     * map applies the full composite tuple (each column validated against
     * the declared PKs and MTI-qualified). Values are validated the same
     * as the direct branches — the group context changes only where the
     * clauses land.
     *
     * @param WhereBuilder $nested The group to constrain.
     * @param mixed $key The scalar key value or column => value map.
     * @return WhereBuilder The constrained group (immutable — returned to
     *         the callback's caller, which forwards it to whereNested()).
     * @throws \InvalidArgumentException When the key shape does not match
     *         the model's PK (a scalar for a composite model, a map for a
     *         single-PK model), or a column/value fails validation.
     */
    private function applyWhereKeyOn(WhereBuilder $nested, mixed $key): WhereBuilder
    {
        $primaryKeys = MetadataFactory::for($this->modelClass)->primaryKeys;

        if (is_array($key) && $key !== []) {
            foreach ($key as $column => $value) {
                $validated = $this->assertCompositeKeyValue($column, $value);

                $this->assertCompositeKeyColumn($validated['column'], $primaryKeys);

                $qualified = $validated['column'];

                if ($this->partitions !== []) {
                    $qualified = ($this->partitions[$qualified] ?? $this->table) . '.' . $qualified;
                }

                $nested = $nested->where($qualified, WhereOperator::Eq, $validated['value']);
            }

            return $nested;
        }

        $single = $this->assertSingleKeyValue($key);

        if (count($primaryKeys) !== 1 || $primaryKeys[0]->name === null) {
            throw new \InvalidArgumentException(
                "Model [{$this->modelClass}] has a composite PK; pass an array of column => value."
            );
        }

        $pkName = $primaryKeys[0]->name;

        if ($this->partitions !== []) {
            $pkName = ($this->partitions[$pkName] ?? $this->table) . '.' . $pkName;
        }

        return $nested->where($pkName, WhereOperator::Eq, $single);
    }

    /**
     * Runtime boundary for one composite-key entry.
     *
     * The `array<string, int|string|null>` shape is a PHPDoc-only contract
     * — the check lives on genuinely-mixed parameters so PHPStan cannot
     * flag it as redundant.
     *
     * @param mixed $column The array key as PHP delivered it.
     * @param mixed $value The array value.
     * @return array{column: string, value: int|string|null} The validated pair.
     * @throws \InvalidArgumentException When the column is not a non-empty
     *         string or the value is not int, string, or null.
     */
    private function assertCompositeKeyValue(mixed $column, mixed $value): array
    {
        if (!is_string($column) || $column === '') {
            throw new \InvalidArgumentException(
                'A composite key must be a column => value map with string column names; got '
                    . (is_string($column) ? 'an empty column name' : get_debug_type($column)) . '.'
            );
        }

        if (!is_int($value) && !is_string($value) && $value !== null) {
            throw new \InvalidArgumentException(
                "Composite key value for [{$column}] must be int, string or null; got "
                    . get_debug_type($value) . '.'
            );
        }

        return ['column' => $column, 'value' => $value];
    }

    /**
     * Assert a composite-key column is one of the model's primary keys.
     *
     * Shape validation alone would let a typo'd column through — a
     * `where(['region' => ...])` on a `regionId_country` model would
     * validate fine and silently match nothing. The key map's columns must
     * be the model's declared PK columns.
     *
     * @param string $column The column name as the caller supplied it.
     * @param list<Column> $primaryKeys The model's PK columns.
     * @return void
     * @throws \InvalidArgumentException When the column is not a declared
     *         primary key.
     */
    private function assertCompositeKeyColumn(string $column, array $primaryKeys): void
    {
        foreach ($primaryKeys as $primaryKey) {
            if ($primaryKey->name === $column) {
                return;
            }
        }

        throw new \InvalidArgumentException(
            "Composite key column [{$column}] is not a primary key of model "
                . "[{$this->modelClass}]; expected one of: "
                . implode(', ', array_map(fn(Column $pk) => $pk->name ?? '(unnamed)', $primaryKeys)) . '.'
        );
    }

    /**
     * Runtime boundary for a single-key value.
     *
     * @param mixed $id The key value as the caller supplied it.
     * @return int|string|null The validated value.
     * @throws \InvalidArgumentException When the value is not int, string,
     *         or null.
     */
    private function assertSingleKeyValue(mixed $id): int|string|null
    {
        if (!is_int($id) && !is_string($id) && $id !== null) {
            throw new \InvalidArgumentException(
                'A single primary-key value must be int, string or null; got ' . get_debug_type($id) . '.'
            );
        }

        return $id;
    }

    // ---- Model-aware overrides ----

    /**
     * Select columns, mapping `*` to the model's OWN columns.
     *
     * A bare `['*']` (the default) is replaced by every declared column —
     * the model's fields, not the raw table shape — with the PK columns
     * always present so hydration and `whereKey()` work. For aggregate
     * queries (GROUP BY) the PK must NOT be force-added — selecting a
     * non-grouped column would break the query.
     *
     * An {@see Expression} bypasses validation — raw SQL by contract, the
     * explicit raw-select path. Hydration note: an aliased raw select
     * (`new Expression('count(*) as total')`) yields models whose typed
     * properties are NOT set for the aggregate keys — read those off the
     * raw row via {@see getRaw()}.
     *
     * An {@see Aggregate} validates its column against the declared model
     * columns — the typed form of the old aggregate strings.
     *
     * VARIADIC: one column per argument. Calling with NO arguments resets
     * to the `['*']` default select — which then maps to the model's own
     * columns (the same `*` → declared columns path).
     *
     * @param string|Expression|Aggregate ...$columns Each column as its own argument, or none to reset to `*`.
     * @return static The builder.
     * @throws \InvalidArgumentException When an explicit string column is
     *         not a declared model column.
     */
    public function select(string|Expression|Aggregate ...$columns): static
    {
        $flat = $columns === [] ? ['*'] : array_values($columns);

        if ($flat === ['*']) {
            // `*` → the model's own columns (PK first, then the rest).
            $flat = array_values(array_unique(array_merge(
                $this->forcedKeys,
                array_diff($this->modelColumns, $this->forcedKeys),
            )));
        } else {
            foreach ($flat as $column) {
                if ($column instanceof Aggregate) {
                    // The aggregate's column gets the same allowlist check
                    // as a plain select column; an Expression argument is
                    // raw SQL by contract and passes through.
                    if (!$column->column instanceof Expression && $column->column !== '*') {
                        $this->validateColumn($column->column);
                    }
                } elseif (!$column instanceof Expression) {
                    $this->validateColumn($column);
                }
            }

            // An explicit list containing QUALIFIED specs (or `table.*`)
            // is caller-owned — typically a joined read where a bare PK
            // would be ambiguous. No forced-key merge. Every string spec has
            // been allowlist-validated above (validateColumn's qualified
            // branch checks the partition map / own table / joined tables),
            // so a typo'd or attacker-influenced `table.column` fails fast
            // here rather than compiling into the SQL quote-only.
            $callerOwned = (bool) array_filter(
                $flat,
                fn(string|Expression|Aggregate $column) => $column instanceof Expression || $column instanceof Aggregate
                    ? true
                    : str_contains($column, '.'),
            );

            if ($callerOwned) {
                return parent::select(...$flat);
            }
        }

        if ($this->getGroups() !== []) {
            return parent::select(...$flat);
        }

        // Merge forced keys (PK always selected), dedupe, preserve order.
        // At this point every entry is a validated string column (all
        // Expression entries exited via the caller-owned branch above — and
        // an aggregate query with an Expression select skips the merge too,
        // since grouping changes the shape). Filter defensively for the
        // type system: array_unique/array_merge need strings here.
        $stringColumns = array_values(array_filter(
            $flat,
            fn(string|Expression|Aggregate $column) => is_string($column),
        ));
        $merged = array_values(array_unique(array_merge($this->forcedKeys, $stringColumns)));

        return parent::select(...$merged);
    }

    /**
     * Add a where clause with model-aware column validation.
     *
     * Accepts plain column names, qualified `table.column` references, and
     * raw {@see Expression} fragments (raw by contract — no validation).
     * Aggregate left-hand sides are structurally impossible: the typed
     * {@see Aggregate} is not part of this signature (SQL forbids
     * aggregates in WHERE — they are having() territory).
     *
     * @param string|Expression $column The column to compare.
     * @param WhereOperator|string $operator The comparison operator.
     * @param mixed $value The value to compare against.
     * @param WhereBoolean $boolean The boolean connector.
     * @return static The builder.
     * @throws \InvalidArgumentException When a string column is not a
     *         declared model column (or a valid qualified reference).
     */
    public function where(
        string|Expression $column,
        WhereOperator|string $operator,
        mixed $value,
        WhereBoolean $boolean = WhereBoolean::And,
    ): static {
        if (!$column instanceof Expression) {
            $this->validateColumn($column);
        }

        return parent::where($column, $operator, $value, $boolean);
    }

    /**
     * Add an order-by clause with model-aware column validation.
     *
     * An {@see Expression} bypasses validation — it is raw SQL by contract,
     * the explicit escape hatch (never pass user-supplied content; see
     * docs/safety.md's raw-SQL rules).
     *
     * @param string|Expression $column The column to order by — or a raw
     *        SQL fragment wrapped in an Expression.
     * @param SortDirection|string $direction `ASC` or `DESC`.
     * @return static The builder.
     * @throws \InvalidArgumentException When a string column is not a
     *         declared model column.
     */
    public function orderBy(string|Expression $column, SortDirection|string $direction = SortDirection::Asc): static
    {
        if (!$column instanceof Expression) {
            $this->validateColumn($column);
        }

        return parent::orderBy($column, $direction);
    }

    /**
     * Group by columns with model-aware column validation.
     *
     * @param string|array<int, string> $columns The column(s) to group by.
     * @return static The builder.
     * @throws \InvalidArgumentException When a column is not a declared
     *         model column.
     */
    public function groupBy(string|array $columns): static
    {
        foreach (is_array($columns) ? $columns : [$columns] as $column) {
            $this->validateColumn($column);
        }

        return parent::groupBy($columns);
    }

    /**
     * Add a having clause with model-aware validation.
     *
     * An {@see Aggregate} left-hand side validates its COLUMN against the
     * declared model columns (the function/alias are validated by the
     * Aggregate itself); an {@see Expression} passes through raw. A string
     * column must be a declared model column.
     *
     * @param string|Expression|Aggregate $column The column (or aggregate) to compare.
     * @param WhereOperator|string $operator The comparison operator.
     * @param mixed $value The value to compare against.
     * @return static The builder.
     * @throws \InvalidArgumentException When a string column is not a
     *         declared model column, or an aggregate column is not declared.
     */
    public function having(string|Expression|Aggregate $column, WhereOperator|string $operator, mixed $value): static
    {
        if ($column instanceof Aggregate) {
            // The column of a typed aggregate gets the same allowlist check
            // as a plain column — the old string path bypassed it. An
            // Expression argument is raw SQL by contract.
            if (!$column->column instanceof Expression && $column->column !== '*') {
                $this->validateColumn($column->column);
            }

            return parent::having($column, $operator, $value);
        }

        if (!$column instanceof Expression) {
            $this->validateColumn($column);
        }

        return parent::having($column, $operator, $value);
    }

    /**
     * Fail fast on an unknown model column.
     *
     * Accepts a declared column name (DB column) or a forced PK key.
     * Aggregate left-hand sides no longer ride this path as strings — pass
     * a typed {@see Aggregate} to having()/select(), which validates the
     * aggregate's column through this method directly.
     *
     * @param string $column The column name to check.
     * @return void
     * @throws \InvalidArgumentException When the column is not declared on
     *         the model.
     */
    protected function validateColumn(string $column): void
    {
        // Hash-set lookups, not linear scans: every where/orderBy/groupBy/
        // having/select validates, so O(clauses × columns) list scans on
        // clause-heavy queries against wide models collapse to O(1) each.
        // The sets are immutable per builder — built lazily once.
        $columns = $this->columnSet ??= array_fill_keys($this->modelColumns, true);
        $forced = $this->forcedKeySet ??= array_fill_keys($this->forcedKeys, true);

        if (isset($columns[$column]) || isset($forced[$column])) {
            return;
        }

        // Strip a trailing `as alias` — the spec validates by its SOURCE
        // reference; the alias only names the result key (the Grammar owns
        // the AS rendering).
        $source = trim((string) preg_replace('/\s+as\s+\S+$/i', '', $column));

        if ($source !== $column && isset($columns[$source])) {
            return; // `column as alias` over a declared column.
        }

        // Qualified reference — `table.column[ as alias]`. The qualified
        // form names its table explicitly: the column must exist on the
        // NAMED table per the partition map (MTI), on a table this query
        // JOINs (the through-relation select spec), or on the builder's
        // OWN table — and in the own-table case the COLUMN part must still
        // be a declared model column (or `*`). Without that check a
        // `table.bogus` spec slipped through where the plain `bogus` form
        // would have failed fast — quoting prevents injection, but the
        // model layer's allowlist contract was not uniformly applied.
        if (str_contains($source, '.')) {
            [$table, $rest] = explode('.', $source, 2);

            if (($this->partitions !== [] && ($this->partitions[$rest] ?? null) === $table)
                || in_array($table, array_column($this->joins, 'table'), true)
            ) {
                return;
            }

            if ($table === $this->table) {
                if ($rest === '*' || isset($columns[$rest]) || isset($forced[$rest])) {
                    return;
                }
                // Own-table prefix but an unknown column — fall through to
                // the throw below, same as the unqualified form would.
            }
        }

        throw new \InvalidArgumentException(
            "Unknown column [{$column}] on model [{$this->modelClass}]."
        );
    }

    // ---- Writes (validated + encoded through the column casts) ----

    /**
     * Insert rows with model-aware validation and cast encoding.
     *
     * Every column key must be a declared model column (fail-fast, the
     * same contract `where()`/`select()` enforce) and every value runs
     * through its column's {@see Column::encode()} — so a builder-level
     * `insert(['created_at' => $carbon])` binds the same way a
     * `$model->save()` does. Null values are preserved (skip encode — it
     * passes null through anyway, but explicit is cheap and clear).
     *
     * @param array<string, mixed>|list<array<string, mixed>> $values A single
     *        row or a list of rows.
     * @return int The number of rows inserted.
     * @throws \InvalidArgumentException When a row key is not a declared
     *         model column.
     */
    public function insert(array $values): int
    {
        return parent::insert($this->encodeRows($values));
    }

    /**
     * Insert a single row and return the generated id, validated and
     * encoded like {@see insert()}.
     *
     * @param array<string, mixed> $values The row to insert.
     * @return string|int|null The generated id, or null when there is none.
     * @throws \InvalidArgumentException When a row key is not a declared
     *         model column.
     */
    public function insertGetId(array $values): string|int|null
    {
        return parent::insertGetId($this->encodeRow($values));
    }

    /**
     * Update the matching rows with model-aware validation and cast
     * encoding.
     *
     * The value map keys are validated against the declared columns and
     * each value encoded through its cast — `update(['views' => 5,
     * 'published_at' => $carbon])` binds exactly what a `$model->save()`
     * would write.
     *
     * @param array<string, mixed> $values The columns to change and their new values.
     * @return int How many rows were updated.
     * @throws \InvalidArgumentException When a key is not a declared
     *         model column.
     */
    public function update(array $values): int
    {
        return parent::update($this->encodeRow($values));
    }

    /**
     * Encode a SINGLE row through the column casts.
     *
     * Each key is validated and its value encoded through the column's
     * {@see Column::encode()} — `\DateTimeInterface` and array (JSON)
     * values bind identically to a model-level `save()`. A non-list input
     * IS the row; a list input (bulk insert) encodes row-by-row and keeps
     * the list shape.
     *
     * @param array<string, mixed>|list<array<string, mixed>> $values The input row(s).
     * @return array<string, mixed>|list<array<string, mixed>> The encoded row(s).
     * @throws \InvalidArgumentException When a row key is not a declared
     *         model column.
     */
    private function encodeRows(array $values): array
    {
        if (!array_is_list($values)) {
            return $this->encodeRow($values);
        }

        // A list whose first entry is NOT an array is a single associative
        // row that array_is_list cannot distinguish (PHP list keys are
        // ints) — but `insert(['name' => 'x'])` is never a list, so a list
        // of scalars is caller error and `array_map` below throws the
        // named validation error on its first key.
        $encoded = [];

        foreach ($values as $row) {
            $encodedRow = [];

            foreach ($row as $column => $value) {
                $name = (string) $column;
                $encodedRow[$name] = $this->encodeValue($name, $value);
            }

            $encoded[] = $encodedRow;
        }

        return $encoded;
    }

    /**
     * Encode one row map — the shared body of {@see encodeRows()}.
     *
     * Keys are normalized to their string form (an int-keyed entry can
     * only be caller error, and fails {@see validateWriteColumn()} with
     * the named column).
     *
     * @param array<int|string, mixed> $row The row: column => value.
     * @return array<string, mixed> The encoded row: column => bindable value.
     * @throws \InvalidArgumentException When a key is not a declared
     *         model column.
     */
    private function encodeRow(array $row): array
    {
        $encoded = [];

        foreach ($row as $column => $value) {
            $name = (string) $column;
            $encoded[$name] = $this->encodeValue($name, $value);
        }

        return $encoded;
    }

    /**
     * Validate one write-path column key and encode its value.
     *
     * @param string $column The DB column name.
     * @param mixed $value The value as the caller supplied it.
     * @return mixed The bindable (encoded) value.
     * @throws \InvalidArgumentException When the column is not declared.
     */
    private function encodeValue(string $column, mixed $value): mixed
    {
        $this->validateWriteColumn($column);

        return MetadataFactory::for($this->modelClass)
            ->mappingFor($column)
            ->column
            ->encode($value);
    }

    /**
     * Validate one write-path column key.
     *
     * The write path validates against the declared columns only — a
     * qualified spec is meaningless for a row map (there is one table per
     * statement root) and an aggregate-expression pass-through would be
     * nonsense on an INSERT/UPDATE. MTI children are handled upstream:
     * `performMtiInsert()`/`performMtiUpdate()` partition writes per
     * owning table through plain per-table builders, so a model-level
     * write never reaches here with cross-table columns.
     *
     * @param string $column The column key to check.
     * @return void
     * @throws \InvalidArgumentException When the column is not declared.
     */
    private function validateWriteColumn(string $column): void
    {
        if (!MetadataFactory::for($this->modelClass)->hasColumn($column)) {
            throw new \InvalidArgumentException(
                "Unknown column [{$column}] on model [{$this->modelClass}]."
            );
        }
    }
}
