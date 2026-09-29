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
 * The model's own columns are the default select, every column-accepting
 * method validates against the declared set, and the soft-delete scope is
 * auto-applied so every execution path respects it.
 *
 * @template-covariant TModel of Model
 * @phpstan-import-type KeyValue from \BlueprintAU\Radiant\Model
 */
final class ModelQueryBuilder extends QueryBuilder
{
    /**
     * The columns always force-selected so hydration keeps the identity
     * (PK) and trash-state (soft-delete) columns available — `whereKey()`
     * needs the former, `trashed()` and save()'s soft-deleted guard the
     * latter.
     *
     * @var list<string>
     */
    protected array $forcedKeys = [];

    /**
     * Every declared column name — the default select and the validation
     * set for every column-accepting method.
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
     * The MTI ancestor chain (nearest parent first), each as
     * [class, table]. Empty for non-MTI models.
     *
     * @var list<array{class-string<Model>, string}>
     */
    protected array $mtiChain = [];

    /**
     * Memoized relation resolutions, keyed by "class::method".
     *
     * @var array<string, Relations\Relation<Model>>
     */
    protected static array $relationCache = [];

    /**
     * Invalidate the memoized relation-resolution cache.
     *
     * @param  string|null  $class  Clear only this class's relations; null clears everything.
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
     * Whether this builder is a nested where group — nested builders skip
     * trait-scope application (the scopes ride the OUTER builder; applying
     * them here would recurse: whereNested → newNestedBuilder → ctor →
     * whereNested).
     *
     * @var bool
     */
    private bool $nested = false;

    /**
     * Create a builder bound to a model class on a connection.
     *
     * @param  class-string<TModel>  $modelClass
     * @param  ConnectionInterface  $connection
     * @param  bool  $nested  Whether this builder is a nested where group (skips trait-scope application — the scopes ride the OUTER builder; applying them here would recurse).
     */
    public function __construct(
        public readonly string $modelClass,
        ConnectionInterface $connection,
        bool $nested = false,
    ) {
        $this->nested = $nested;

        $metadata = MetadataFactory::for($modelClass);

        $this->forcedKeys = array_values(array_filter(array_map(
            fn(Column $column) => $column->name ?? '',
            $metadata->primaryKeys,
        ), fn(string $name) => $name !== ''));

        // The soft-delete column force-selects alongside the PKs: a narrow
        // caller select that omits it hydrates models whose trash state is
        // unknown — trashed() reads false and save()'s soft-deleted guard
        // passes, letting an UPDATE under the auto-scope match 0 rows while
        // reporting success.
        if ($metadata->softDeleteColumn !== null
            && !in_array($metadata->softDeleteColumn, $this->forcedKeys, true)
        ) {
            $this->forcedKeys[] = $metadata->softDeleteColumn;
        }

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
        // Auto-apply every trait-declared scope. A trait's conditions group
        // in ONE nested where group marked with the trait (`traitScope`
        // marker) so the opt-outs (withTrashed/withoutScope/withoutScopes)
        // strip the trait's whole scope atomically by MARKER, not positional
        // index. Between traits the groups always AND — each trait's scope
        // is a hard filter. MTI: the scope qualifies to the OWNING table
        // (the column lives where the trait declared it).
        // Nested builders skip this entirely — the scopes ride the OUTER
        // builder; applying them here would recurse (whereNested →
        // newNestedBuilder → ctor → whereNested).
        $conditionsByTrait = [];

        if (!$this->nested) {
            foreach ($metadata->traitScopes as ['trait' => $scopeTrait, 'condition' => $condition]) {
                $conditionsByTrait[$scopeTrait][] = $condition;
            }
        }

        foreach ($conditionsByTrait as $scopeTrait => $conditions) {
            // The constructor is the ONE place a builder finalizes its own
            // state: the scope rides the instance being built, then is
            // marked for marker-based removal.
            $scoped = $this->whereNested(
                function (WhereBuilder $nested) use ($conditions): WhereBuilder {
                    // WhereBuilder is immutable — each where() returns a
                    // NEW facade; thread it through the loop.
                    $builder = $nested;

                    foreach ($conditions as $index => $condition) {
                        $column = $condition->column;

                        if (isset($this->partitions[$column])) {
                            $column = $this->partitions[$column] . '.' . $column;
                        }

                        // The first condition in the group carries no
                        // boolean — the group's internal join starts with
                        // the SECOND condition's declared boolean.
                        $builder = $index === 0
                            ? $builder->where($column, $condition->operator, $condition->value)
                            : $builder->where($column, $condition->operator, $condition->value, $condition->boolean);
                    }

                    return $builder;
                },
            );
            $scoped->markLastWhereTraitScope($scopeTrait);
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
     * @param  list<string>  $relations
     * @return static
     * @throws \InvalidArgumentException
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
     * Assert one eager-load path is a non-empty string.
     *
     * The `list<string>` element contract is PHPDoc-only — callers without
     * a static analyzer can pass anything — so the shape is checked here,
     * where the parameter is genuinely untyped and PHPStan cannot call the
     * check redundant.
     *
     * @param  mixed  $path  Must be a non-empty string naming a relation path.
     * @return string
     * @throws \InvalidArgumentException
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
     * @param  string  $path
     * @return void
     * @throws \InvalidArgumentException
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
     * @param  class-string<Model>  $class
     * @param  string  $name
     * @param  string  $path
     * @return Relations\Relation<Model>
     * @throws \InvalidArgumentException
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
     * row multiplication.
     *
     * @param  Collection<Model>  $models
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
     * @param  Collection<Model>  $models
     * @param  string  $path
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
     * @param  list<Model>  $parents
     * @param  Relations\Relation<Model>  $relation
     * @param  string  $name
     * @param  string|null  $nested
     * @param  string  $path
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
     * @param  \BlueprintAU\Radiant\Metadata\ClassMetadata  $metadata
     * @return array<string, string>
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
     * level's columns qualified + aliased back to the plain columnName.
     *
     * @param  \BlueprintAU\Radiant\Metadata\ClassMetadata  $metadata
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
     * @param  string  $first
     * @param  ColumnOperator|string  $operator
     * @param  string  $second
     * @param  WhereBoolean  $boolean
     * @return static
     * @throws \InvalidArgumentException
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
     * @param  string  $sql
     * @param  array<int, mixed>  $bindings
     * @param  WhereBoolean  $boolean
     * @return static
     */
    public function whereRaw(string $sql, array $bindings = [], WhereBoolean $boolean = WhereBoolean::And): static
    {
        return parent::whereRaw($sql, $bindings, $boolean);
    }

    /**
     * Create a new builder for a nested where group.
     *
     * @return QueryBuilder
     * @throws \InvalidArgumentException
     */
    protected function newNestedBuilder(): QueryBuilder
    {
        return new self($this->modelClass, $this->connection, nested: true);
    }

    /**
     * Append an ON condition to the last added join, with validation.
     *
     * @param  string  $first
     * @param  ColumnOperator|string  $operator
     * @param  string  $second
     * @return static
     * @throws \LogicException
     * @throws \InvalidArgumentException
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
     * @param  string  $first
     * @param  ColumnOperator|string  $operator
     * @param  string  $second
     * @return static
     * @throws \LogicException
     * @throws \InvalidArgumentException
     */
    public function orOn(string $first, ColumnOperator|string $operator = '=', string $second = ''): static
    {
        $this->validateColumn($first);
        $this->validateColumn($second);

        return parent::orOn($first, $operator, $second);
    }

    // ---- Trait scopes ----

    /**
     * Include soft-deleted rows — strips only the SoftDeletes scope.
     *
     * @return static
     */
    public function withTrashed(): static
    {
        return $this->withoutScope(SoftDeletes::class);
    }

    /**
     * Only soft-deleted rows — strips the scope and adds a marked
     * `whereNotNull` so the toggle round-trips.
     *
     * @return static
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
        $scoped->markLastWhereTraitScope(SoftDeletes::class);

        $clone = clone $scoped;
        return $clone;
    }

    /**
     * Strip every where clause declared by one trait's scope.
     *
     * @param  class-string  $trait
     * @return static
     */
    public function withoutScope(string $trait): static
    {
        $wheres = $this->getWheres();

        $filtered = array_values(array_filter(
            $wheres,
            fn(array $where): bool => ($where['traitScope'] ?? null) !== $trait,
        ));

        if ($filtered === $wheres) {
            return $this; // nothing to remove — reuse the instance.
        }

        $clone = clone $this;
        $clone->wheres = $filtered;
        return $clone;
    }

    /**
     * Strip every trait-declared scope.
     *
     * @return static
     */
    public function withoutScopes(): static
    {
        $wheres = $this->getWheres();

        $filtered = array_values(array_filter(
            $wheres,
            fn(array $where): bool => !array_key_exists('traitScope', $where),
        ));

        if ($filtered === $wheres) {
            return $this; // nothing to remove — reuse the instance.
        }

        $clone = clone $this;
        $clone->wheres = $filtered;
        return $clone;
    }

    // ---- Execution (hydration) ----

    /**
     * Run the query and hydrate every row into a model.
     *
     * @return Collection<TModel>
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
     * Get the raw rows (stdClass), bypassing hydration.
     *
     * @return BaseCollection<int, \stdClass>
     */
    public function getRaw(): BaseCollection
    {
        return parent::get();
    }

    /**
     * Stream the query, hydrating each row into a model as it arrives.
     *
     * Eager loads cannot ride a stream — use `get()` when relations are
     * required.
     *
     * @return \Generator<int, TModel>
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
     * @return TModel|null
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
     * @param  KeyValue  $id  The primary-key value, or a column => value map for a composite key.
     * @return TModel|null
     */
    public function find(int|string|null|array $id): ?Model
    {
        return $this->whereKey($id)->first();
    }

    /**
     * Get the first hydrated row or throw if no rows match.
     *
     * @return TModel
     *
     * @throws ModelNotFoundException
     */
    public function firstOrFail(): Model
    {
        return (clone $this)->firstOrFailWithKey(null);
    }

    /**
     * Find a model by primary key or throw if it does not exist.
     *
     * @param  KeyValue  $id  The primary-key value, or a column => value map for a composite key.
     * @return TModel
     *
     * @throws ModelNotFoundException
     */
    public function findOrFail(int|string|null|array $id): Model
    {
        // whereKey() also runs on the clone — the added wheres never land
        // on the shared builder.
        return (clone $this)->whereKey($id)->firstOrFailWithKey($id);
    }

    /**
     * Get the single matching row or throw if the count differs.
     *
     * @return TModel
     *
     * @throws ModelNotFoundException
     * @throws MultipleRecordsFoundException
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
     * @param  KeyValue|null  $keyForMessage  The lookup key for the exception, or null.
     * @return TModel
     *
     * @throws ModelNotFoundException
     */
    private function firstOrFailWithKey(int|string|null|array $keyForMessage): Model
    {
        $model = $this->first();

        if ($model === null) {
            throw new ModelNotFoundException($this->modelClass, $keyForMessage);
        }

        return $model;
    }

    // ---- Scalar reads (decoded through the column casts) ----

    /**
     * The value of a single column from the first row, decoded through
     * the column's cast.
     *
     * Raw SQL and user-aliased columns pass through raw — the model layer
     * has no cast for a computed value.
     *
     * @param  string|Aggregate  $column
     * @return mixed
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
     * A collection of a single column's values, decoded through the casts.
     *
     * @param  string  $column
     * @return BaseCollection<int, mixed>
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
     * The clone carries the constraints (wheres, joins, soft-delete state)
     * but owns its own column list — the bypass primitive for internal
     * scalar reads, where `select()` cannot be used (it re-merges the
     * forced PK and validates against the declared columns).
     *
     * @param  string|Aggregate  ...$sql
     * @return static
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
     * @param  string  $column
     * @param  mixed  $raw
     * @return mixed
     */
    private function decodeScalar(string $column, mixed $raw): mixed
    {
        $bare = trim((string) preg_replace('/\s+as\s+\S+$/i', '', $column));
        $metadata = MetadataFactory::for($this->modelClass);

        if (!$metadata->hasColumn($bare)) {
            return $raw;
        }

        $mapping = $metadata->mappingFor($bare);

        return $mapping->column->decode($raw, $mapping->propertyType);
    }

    // ---- Aggregates (decoded like every other scalar read) ----

    /**
     * Count the matching rows.
     *
     * @return int
     */
    public function count(): int
    {
        return (int) $this->value(Aggregate::count());
    }

    /**
     * The maximum value of a column, decoded through the cast for
     * declared columns.
     *
     * @param  string  $column
     * @return mixed
     */
    public function max(string $column): mixed
    {
        return $this->value(Aggregate::max($column));
    }

    /**
     * The minimum value of a column, decoded through the cast for
     * declared columns.
     *
     * @param  string  $column
     * @return mixed
     */
    public function min(string $column): mixed
    {
        return $this->value(Aggregate::min($column));
    }

    /**
     * The sum of a column's values, decoded through the cast for
     * declared columns.
     *
     * @param  string  $column
     * @return mixed
     */
    public function sum(string $column): mixed
    {
        return $this->value(Aggregate::sum($column));
    }

    /**
     * The average of a column's values, decoded through the cast for
     * declared columns.
     *
     * @param  string  $column
     * @return mixed
     */
    public function avg(string $column): mixed
    {
        return $this->value(Aggregate::avg($column));
    }

    /**
     * Multiple aggregates in one query, decoded through each aggregate's
     * column cast.
     *
     * The aggregate's own alias names its result column:
     *
     *     User::query()->aggregates(
     *         Aggregate::count('*', 'total'),
     *         Aggregate::max('signed_up_at', 'latest'),
     *     )->latest;
     *
     * @param  Aggregate  ...$aggregates
     * @return \stdClass
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
     * Run one aggregate per group of the matching rows — a grouped
     * aggregate in a single query.
     *
     * The result is keyed by the group column's value, so the aggregate's
     * own alias is ignored here (it matters only for the multi-aggregate
     * row shape of aggregates()). Declared columns decode through the
     * column's cast; `count` is always an int.
     *
     * @param  Aggregate  $aggregate  The aggregate to compute per group.
     * @param  string  $groupBy  The column whose values key the result.
     * @return BaseCollection<string, mixed>
     */
    public function aggregateBy(Aggregate $aggregate, string $groupBy): BaseCollection
    {
        $rows = $this->scopedFor($groupBy, new Aggregate($aggregate->function, $aggregate->column, self::AGGREGATE_ALIAS))
            ->groupBy($groupBy)
            ->getRaw();

        $out = [];

        foreach ($rows as $row) {
            $key = (string) $row->{$groupBy};
            $raw = $row->{self::AGGREGATE_ALIAS};

            $out[$key] = $aggregate->function === 'count'
                ? (int) $raw
                : $this->decodeAggregateColumn($aggregate->column, $raw);
        }

        /** @var BaseCollection<string, mixed> */
        return BaseCollection::make($out);
    }

    /**
     * Count the matching rows per group of a column — in a single query.
     *
     * The result is keyed by the group column's value with int counts.
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
     */
    public function countBy(string $column, ?array $seed = null): BaseCollection
    {
        /** @var BaseCollection<string, int> $counts */
        $counts = $this->aggregateBy(Aggregate::count('*'), $column);

        if ($seed !== null) {
            $out = $counts->all();

            foreach ($seed as $value) {
                $key = (string) $value;
                $out[$key] ??= 0;
            }

            /** @var BaseCollection<string, int> */
            return BaseCollection::make($out);
        }

        return $counts;
    }

    /**
     * Decode one grouped-aggregate value when the aggregated column is a
     * declared model column.
     *
     * Mirrors the scalar decode: declared columns decode through the
     * column's cast, everything else (raw SQL, Expression arguments,
     * computed values) passes through raw.
     *
     * @param  string|Expression  $column
     * @param  mixed  $raw
     * @return mixed
     */
    private function decodeAggregateColumn(string|Expression $column, mixed $raw): mixed
    {
        if ($column instanceof Expression || $raw === null) {
            return $raw;
        }

        return $this->decodeScalar($column, $raw);
    }

    /**
     * Constrain the query to a primary-key value.
     *
     * Accepts a scalar (the single-PK form), a column => value map (the
     * composite-key form), or a list of either (the batching form, match
     * ANY).
     *
     * @param  KeyValue|list<KeyValue>  $id
     * @return static
     * @throws \InvalidArgumentException
     */
    public function whereKey(int|string|null|array $id): static
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
        // The whole OR-of-groups lands INSIDE one outer AND-group: the
        // key set is ONE constraint unit. The constructor auto-applies
        // trait scopes (e.g. soft-delete `deleted_at IS NULL`) as leading
        // AND-groups — flat top-level ORs would compile to
        // `(scope) OR (pk = 1) OR ...` and let a scope-excluded row back
        // in whenever its key matched. Grouped, the scope ANDs against
        // the whole set: `(scope) AND ((pk = 1) OR (pk = 2) OR ...)`.
        //
        // No chunking: whereKey() returns ONE builder, so every key
        // compiles into the same statement regardless of how the loop is
        // sliced — chunking the loop cannot bound the statement. Splitting
        // the keys across SEPARATE top-level groups would AND the chunks
        // together (a row would need a key in EVERY chunk to match), so
        // the only correct shape is one group holding all the keys. An
        // oversized list therefore hits the driver's own placeholder cap
        // (SQLite's 999 variables, MySQL's max_allowed_packet) with the
        // driver's error — the same exposure every whereIn([...]) has.
        if (is_array($id) && array_is_list($id)) {
            if ($id === []) {
                return $this->whereRaw('1 = 0');
            }

            return $this->whereNested(
                function (WhereBuilder $nested) use ($id): WhereBuilder {
                    $grouped = $nested;

                    foreach ($id as $key) {
                        $grouped = $grouped->orWhereNested(
                            fn (WhereBuilder $keyGroup): WhereBuilder => $this->applyWhereKeyOn($keyGroup, $key)
                        );
                    }

                    return $grouped;
                }
            );
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
                $id,
            );
        }

        return $this->where($pkName, WhereOperator::Eq, $id);
    }

    /**
     * Apply ONE key value onto a where-group — the shared body of
     * {@see whereKey()}'s single and list branches.
     *
     * @param  WhereBuilder  $nested
     * @param  mixed  $key  The scalar key value or column => value map.
     * @return WhereBuilder
     * @throws \InvalidArgumentException
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

        // Runtime boundary: `$key` is a list ELEMENT — the KeyValue scalar
        // contract is PHPDoc-only, so an untyped caller can pass anything.
        // The native whereKey() union already TypeErrors at the public
        // boundary; this guard covers the mixed path behind it.
        if (!is_int($key) && !is_string($key) && $key !== null) {
            throw new \InvalidArgumentException(
                'A single primary-key value must be int, string or null; got ' . get_debug_type($key) . '.'
            );
        }

        $single = $key;

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
     * Validate one composite-key entry and its value.
     *
     * @param  mixed  $column  Must be a non-empty string naming a declared PK column.
     * @param  mixed  $value  Must be int, string or null.
     * @return array{column: string, value: int|string|null}
     * @throws \InvalidArgumentException
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
     * @param  string  $column
     * @param  list<Column>  $primaryKeys
     * @return void
     * @throws \InvalidArgumentException
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

    // ---- Model-aware overrides ----

    /**
     * Select columns, mapping `*` to the model's own columns.
     *
     * The PK columns and the soft-delete column (when the model uses
     * {@see SoftDeletes}) are always present so hydration, `whereKey()`
     * and the trash-state reads work. An {@see Expression} bypasses
     * validation — raw SQL by contract.
     *
     * @param  string|Expression|Aggregate  ...$columns  Each column as its own argument, or none to reset to `*`.
     * @return static
     * @throws \InvalidArgumentException
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
     * @param  string|Expression  $column
     * @param  WhereOperator|string  $operator
     * @param  mixed  $value
     * @param  WhereBoolean  $boolean
     * @return static
     * @throws \InvalidArgumentException
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
     * @param  string|Expression  $column
     * @param  SortDirection|string  $direction
     * @return static
     * @throws \InvalidArgumentException
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
     * @param  string|array<int, string>  $columns
     * @return static
     * @throws \InvalidArgumentException
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
     * @param  string|Expression|Aggregate  $column
     * @param  WhereOperator|string  $operator
     * @param  mixed  $value
     * @return static
     * @throws \InvalidArgumentException
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
     * @param  string  $column
     * @return void
     * @throws \InvalidArgumentException
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
     * @param  array<string, mixed>|list<array<string, mixed>>  $values
     * @return int
     * @throws \InvalidArgumentException
     */
    public function insert(array $values): int
    {
        return parent::insert($this->encodeRows($values));
    }

    /**
     * Insert a single row and return the generated id, validated and
     * encoded like {@see insert()}.
     *
     * @param  array<string, mixed>  $values
     * @return string|int|null
     * @throws \InvalidArgumentException
     */
    public function insertGetId(array $values): string|int|null
    {
        return parent::insertGetId($this->encodeRow($values));
    }

    /**
     * Update the matching rows with model-aware validation and cast
     * encoding.
     *
     * @param  array<string, mixed>  $values
     * @return int
     * @throws \InvalidArgumentException
     */
    public function update(array $values): int
    {
        return parent::update($this->encodeRow($values));
    }

    /**
     * Encode a single row through the column casts.
     *
     * @param  array<string, mixed>|list<array<string, mixed>>  $values
     * @return array<string, mixed>|list<array<string, mixed>>
     * @throws \InvalidArgumentException
     */
    private function encodeRows(array $values): array
    {
        if (!array_is_list($values)) {
            return $this->encodeRow($values);
        }

        // A list whose entries are NOT arrays is caller error — a list of
        // scalars (`insert(['name', 'age'])`) is never a valid row set.
        // Delegating each entry to encodeRow() makes that fail fast: its
        // native `array` parameter TypeErrors on a scalar, where a
        // duplicated foreach would only WARN and silently encode empty
        // rows (PHP 8 foreach-over-string skips the loop).
        $encoded = [];

        foreach ($values as $row) {
            $encoded[] = $this->encodeRow($row);
        }

        return $encoded;
    }

    /**
     * Encode one row map — the shared body of {@see encodeRows()}.
     *
     * @param  array<int|string, mixed>  $row
     * @return array<string, mixed>
     * @throws \InvalidArgumentException
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
     * @param  string  $column
     * @param  mixed  $value
     * @return mixed
     * @throws \InvalidArgumentException
     */
    private function encodeValue(string $column, mixed $value): mixed
    {
        $this->validateWriteColumn($column);

        $mapping = MetadataFactory::for($this->modelClass)->mappingFor($column);

        return $mapping->column->encode($value, $mapping->propertyType);
    }

    /**
     * Validate one write-path column key.
     *
     * @param  string  $column
     * @return void
     * @throws \InvalidArgumentException
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
