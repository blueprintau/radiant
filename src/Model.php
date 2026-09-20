<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant;

use BlueprintAU\Radiant\Concerns\FiltersStaticQuery;
use BlueprintAU\Radiant\Database\Connections\ConnectionInterface;
use BlueprintAU\Radiant\Database\Connections\SqlConnection;
use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Query\Enums\SortDirection;
use BlueprintAU\Radiant\Database\Query\Enums\WhereBoolean;
use BlueprintAU\Radiant\Database\Query\Enums\WhereOperator;
use BlueprintAU\Radiant\Metadata\MetadataFactory;
use BlueprintAU\Radiant\Metadata\PropertyMapping;

/**
 * The Active Record base model.
 *
 * Typed properties + `#[Column]` attributes declare the schema; the
 * {@see MetadataFactory} builds the per-class metadata once and caches it.
 * Core CRUD runs on any {@see ConnectionInterface} — the portable subset
 * (`select`/`insert`/`update`/`delete`) — while SQL-only extras stay gated
 * at the connection/builder layer.
 *
 * Hydration reconstitutes instances without the constructor
 * (`newInstanceWithoutConstructor()`), so `Model` subclasses need no
 * constructor ceremony; dirty tracking compares against the `$original`
 * snapshot taken at hydration/save time.
 *
 * @phpstan-type KeyValue int|string|null|array<string, int|string|null>
 */
abstract class Model
{
    /** @use FiltersStaticQuery<Model> */
    use FiltersStaticQuery;

    /**
     * The reserved alias prefix for the ORM's internal select aliases.
     *
     * Every synthetic select alias the ORM splices into a query starts
     * with `radiant_` — `radiant_pivot_{column}` (BelongsToMany/MorphToMany
     * pivot columns), `radiant_pivot_parent_{table}` (the through-pivot
     * parent key), `radiant_scalar` (aggregate reads), and
     * `radiant_through_parent_{table}` (through-relation parent keys).
     * The lift in {@see Model::fromRow()} treats any row field with this
     * prefix as internal state, so a USER COLUMN named `radiant_foo`
     * would collide: inside a pivot select it would silently hijack the
     * pivot value slot (and vice versa). The prefix is therefore
     * RESERVED — columns and pivot columns must not start with it.
     *
     * The constant lives HERE (not on a relation or the query layer)
     * because the collision surface is model-shaped: the row lift runs
     * on Model, and every alias-bearing feature — relations today, any
     * query-layer feature tomorrow — turns user-named columns into
     * these aliases. {@see Model::assertNotReservedPrefix()} is the
     * shared fail-fast guard.
     */
    public const RESERVED_PREFIX = 'radiant_';

    /**
     * The loaded values at hydration time, keyed by column name — the
     * hydration/save-time snapshot dirty tracking compares against.
     *
     * Values live in the ENCODED (bindable) space — the same space
     * {@see Model::getColumnValues()} produces — so the `!=` comparison in
     * {@see Model::getDirty()} compares like with like. (The raw DB row is
     * a DIFFERENT space — `'2026-09-06 12:00:00'` strings vs Carbon
     * objects — and comparing across it would make every datetime column
     * permanently dirty.)
     *
     * Synthetic columns (the runtime {@see Model::$syntheticValues} store)
     * land here too, so {@see Model::getKeyForRefresh()} and trait-level
     * checks read one consistent shape.
     *
     * @var array<string, mixed>
     */
    protected array $original = [];

    /**
     * Runtime overrides for synthetic columns — columns the metadata
     * declares but no PHP property backs (the SoftDeletes column injected
     * by the {@see MetadataFactory} when the model declares none itself).
     *
     * EMPTY after hydration: the loaded value lives in {@see Model::$original}
     * and {@see Model::attribute()} decodes it on demand. This store only
     * fills when a trait writes a NEW value post-load (via
     * {@see Model::setAttribute()}) — the synthetic column has no typed
     * property to hold it in. Never a dynamic property.
     *
     * @var array<string, mixed>
     */
    protected array $syntheticValues = [];

    /**
     * Whether the model exists in the database (was inserted/hydrated).
     *
     * @var bool
     */
    protected bool $exists = false;

    /**
     * Loaded relation results, keyed by relation name.
     *
     * Written by the eager loader (via {@see Relation::match()}) and read
     * by {@see Relation::getResults()} through {@see Model::cachedRelation()}
     * — a relation method's own access returns the cache when the relation
     * is loaded and unfiltered, and executes fresh otherwise.
     *
     * @var array<string, Model|Collection<Model>|null>
     */
    protected array $relations = [];

    /**
     * Pivot values carried onto this model by a BelongsToMany eager load,
     * keyed by pivot column name.
     *
     * Written by the relation's hydration pass (the `radiant_pivot_`
     * aliased select columns); read through {@see Model::pivotValue()}.
     * Per-call state on the instance — a model hydrated WITHOUT a pivot
     * join simply has none.
     *
     * @var array<string, mixed>
     */
    protected array $pivotValues = [];

    /**
     * Fail fast when a column name starts with the ORM's reserved prefix.
     *
     * The shared guard for every surface that turns user-named columns
     * into internal select aliases — today {@see BelongsToMany::withPivot()},
     * tomorrow any new alias-bearing feature. Reserved names are a data
     * bug caught at the call site, not silent row-shape corruption.
     *
     * @param string $column The user-facing column name to check.
     * @param string $role What the column is (for the message).
     * @return void
     * @throws \InvalidArgumentException When the column starts with
     *         {@see RESERVED_PREFIX}.
     */
    final public static function assertNotReservedPrefix(string $column, string $role): void
    {
        if (str_starts_with($column, self::RESERVED_PREFIX)) {
            throw new \InvalidArgumentException(
                "The {$role} [{$column}] starts with the reserved prefix ["
                . self::RESERVED_PREFIX . '] — the ORM uses that namespace for its internal '
                . 'select aliases (radiant_pivot_*, radiant_scalar, radiant_through_parent_*). '
                . 'Rename the column.'
            );
        }
    }

    // ---- Connection ----

    /**
     * Default connection name for this model.
     *
     * Null = the manager's default. A model that always runs on a named
     * connection (e.g. a tenant model) pins itself with one line:
     * `protected static ?string $connection = 'tenant';`
     *
     * @var string|null
     */
    protected static ?string $connection = null;

    /**
     * The connection this model runs on — the public resolution entry point.
     *
     * @return ConnectionInterface The resolved connection.
     */
    final public static function connection(): ConnectionInterface
    {
        return static::resolveConnection();
    }

    /**
     * The single seam for connection resolution.
     *
     * Default falls through to the Database facade (the ORM's one ambient
     * dependency) via its public `connection()` entry point; the `static::`
     * late binding makes the method overridable per model class and in
     * tests without mutating global facade state.
     *
     * @return ConnectionInterface The resolved connection.
     */
    protected static function resolveConnection(): ConnectionInterface
    {
        return Database::connection(static::$connection);
    }

    /**
     * The table name for this model.
     *
     * Computed once by {@see MetadataFactory} and cached in
     * {@see \BlueprintAU\Radiant\Metadata\ClassMetadata} — a static name by
     * design; dynamic (per-tenant/partitioned) names are deliberately
     * unsupported at the model layer (see the `#[Table]` attribute notes)
     * and belong at the connection layer.
     *
     * @return string The resolved table name.
     */
    public static function table(): string
    {
        $tableName = MetadataFactory::for(static::class)->tableName;

        if ($tableName === null) {
            throw new \LogicException(
                'Model [' . static::class . '] declares no columns of its own and resolves no '
                . 'table. Add #[Column] properties, or extend a table-owning model '
                . 'behavior-only (no new columns, no #[Table]).'
            );
        }

        return $tableName;
    }

    // ---- Query entry points ----

    /**
     * A fresh model query builder for this class.
     *
     * @return ModelQueryBuilder<static> The query builder.
     */
    public static function newQuery(): ModelQueryBuilder
    {
        return new ModelQueryBuilder(static::class, static::connection());
    }

    /**
     * Find a model by its primary key.
     *
     * @param KeyValue $id The primary-key value (or a column => value map
     *        for a composite key).
     * @return static|null The model, or null when not found.
     */
    public static function find(mixed $id): ?static
    {
        return static::newQuery()->find($id);
    }

    /**
     * Find a model by its primary key — or throw when it does not exist.
     *
     * The fail-fast counterpart of {@see find()}: a missing row raises
     * {@see ModelNotFoundException} carrying the model class and the key.
     *
     * @param KeyValue $id The primary-key value (or a column => value map
     *        for a composite key).
     * @return static The model.
     *
     * @throws \BlueprintAU\Radiant\Database\Exceptions\ModelNotFoundException When no row matches the key.
     */
    public static function findOrFail(mixed $id): static
    {
        return static::newQuery()->findOrFail($id);
    }

    /**
     * Hydrate the first model of the table — or throw when the table is empty.
     *
     * The fail-fast counterpart of the builder's `first()`: an empty
     * result raises {@see \BlueprintAU\Radiant\Database\Exceptions\ModelNotFoundException}.
     *
     * @return static The first model.
     *
     * @throws \BlueprintAU\Radiant\Database\Exceptions\ModelNotFoundException When no row matches.
     */
    public static function firstOrFail(): static
    {
        return static::newQuery()->firstOrFail();
    }

    /**
     * Require the table to hold EXACTLY ONE row, hydrated.
     *
     * Zero rows raise {@see \BlueprintAU\Radiant\Database\Exceptions\ModelNotFoundException};
     * more than one raise
     * {@see \BlueprintAU\Radiant\Database\Exceptions\MultipleRecordsFoundException}.
     *
     * @return static The single model.
     *
     * @throws \BlueprintAU\Radiant\Database\Exceptions\ModelNotFoundException When no row matches.
     * @throws \BlueprintAU\Radiant\Database\Exceptions\MultipleRecordsFoundException When more than one row matches.
     */
    public static function sole(): static
    {
        return static::newQuery()->sole();
    }

    /**
     * Every model in the table.
     *
     * @return Collection<static> The hydrated models.
     */
    public static function all(): Collection
    {
        return static::newQuery()->get();
    }

    // ---- Static filter-modifier forwarders ----

    /**
     * Start a model query with a where clause — the sink the shared static
     * filter trait funnels the where-family helpers into. The trait also
     * requires orderBy/limit/offset/select/groupBy/having (not
     * where-derivable — they start a fresh query); this class implements
     * them directly below the sink.
     *
     * @param string $column The column to compare.
     * @param WhereOperator|string $operator The comparison operator.
     * @param mixed $value The value to compare against.
     * @param WhereBoolean $boolean The boolean connector.
     * @return ModelQueryBuilder<static> The query builder.
     */
    public static function where(
        string $column,
        WhereOperator|string $operator,
        mixed $value,
        WhereBoolean $boolean = WhereBoolean::And,
    ): ModelQueryBuilder {
        return static::newQuery()->where($column, $operator, $value, $boolean);
    }

    /**
     * Start a model query with a nested where group — the second static
     * sink; `orWhereNested` delegates here.
     *
     * @param callable(\BlueprintAU\Radiant\Database\Query\WhereBuilder): void $callback Receives the group's
     *        where-family facade to constrain.
     * @param WhereBoolean $boolean The boolean connector.
     * @return ModelQueryBuilder<static> The query builder.
     */
    public static function whereNested(
        callable $callback,
        WhereBoolean $boolean = WhereBoolean::And,
    ): ModelQueryBuilder {
        return static::newQuery()->whereNested($callback, $boolean);
    }

    /**
     * Start a model query with an order-by clause.
     *
     * @param string $column The column to order by.
     * @param SortDirection|string $direction `ASC` or `DESC`.
     * @return ModelQueryBuilder<static> The query builder.
     */
    public static function orderBy(
        string $column,
        SortDirection|string $direction = SortDirection::Asc,
    ): ModelQueryBuilder {
        return static::newQuery()->orderBy($column, $direction);
    }

    /**
     * Start a model query with a row limit.
     *
     * @param int $limit The row limit.
     * @return ModelQueryBuilder<static> The query builder.
     */
    public static function limit(int $limit): ModelQueryBuilder
    {
        return static::newQuery()->limit($limit);
    }

    /**
     * Start a model query with a row offset.
     *
     * @param int $offset The number of rows to skip.
     * @return ModelQueryBuilder<static> The query builder.
     */
    public static function offset(int $offset): ModelQueryBuilder
    {
        return static::newQuery()->offset($offset);
    }

    /**
     * Start a model query with an explicit column selection.
     *
     * @param string|\BlueprintAU\Radiant\Database\Query\Expression|\BlueprintAU\Radiant\Database\Query\Aggregate ...$columns Each column as its own argument, or none to reset to `*`.
     * @return ModelQueryBuilder<static> The query builder.
     */
    public static function select(string|\BlueprintAU\Radiant\Database\Query\Expression|\BlueprintAU\Radiant\Database\Query\Aggregate ...$columns): ModelQueryBuilder
    {
        return static::newQuery()->select(...$columns);
    }

    /**
     * Start a model query grouped by one or more columns.
     *
     * @param string|array<int, string> $columns The column(s) to group by.
     * @return ModelQueryBuilder<static> The query builder.
     */
    public static function groupBy(string|array $columns): ModelQueryBuilder
    {
        return static::newQuery()->groupBy($columns);
    }

    /**
     * Start a model query with a having clause.
     *
     * @param string|\BlueprintAU\Radiant\Database\Query\Expression|\BlueprintAU\Radiant\Database\Query\Aggregate $column The column (or aggregate) to compare.
     * @param WhereOperator|string $operator The comparison operator.
     * @param mixed $value The value to compare against.
     * @return ModelQueryBuilder<static> The query builder.
     */
    public static function having(
        string|\BlueprintAU\Radiant\Database\Query\Expression|\BlueprintAU\Radiant\Database\Query\Aggregate $column,
        WhereOperator|string $operator,
        mixed $value,
    ): ModelQueryBuilder {
        return static::newQuery()->having($column, $operator, $value);
    }

    /**
     * Start a model query with eager-loaded relations.
     *
     * Validation happens HERE, at the with() call — an unknown relation is
     * a typo and fails fast at the call site, not at hydration time.
     *
     * Dot-notation nests: `'posts.comments'` eager-loads posts, then each
     * post's comments.
     *
     * @param string ...$relations The relation names to eager-load.
     * @return ModelQueryBuilder<static> The query builder.
     * @throws \InvalidArgumentException When a name does not resolve to a
     *         relation method on the model.
     */
    public static function with(string ...$relations): ModelQueryBuilder
    {
        // Variadics are already a list — the @param on with() narrows it.
        /** @var list<string> $relations */
        return static::newQuery()->with($relations);
    }

    // ---- Persistence ----

    /**
     * Save the model — INSERT when new, UPDATE of the dirty columns when not.
     *
     * The branch is driven by in-memory state (`exists`), not a database
     * check: a `new` model always INSERTs, so re-saving a caller-assigned
     * (non-auto-increment) PK from a fresh instance is a duplicate-PK
     * failure — re-save through a loaded instance instead. Concurrent
     * saves are last-writer-wins (no optimistic locking); see docs/orm.md
     * "Saving and primary keys" for the full contract.
     *
     * @return bool True on success (failures throw).
     */
    public function save(): bool
    {
        if (!$this->exists) {
            return $this->performInsert();
        }

        // MTI children update per-partition (single query when the dirty
        // columns land on one table, a transaction across tables otherwise).
        if (MetadataFactory::for(static::class)->isMtiChild()) {
            return $this->performMtiUpdate();
        }

        return $this->performUpdate();
    }

    /**
     * Delete the model (soft-delete when the trait is used).
     *
     * @return bool True when the delete affected the row; false when the
     *         row no longer exists (a stale instance). Failures throw.
     */
    public function delete(): bool
    {
        return $this->performDelete();
    }

    /**
     * The real DELETE by primary key.
     *
     * Reflects the affected-row count: a stale instance (the row was
     * deleted by another connection while this one was alive) matches 0
     * rows — `$this->exists` is cleared and false is returned instead of
     * reporting a delete that did not happen. Mirrors the
     * {@see SoftDeletes} trait's delete()/restore() contract.
     *
     * @return bool True when the row was deleted; false when it was
     *         already gone.
     */
    protected function performDelete(): bool
    {
        $metadata = MetadataFactory::for(static::class);

        // MTI: leaf-first deletes up the chain — each level removes its own
        // row. (The schema-level ON DELETE CASCADE is the backstop; the
        // explicit deletes are the runtime path, portable across dialects
        // that enforce FKs differently.)
        if ($metadata->isMtiChild()) {
            $key = $this->getKeyForRefresh();
            $pks = static::getPrimaryKeys();

            // Composite MTI keys delete by the full key tuple — every level
            // shares ALL the key columns, so each table's DELETE matches on
            // the same column => value pairs. A single key keeps the
            // scalar form (one where, one binding).
            $composite = count($pks) > 1;

            $anyDeleted = false;

            for ($class = static::class; $class !== false; $class = get_parent_class($class)) {
                if (!is_a($class, Model::class, true)) {
                    continue;
                }

                $levelMetadata = MetadataFactory::for($class);
                $levelTable = $levelMetadata->tableName;

                if ($levelTable === null) {
                    continue;
                }

                $query = static::connection()->table($levelTable);

                if ($composite) {
                    foreach ($pks as $pk) {
                        if ($pk->name !== null) {
                            $query->where($pk->name, '=', $key[$pk->name] ?? null);
                        }
                    }
                } else {
                    $query->where($pks[0]->name ?? 'id', '=', $key);
                }

                $deleted = $query->delete();
                $anyDeleted = $anyDeleted || $deleted > 0;
            }

            $this->exists = false;

            // MTI reports success when at least one partition row went
            // away — a cascade may legitimately remove some levels' rows
            // first, so per-level zero counts are expected.
            return $anyDeleted;
        }

        // withTrashed(): forceDelete must reach soft-deleted rows too — the
        // auto-applied whereNull(deleted_at) scope would exclude exactly the
        // rows a hard delete after a soft delete needs to target, matching 0
        // rows and reporting false.
        $deleted = $this->newQuery()->withTrashed()->whereKey($this->getKeyForRefresh())->delete();
        $this->exists = false;

        return $deleted > 0;
    }

    /**
     * INSERT the model.
     *
     * A single auto-increment PK gets its generated id back via
     * `insertGetId()`; a composite / UUID / char PK has no generated id, so
     * the caller must have set all key columns before `save()`.
     *
     * @return bool Always true (failures throw).
     */
    protected function performInsert(): bool
    {
        $metadata = MetadataFactory::for(static::class);

        // MTI: split the insert per table — root first (generating the id),
        // then each descendant, in ONE transaction on a SQL connection.
        if ($metadata->isMtiChild()) {
            return $this->performMtiInsert($metadata);
        }

        $values = $this->getColumnValues();
        $pks = static::getPrimaryKeys();

        if (count($pks) === 1 && $pks[0]->autoIncrement) {
            $this->setPrimaryKey($this->newQuery()->insertGetId($values));
        } else {
            $this->newQuery()->insert($values);
        }

        $this->exists = true;
        $this->materializeDefaults();
        $this->syncOriginal();

        return true;
    }

    /**
     * Materialize declared column defaults onto uninitialized properties
     * after a successful INSERT.
     *
     * Uninitialized typed properties were omitted from the INSERT (that is
     * WHY the DB default fired) — but leaving them uninitialized makes the
     * in-memory model diverge from the row it just wrote: the property
     * still throws "must not be accessed before initialization" even
     * though `exists` is true and the row holds the default. Writing the
     * declared default through the column's own decode keeps the model the
     * row's honest picture without a re-fetch.
     *
     * Scope: only properties that are (a) uninitialized, (b) carry a
     * literal (non-Expression) default, and (c) are not the auto-increment
     * PK (it gets its generated value from `setPrimaryKey()`). An
     * `Expression` default (e.g. `CURRENT_TIMESTAMP`) is skipped — its
     * DB-computed value is unknowable client-side, so guessing would be
     * worse than leaving the property uninitialized. A `null` attribute
     * default means "no declared default" — nothing to materialize; for a
     * nullable property, uninitialized already reads as null through
     * `attribute()`, so there is no gap to fill.
     *
     * @return void
     */
    private function materializeDefaults(): void
    {
        foreach (static::getProperties() as $mapping) {
            $property = $mapping->property;

            if ($property === null || $property->isInitialized($this)) {
                continue;
            }

            $default = $mapping->column->default;

            if ($default === null || $default instanceof \BlueprintAU\Radiant\Database\Query\Expression) {
                continue;
            }

            $property->setValue($this, $mapping->column->decode($default));
        }
    }

    /**
     * INSERT an MTI chain: root partition first (the generated id seeds
     * every descendant's shared PK), then each level, all in one
     * transaction.
     *
     * @param \BlueprintAU\Radiant\Metadata\ClassMetadata $metadata The child's metadata.
     * @return bool Always true (failures throw and roll back).
     * @throws \BlueprintAU\Radiant\Database\Exceptions\UnsupportedFeatureException
     *         When the connection is not SQL (transactions + joins are
     *         required for the split write).
     */
    protected function performMtiInsert(\BlueprintAU\Radiant\Metadata\ClassMetadata $metadata): bool
    {
        // Fail fast with the MTI-specific message: the insert splits across
        // tables in one transaction, which only a SQL connection can do.
        $connection = static::connection();

        if (!$connection instanceof \BlueprintAU\Radiant\Database\Connections\SqlConnection) {
            throw new \BlueprintAU\Radiant\Database\Exceptions\UnsupportedFeatureException(
                'Multi-table inheritance writes require a SQL connection (the insert splits across tables in one transaction).'
            );
        }

        $pk = static::getPrimaryKeys()[0];
        $pkName = $pk->name ?? throw new \LogicException(
            'MTI requires a single named primary key on the root table.'
        );

        // Late static binding does NOT flow into a closure's
        // get_parent_class()/static:: calls — the closure's scope is the
        // defining class (Model). Capture the concrete class here and walk
        // the chain from it.
        $leafClass = static::class;

        $connection->transaction(function () use ($connection, $metadata, $pkName, $leafClass): void {
            // Walk root-first: each level's own columns go to its own table.
            $chain = [];

            for ($class = $leafClass; $class !== false; $class = get_parent_class($class)) {
                if (!is_a($class, Model::class, true)) {
                    continue;
                }

                $levelMetadata = MetadataFactory::for($class);

                if ($levelMetadata->tableName === null) {
                    continue;
                }

                $chain[] = [$levelMetadata, $levelMetadata->tableName];
            }

            // chain is child-first; reverse to insert the root first.
            $chain = array_reverse($chain);

            $generatedId = null;

            foreach ($chain as [$levelMetadata, $levelTable]) {
                $values = [];

                foreach ($levelMetadata->properties as $mapping) {
                    if ($metadata->tableFor($mapping->columnName) !== $levelTable) {
                        continue; // another level's column
                    }

                    // The shared PK: root generates it, descendants copy it.
                    if ($mapping->columnName === $pkName) {
                        $value = $generatedId ?? ($this->original[$pkName] ?? $this->encodedPkValue($mapping));

                        if ($value !== null) {
                            $values[$pkName] = $value;
                        }

                        continue;
                    }

                    if ($mapping->property === null) {
                        if (array_key_exists($mapping->columnName, $this->syntheticValues)) {
                            $values[$mapping->columnName] = $mapping->column->encode(
                                $this->syntheticValues[$mapping->columnName],
                            );
                        }
                        continue;
                    }

                    if ($mapping->property->isInitialized($this) === false) {
                        continue;
                    }

                    $values[$mapping->columnName] = $mapping->column->encode(
                        $mapping->property->getValue($this),
                    );
                }

                // ONLY the root table generates the id — the decision uses
                // the ROOT's key (autoIncrement true there), not the child's
                // derived clone (autoIncrement false by design).
                $levelRoot = $generatedId === null;

                if ($levelRoot) {
                    unset($values[$pkName]);

                    $builder = $connection->table($levelTable);

                    if (self::rootAutoIncrement($leafClass)) {
                        $generatedId = $builder->insertIdColumn($pkName, true)->insertGetId($values);
                    } elseif (isset($values[$pkName])) {
                        $generatedId = $values[$pkName]; // caller-assigned key
                    } else {
                        $builder->insert($values);
                    }
                } else {
                    // Descendant: the shared id MUST ride along — the FK is
                    // the link. The PK mapping's value was already copied
                    // above when present; fill it from the generated id.
                    if (!isset($values[$pkName])) {
                        $values[$pkName] = $generatedId;
                    }

                    $connection->table($levelTable)->insert($values);
                }
            }

            $this->setPrimaryKey($generatedId);
        });

        $this->exists = true;
        $this->materializeDefaults();
        $this->syncOriginal();

        return true;
    }

    /**
     * Whether the MTI chain's ROOT table has an auto-increment key.
     *
     * @param class-string<Model> $leafClass The child class.
     * @return bool True when the root generates the id.
     */
    private static function rootAutoIncrement(string $leafClass): bool
    {
        $class = $leafClass;

        while (true) {
            $metadata = MetadataFactory::for($class);

            if ($metadata->parentModel === null) {
                return $metadata->primaryKeys[0]->autoIncrement;
            }

            $class = $metadata->parentModel;
        }
    }

    /**
     * The PK value from a typed property (encoded) for a NEW model — the
     * caller-assigned key path (non-auto-increment roots).
     *
     * @param \BlueprintAU\Radiant\Metadata\PropertyMapping $mapping The PK mapping.
     * @return string|int|null The encoded key value, or null when unset.
     */
    private function encodedPkValue(\BlueprintAU\Radiant\Metadata\PropertyMapping $mapping): string|int|null
    {
        if ($mapping->property === null || $mapping->property->isInitialized($this) === false) {
            return null;
        }

        $value = $mapping->column->encode($mapping->property->getValue($this));

        return is_string($value) || is_int($value) ? $value : null;
    }

    /**
     * Write the generated id back onto the single auto-increment PK property.
     *
     * The id arrives as the codec's output (int or bigint-string). Writing
     * it onto the typed property coerces it: `"42"` → int when the property
     * is int; a bigint string that exceeds `PHP_INT_MAX` stays string. This
     * is the type boundary — the codec normalizes dialect bytes, the model
     * property owns the PHP type.
     *
     * @param string|int|null $id The generated id.
     * @return void
     */
    protected function setPrimaryKey(string|int|null $id): void
    {
        if ($id === null) {
            return;
        }

        $pk = static::getPrimaryKeys()[0];

        foreach (static::getProperties() as $mapping) {
            if ($mapping->columnName === $pk->name && $mapping->property !== null) {
                $mapping->property->setValue($this, $id);
                return;
            }
        }
    }

    /**
     * UPDATE the dirty columns by primary key.
     *
     * @return bool Always true (failures throw).
     */
    protected function performUpdate(): bool
    {
        $dirty = $this->getDirty();

        if ($dirty !== []) {
            $this->newQuery()->whereKey($this->getKeyForRefresh())->update($dirty);
        }

        $this->syncOriginal();

        return true;
    }

    /**
     * UPDATE the dirty columns, split per owning table when the model is
     * an MTI child. One UPDATE per dirty partition; a transaction wraps
     * the writes only when they span more than one table.
     *
     * @return bool Always true (failures throw).
     */
    protected function performMtiUpdate(): bool
    {
        $metadata = MetadataFactory::for(static::class);
        $dirty = $this->getDirty();

        if ($dirty === []) {
            $this->syncOriginal();

            return true;
        }

        $key = $this->getKeyForRefresh();
        $connection = static::connection();
        $pks = static::getPrimaryKeys();

        // Partition the dirty columns per owning table.
        $perTable = [];

        foreach ($dirty as $column => $value) {
            $perTable[$metadata->tableFor($column)][$column] = $value;
        }

        $multiTable = count($perTable) > 1;

        // A composite MTI key retargets every partition with the full key
        // tuple (all levels share ALL key columns); a single key keeps the
        // scalar form.
        $composite = count($pks) > 1;

        $apply = function () use ($perTable, $key, $connection, $pks, $composite): void {
            foreach ($perTable as $table => $values) {
                $query = $connection->table($table);

                if ($composite) {
                    foreach ($pks as $pk) {
                        if ($pk->name !== null) {
                            $query->where($pk->name, '=', $key[$pk->name] ?? null);
                        }
                    }
                } else {
                    $query->where($pks[0]->name ?? 'id', '=', $key);
                }

                $query->update($values);
            }
        };

        if ($multiTable) {
            // A multi-table update must be atomic — SQL-only, narrowed
            // fail-fast (UnsupportedFeatureException on a non-SQL backend),
            // no @var docblock needed.
            SqlConnection::from($connection)->transaction($apply);
        } else {
            $apply();
        }

        $this->syncOriginal();

        return true;
    }

    // ---- Dirty tracking ----

    /**
     * The columns changed since the last sync, keyed by column name with
     * their encoded (bindable) values.
     *
     * The comparison is STRICT against the encoded snapshot (`!==` on the
     * encoded space, with an array_key_exists guard for newly-written
     * columns). PHP's loose `!=` treats `0 == '0'`, `'' == null`, `true ==
     * 1` and `'1e3' == '1000'` as equal — all REAL encoded-space
     * representations a write can legitimately change (an int `0` written
     * onto a column loaded as the string `'0'`, a `false` onto a `1`). A
     * loose compare silently dropped those writes: `$dirty` stayed empty,
     * `save()` wrote nothing, and the application's update never reached
     * the database.
     *
     * @return array<string, mixed> The dirty column values.
     */
    protected function getDirty(): array
    {
        $dirty = [];

        foreach ($this->getColumnValues() as $column => $value) {
            if (!array_key_exists($column, $this->original) || $this->original[$column] !== $value) {
                $dirty[$column] = $value;
            }
        }

        return $dirty;
    }

    /**
     * Snapshot the current column values as the hydration-time original.
     *
     * @return void
     */
    protected function syncOriginal(): void
    {
        $this->original = $this->getColumnValues();
    }

    /**
     * The model's primary-key value for re-targeting the row.
     *
     * A single PK returns its loaded (original) value; a composite PK
     * returns an associative array of column => value.
     *
     * @return KeyValue The key value, or a column => value map.
     */
    final public function getKeyForRefresh(): mixed
    {
        $pks = static::getPrimaryKeys();

        if (count($pks) === 1 && $pks[0]->name !== null) {
            return $this->original[$pks[0]->name] ?? null;
        }

        $key = [];

        foreach ($pks as $pk) {
            if ($pk->name !== null) {
                $key[$pk->name] = $this->original[$pk->name] ?? null;
            }
        }

        return $key;
    }

    // ---- Hydration (reconstitution, not creation) ----

    /**
     * Reconstitute a model from a raw row.
     *
     * Hydration does NOT run the constructor — the instance is created
     * without it and each column property is decoded through its column's
     * cast. A `\DateTimeInterface`-typed property re-bases the decoded
     * Carbon to the property's concrete class when the two differ.
     *
     * @param \stdClass $row The raw row (stdClass), keyed by column name.
     * @return static The hydrated model.
     */
    final public static function fromRow(\stdClass $row): static
    {
        $instance = (new \ReflectionClass(static::class))->newInstanceWithoutConstructor();

        foreach (static::getProperties() as $mapping) {
            $columnName = $mapping->columnName;

            if (!property_exists($row, $columnName)) {
                continue;
            }

            if ($mapping->property === null) {
                // Synthetic column — no property slot to hydrate. Seed the
                // snapshot directly: the raw bytes ARE the encoded space
                // `$original` lives in, so no decode/encode round-trip is
                // needed (or wanted). For synthetic mappings
                // `propertyName === columnName` by construction (the
                // factory creates them from the same name), so this key
                // matches the rest of the column-keyed snapshot.
                $instance->original[$columnName] = $row->{$columnName};
                continue;
            }

            $instance->hydrateProperty($mapping, $mapping->column->decode($row->{$columnName}));
        }

        $instance->exists = true;
        $instance->syncOriginal();

        // Pivot columns from a BelongsToMany eager select ride the row as
        // `radiant_pivot_{column}` aliases — lift them onto the instance's
        // pivot store (a model hydrated WITHOUT a pivot join has none).
        foreach (get_object_vars($row) as $field => $value) {
            if (str_starts_with($field, 'radiant_pivot_')) {
                $instance->pivotValues[substr($field, strlen('radiant_pivot_'))] = $value;
            }
        }

        return $instance;
    }

    /**
     * A pivot value carried onto this model by a BelongsToMany eager load.
     *
     * @param string $column The pivot column name (as passed to
     *        `withPivot()`).
     * @return mixed The value, or null when the model was not loaded with
     *         that pivot column.
     */
    final public function pivotValue(string $column): mixed
    {
        return $this->pivotValues[$column] ?? null;
    }

    /**
     * Write one decoded value onto the instance.
     *
     * A `\DateTimeInterface`-typed property re-bases a decoded Carbon to
     * the property's concrete class (`CarbonImmutable`, `DateTime`,
     * custom subclasses) via `createFromInterface()` — the cast never
     * needs to know the concrete class.
     *
     * @param PropertyMapping $mapping The column mapping.
     * @param mixed $value The decoded value.
     * @return void
     */
    private function hydrateProperty(PropertyMapping $mapping, mixed $value): void
    {
        $property = $mapping->property;

        if ($property === null) {
            return;
        }

        $propertyType = $mapping->column->propertyType;

        if (
            $value instanceof \DateTimeInterface
            && $propertyType !== null
            && $value::class !== $propertyType
            && is_a($propertyType, \DateTimeInterface::class, true)
            && !$value instanceof $propertyType
        ) {
            // DateTimeImmutable::createFromInterface etc. exist on every
            // concrete datetime class, but not on the interface itself —
            // reflect the method off the concrete class-string so PHPStan
            // sees a verified call, not a static guess.
            $method = new \ReflectionMethod($propertyType, 'createFromInterface');
            $value = $method->invoke(null, $value);
        }

        $property->setValue($this, $value);
    }

    /**
     * Read a column's current value by DB column name — works for BOTH
     * typed-property columns and synthetic columns (which hold no PHP
     * property, so there is nothing to read except through here).
     *
     * A synthetic column resolves in priority order: a post-load runtime
     * override ({@see Model::setAttribute()}) first, then the loaded value
     * decoded out of {@see Model::$original} on demand — the store only
     * exists because the column has no typed property to read.
     *
     * @param string $columnName The DB column name.
     * @return mixed The decoded (typed-property-shaped) value, or null when
     *         unset.
     */
    final public function attribute(string $columnName): mixed
    {
        $mapping = MetadataFactory::for(static::class)->mappingFor($columnName);

        if ($mapping->property === null) {
            // Runtime override wins; else decode the loaded snapshot.
            if (array_key_exists($columnName, $this->syntheticValues)) {
                return $this->syntheticValues[$columnName];
            }

            $encoded = $this->original[$columnName] ?? null;

            return $encoded === null ? null : $mapping->column->decode($encoded);
        }

        if ($mapping->property->isInitialized($this) === false) {
            return null;
        }

        return $mapping->property->getValue($this);
    }

    /**
     * Write a synthetic column's runtime value.
     *
     * Synthetic columns have no typed property to hold a value — this store
     * is their ONLY writable slot. A column backed by a typed property is
     * written through the property itself (`$model->columnName = ...`):
     * writing it here would silently diverge from what the property reads,
     * so it fails fast instead.
     *
     * @param string $columnName The DB column name.
     * @param mixed $value The decoded (typed) value.
     * @return void
     * @throws \InvalidArgumentException When the column is backed by a
     *         typed property (write the property directly), or is unknown.
     */
    final public function setAttribute(string $columnName, mixed $value): void
    {
        $mapping = MetadataFactory::for(static::class)->mappingFor($columnName);

        if ($mapping->property !== null) {
            throw new \InvalidArgumentException(
                'Column [' . $columnName . '] on model [' . static::class . '] is backed by a typed '
                . 'property; write the property directly instead of setAttribute().'
            );
        }

        $this->syntheticValues[$columnName] = $value;
    }

    // ---- Metadata (delegating to the MetadataFactory cache) ----

    /**
     * The class's merged column mappings, keyed by property name.
     *
     * @return PropertyMapping[] The merged mappings.
     */
    protected static function getProperties(): array
    {
        return MetadataFactory::for(static::class)->properties;
    }

    /**
     * The class's primary-key column declarations.
     *
     * @return list<Column> The primary-key columns.
     */
    protected static function getPrimaryKeys(): array
    {
        return MetadataFactory::for(static::class)->primaryKeys;
    }

    /**
     * The current column values, keyed by column name with their encoded
     * (bindable) values.
     *
     * Unset (uninitialized) typed properties are skipped — a partial model
     * writes only what it holds. Synthetic columns contribute their
     * runtime value when set.
     *
     * @return array<string, mixed> column => encoded value
     */
    protected function getColumnValues(): array
    {
        $values = [];

        foreach (static::getProperties() as $mapping) {
            if ($mapping->property === null) {
                // Synthetic column — the runtime store holds the value when
                // a trait has written one post-load; otherwise the loaded
                // snapshot stands (seeded at hydration from the raw row).
                // Without the fallback, `syncOriginal()` would silently
                // drop the loaded value on every save.
                if (array_key_exists($mapping->columnName, $this->syntheticValues)) {
                    $values[$mapping->columnName] = $mapping->column->encode(
                        $this->syntheticValues[$mapping->columnName],
                    );
                } elseif (array_key_exists($mapping->columnName, $this->original)) {
                    $values[$mapping->columnName] = $this->original[$mapping->columnName];
                }
                continue;
            }

            if ($mapping->property->isInitialized($this) === false) {
                continue;
            }

            $values[$mapping->columnName] = $mapping->column->encode(
                $mapping->property->getValue($this),
            );
        }

        return $values;
    }

    /**
     * Encode a single value for a column (used by traits writing raw values).
     *
     * @param string $columnName The DB column name.
     * @param mixed $value The typed property value.
     * @return mixed The bindable value.
     */
    protected function castForWrite(string $columnName, mixed $value): mixed
    {
        return MetadataFactory::for(static::class)->mappingFor($columnName)->column->encode($value);
    }

    // ---- Relations ----

    /**
     * A one-to-many relation: this model's key is referenced by the
     * related table's FK.
     *
     * Declared as a method so it composes: `$user->posts()->where(...)`
     * keeps the constraint and adds to it. Override the FK with
     * `$foreignKey` when the column is not the snake_case default.
     *
     * Composite keys: when this model has a composite PK, `$localKey`
     * defaults to the full PK column list — and `$foreignKey` must then be
     * declared explicitly as a matching column list (a composite FK cannot
     * be derived by convention).
     *
     * @template TRelated of Model
     *
     * @param class-string<TRelated> $related The related model class.
     * @param string|list<string>|null $foreignKey The FK column (or column
     *        list) on the related table.
     * @param string|list<string>|null $localKey The key column (or column
     *        list) on this table.
     * @return Relations\HasMany<TRelated> The relation (a lazily-executed query).
     * @throws \InvalidArgumentException When the FK column does not exist
     *         on the related model.
     */
    protected function hasMany(string $related, string|array|null $foreignKey = null, string|array|null $localKey = null): Relations\HasMany
    {
        $localKey ??= self::defaultLocalKey();
        $foreignKey ??= self::defaultForeignKeyFor($localKey);

        self::assertColumnExists($related, $foreignKey, 'foreign key');
        self::assertColumnExists(static::class, $localKey, 'local key');

        return (new Relations\HasMany($this, $related, $foreignKey, $localKey))
            ->withName(self::relationName());
    }

    /**
     * Composite keys follow the same rules as {@see Model::hasMany()}.
     *
     * @template TRelated of Model
     *
     * @param class-string<TRelated> $related The related model class.
     * @param string|list<string>|null $foreignKey The FK column (or column
     *        list) on the related table.
     * @param string|list<string>|null $localKey The key column (or column
     *        list) on this table.
     * @return Relations\HasOne<TRelated> The relation.
     * @throws \InvalidArgumentException When the FK column does not exist
     *         on the related model.
     */
    protected function hasOne(string $related, string|array|null $foreignKey = null, string|array|null $localKey = null): Relations\HasOne
    {
        $localKey ??= self::defaultLocalKey();
        $foreignKey ??= self::defaultForeignKeyFor($localKey);

        self::assertColumnExists($related, $foreignKey, 'foreign key');
        self::assertColumnExists(static::class, $localKey, 'local key');

        return (new Relations\HasOne($this, $related, $foreignKey, $localKey))
            ->withName(self::relationName());
    }

    /**
     * The inverse relation: this model's table holds the FK.
     *
     * Composite keys: when the related model has a composite PK, `$ownerKey`
     * defaults to its full PK column list — and `$foreignKey` must then be
     * declared explicitly as a matching column list.
     *
     * @template TRelated of Model
     *
     * @param class-string<TRelated> $related The related (owning) model class.
     * @param string|list<string>|null $foreignKey The FK column (or column
     *        list) on THIS table.
     * @param string|list<string>|null $ownerKey The key column (or column
     *        list) on the related table.
     * @return Relations\BelongsTo<TRelated> The relation.
     * @throws \InvalidArgumentException When the FK column does not exist
     *         on this model.
     */
    protected function belongsTo(string $related, string|array|null $foreignKey = null, string|array|null $ownerKey = null): Relations\BelongsTo
    {
        $ownerKey ??= self::defaultLocalKeyOf($related);
        $foreignKey ??= self::defaultForeignKeyFromKey($ownerKey, $related);

        self::assertColumnExists(static::class, $foreignKey, 'foreign key');
        self::assertColumnExists($related, $ownerKey, 'owner key');

        return (new Relations\BelongsTo($this, $related, $foreignKey, $ownerKey))
            ->withName(self::relationName());
    }

    /**
     * A two-hop relation through an intermediate model.
     *
     * `hasOneThrough(Owner::class, Car::class)` — the intermediate model
     * is the SECOND argument; the FKs derive from the snake_case convention
     * and are overridable for non-standard keys. Composite keys are declared
     * as matching column lists on every side that is composite.
     *
     * @template TRelated of Model
     *
     * @param class-string<TRelated> $related The final related model class.
     * @param class-string<Model> $through The intermediate model class.
     * @param string|list<string>|null $firstKey FK column (or list) on the
     *        intermediate table → this model.
     * @param string|list<string>|null $secondKey FK column (or list) on the
     *        related table → intermediate.
     * @param string|list<string>|null $localKey The key column (or list) on
     *        this table.
     * @return Relations\HasOneThrough<TRelated> The relation.
     * @throws \InvalidArgumentException When any derived column does not
     *         exist on its model.
     */
    protected function hasOneThrough(
        string $related,
        string $through,
        string|array|null $firstKey = null,
        string|array|null $secondKey = null,
        string|array|null $localKey = null,
    ): Relations\HasOneThrough {
        $localKey ??= self::defaultLocalKey();
        $firstKey ??= self::defaultForeignKeyFor($localKey);
        $secondKey ??= self::defaultForeignKeyFrom($through);

        self::assertColumnExists($through, $firstKey, 'first key');
        self::assertColumnExists($related, $secondKey, 'second key');
        self::assertColumnExists(static::class, $localKey, 'local key');

        return (new Relations\HasOneThrough($this, $related, $through, $firstKey, $secondKey, $localKey))
            ->withName(self::relationName());
    }

    /**
     * A one-to-many two-hop relation through an intermediate model.
     *
     * @template TRelated of Model
     *
     * @param class-string<TRelated> $related The final related model class.
     * @param class-string<Model> $through The intermediate model class.
     * @param string|list<string>|null $firstKey FK column (or list) on the
     *        intermediate table → this model.
     * @param string|list<string>|null $secondKey FK column (or list) on the
     *        related table → intermediate.
     * @param string|list<string>|null $localKey The key column (or list) on
     *        this table.
     * @return Relations\HasManyThrough<TRelated> The relation.
     * @throws \InvalidArgumentException When any derived column does not
     *         exist on its model.
     */
    protected function hasManyThrough(
        string $related,
        string $through,
        string|array|null $firstKey = null,
        string|array|null $secondKey = null,
        string|array|null $localKey = null,
    ): Relations\HasManyThrough {
        $localKey ??= self::defaultLocalKey();
        $firstKey ??= self::defaultForeignKeyFor($localKey);
        $secondKey ??= self::defaultForeignKeyFrom($through);

        self::assertColumnExists($through, $firstKey, 'first key');
        self::assertColumnExists($related, $secondKey, 'second key');
        self::assertColumnExists(static::class, $localKey, 'local key');

        return (new Relations\HasManyThrough($this, $related, $through, $firstKey, $secondKey, $localKey))
            ->withName(self::relationName());
    }

    /**
     * A one-to-many POLYMORPHIC relation: the related table's FK + type
     * columns point back at models of ANY class.
     *
     * `Post::comments()` → `Comment::newQuery()->where(commentable_id,
     * $post->id)->where(commentable_type, Post::class)`. The type column
     * defaults to `{morphName}_type` and the FK to `{morphName}_id` — pass
     * the morph name (e.g. `'commentable'`) or the explicit columns.
     *
     * @template TRelated of Model
     *
     * @param class-string<TRelated> $related The related model class.
     * @param string|null $morphName The morph alias prefix — derives both
     *        column names when the explicit ones are null.
     * @param string|null $foreignKey The FK column on the related table.
     * @param string|null $localKey The key column on this table.
     * @param string|null $typeColumn The type-discriminator column on the
     *        related table.
     * @return Relations\MorphMany<TRelated> The relation.
     * @throws \InvalidArgumentException When a derived column does not
     *         exist on its model.
     */
    protected function morphMany(
        string $related,
        ?string $morphName = null,
        ?string $foreignKey = null,
        ?string $localKey = null,
        ?string $typeColumn = null,
    ): Relations\MorphMany {
        $localKey ??= self::defaultLocalKey();

        if (is_array($localKey)) {
            throw new \LogicException(
                'morphMany() does not support composite keys; the morph (type, key) '
                . 'pair is a scalar-key convention.'
            );
        }

        $foreignKey ??= self::defaultMorphForeignKey($morphName, $related, $foreignKey, $typeColumn);
        $typeColumn ??= self::defaultMorphTypeColumn($morphName, $related, $foreignKey);

        self::assertColumnExists($related, $foreignKey, 'foreign key');
        self::assertColumnExists($related, $typeColumn, 'morph type');
        self::assertColumnExists(static::class, $localKey, 'local key');

        return (new Relations\MorphMany($this, $related, $foreignKey, $localKey, $typeColumn))
            ->withName(self::relationName());
    }

    /**
     * A one-to-one POLYMORPHIC relation — {@see Model::morphMany()}'s
     * first row, stably ordered by the related PK.
     *
     * @template TRelated of Model
     *
     * @param class-string<TRelated> $related The related model class.
     * @param string|null $morphName The morph alias prefix — derives both
     *        column names when the explicit ones are null.
     * @param string|null $foreignKey The FK column on the related table.
     * @param string|null $localKey The key column on this table.
     * @param string|null $typeColumn The type-discriminator column on the
     *        related table.
     * @return Relations\MorphOne<TRelated> The relation.
     * @throws \InvalidArgumentException When a derived column does not
     *         exist on its model.
     */
    protected function morphOne(
        string $related,
        ?string $morphName = null,
        ?string $foreignKey = null,
        ?string $localKey = null,
        ?string $typeColumn = null,
    ): Relations\MorphOne {
        $localKey ??= self::defaultLocalKey();

        if (is_array($localKey)) {
            throw new \LogicException(
                'morphOne() does not support composite keys; the morph (type, key) '
                . 'pair is a scalar-key convention.'
            );
        }

        $foreignKey ??= self::defaultMorphForeignKey($morphName, $related, $foreignKey, $typeColumn);
        $typeColumn ??= self::defaultMorphTypeColumn($morphName, $related, $foreignKey);

        self::assertColumnExists($related, $foreignKey, 'foreign key');
        self::assertColumnExists($related, $typeColumn, 'morph type');
        self::assertColumnExists(static::class, $localKey, 'local key');

        return (new Relations\MorphOne($this, $related, $foreignKey, $localKey, $typeColumn))
            ->withName(self::relationName());
    }

    /**
     * The inverse POLYMORPHIC relation: this model's (type, key) pair
     * points at a row of ANY model table, resolved per row from the type
     * column.
     *
     * `Comment::commentable()` reads `commentable_type` + `commentable_id`
     * and queries whichever model class the type column names. The owner
     * key defaults to the TARGET's primary key (resolved per query — the
     * target class is dynamic).
     *
     * Typing: the `$types` allowlist drives the static type. With
     * `morphTo('commentable', types: [Post::class, Video::class])` the
     * relation's reads narrow to `(Post|Video)|null` — the same classes
     * the runtime allowlist enforces. Without it the result is the honest
     * `Model|null` (any class can resolve) and callers narrow with a
     * local `instanceof`.
     *
     * @template TRelated of Model The classes the allowlist admits —
     *         inferred from `$types`; never resolved when `$types` is null.
     *
     * @param string|null $morphName The morph alias prefix — derives both
     *        column names when the explicit ones are null.
     * @param string|null $typeColumn The type-discriminator column on THIS
     *        table.
     * @param string|null $foreignKey The FK column on THIS table.
     * @param string|null $ownerKey The key column on the target tables.
     * @param list<class-string<TRelated>>|null $types The optional
     *        morph-alias allowlist — null resolves any model class; a
     *        value outside the list fails fast at resolution.
     * @return ($types is null ? Relations\MorphTo<Model> : Relations\MorphTo<TRelated>) The relation:
     *         the Model bound when no allowlist is declared (any class can
     *         resolve — the honest contract), the allowlist-narrowed
     *         template when one is.
     * @throws \InvalidArgumentException When a derived column does not
     *         exist on this model.
     */
    protected function morphTo(
        ?string $morphName = null,
        ?string $typeColumn = null,
        ?string $foreignKey = null,
        ?string $ownerKey = null,
        ?array $types = null,
    ): Relations\MorphTo {
        $typeColumn ??= self::defaultMorphTypeColumn($morphName, static::class, $typeColumn);
        $foreignKey ??= self::defaultMorphForeignKey($morphName, static::class, $foreignKey, $typeColumn);
        $ownerKey ??= 'id';

        self::assertColumnExists(static::class, $typeColumn, 'morph type');
        self::assertColumnExists(static::class, $foreignKey, 'foreign key');

        return (new Relations\MorphTo($this, $typeColumn, $foreignKey, $ownerKey, $types))
            ->withName(self::relationName());
    }

    /**
     * The morph FK default: `{morphName}_id`, or the caller's explicit
     * type column's `_type` → `_id` mirror.
     *
     * @param string|null $morphName The morph alias prefix.
     * @param class-string<Model> $model The model the columns live on (for
     *        the error message).
     * @param string|null $foreignKey The caller's explicit FK (null here —
     *        the param exists for the mirror rule).
     * @param string|null $typeColumn The caller's explicit type column.
     * @return string The FK column name.
     * @throws \InvalidArgumentException When neither a morph name nor an
     *         explicit type column is available to derive from.
     */
    private static function defaultMorphForeignKey(
        ?string $morphName,
        string $model,
        ?string $foreignKey,
        ?string $typeColumn,
    ): string {
        if ($morphName !== null && $morphName !== '') {
            return $morphName . '_id';
        }

        if ($typeColumn !== null && str_ends_with($typeColumn, '_type')) {
            return substr($typeColumn, 0, -strlen('_type')) . '_id';
        }

        throw new \InvalidArgumentException(
            "A morph relation on [{$model}] needs a morph name (or an explicit "
            . '`_type`-suffixed type column) to derive its column names.'
        );
    }

    /**
     * The morph type-column default: `{morphName}_type`, or the caller's
     * explicit FK column's `_id` → `_type` mirror.
     *
     * @param string|null $morphName The morph alias prefix.
     * @param class-string<Model> $model The model the columns live on (for
     *        the error message).
     * @param string|null $foreignKey The caller's explicit FK column.
     * @return string The type column name.
     * @throws \InvalidArgumentException When neither a morph name nor an
     *         explicit FK column is available to derive from.
     */
    private static function defaultMorphTypeColumn(
        ?string $morphName,
        string $model,
        ?string $foreignKey,
    ): string {
        if ($morphName !== null && $morphName !== '') {
            return $morphName . '_type';
        }

        if ($foreignKey !== null && str_ends_with($foreignKey, '_id')) {
            return substr($foreignKey, 0, -strlen('_id')) . '_type';
        }

        throw new \InvalidArgumentException(
            "A morph relation on [{$model}] needs a morph name (or an explicit "
            . '`_id`-suffixed foreign key) to derive its column names.'
        );
    }

    /**
     * A many-to-many relation through a pivot table.
     *
     * `Post::tags()` links through `posts_tags` (the deterministic
     * `{parentTable}_{relatedTable}` default — pass `$table` for any other
     * name). The pivot key columns default to `{parentTable}_id` /
     * `{relatedTable}_id`; both models must declare a single named primary
     * key (pivot keys are scalar-only).
     *
     * @template TRelated of Model
     *
     * @param class-string<TRelated> $related The related model class.
     * @param string|null $table The pivot table name.
     * @param string|null $foreignPivotKey The pivot column → this model.
     * @param string|null $relatedPivotKey The pivot column → related.
     * @param string|null $parentKey This model's key column.
     * @param string|null $relatedKey The related model's key column.
     * @return Relations\BelongsToMany<TRelated> The relation.
     * @throws \InvalidArgumentException When a model's primary key is
     *         composite or unnamed.
     */
    protected function belongsToMany(
        string $related,
        ?string $table = null,
        ?string $foreignPivotKey = null,
        ?string $relatedPivotKey = null,
        ?string $parentKey = null,
        ?string $relatedKey = null,
    ): Relations\BelongsToMany {
        return (new Relations\BelongsToMany(
            $this,
            $related,
            $table,
            $foreignPivotKey,
            $relatedPivotKey,
            $parentKey,
            $relatedKey,
        ))->withName(self::relationName());
    }

    /**
     * A many-to-many POLYMORPHIC relation: the pivot's parent side is a
     * (type, key) pair, so models of ANY class share the pool.
     *
     * `Post::tags()` and `Video::tags()` both link through `taggables`
     * (the `{morphName}{relatedTable}` default pivot name); every query
     * filters the pivot's `{morphName}_type` to THIS class's FQCN.
     *
     * @template TRelated of Model
     *
     * @param class-string<TRelated> $related The related model class.
     * @param string $morphName The morph alias prefix — the pivot's
     *        `{morphName}_id`/`{morphName}_type` columns.
     * @param string|null $table The pivot table name.
     * @return Relations\MorphToMany<TRelated> The relation.
     * @throws \InvalidArgumentException When a model's primary key is
     *         composite or unnamed.
     */
    protected function morphToMany(
        string $related,
        string $morphName,
        ?string $table = null,
    ): Relations\MorphToMany {
        return (new Relations\MorphToMany($this, $related, $morphName, $table))
            ->withName(self::relationName());
    }

    /**
     * The INVERSE polymorphic many-to-many relation: this model is the
     * RELATED side of the pivot (`Tag::posts()` lists every post tagged
     * with it).
     *
     * @template TRelated of Model
     *
     * @param class-string<TRelated> $related The related model class (the
     *        morph PARENT side — e.g. Post when called on Tag).
     * @param string $morphName The morph alias prefix.
     * @param string|null $table The pivot table name.
     * @return Relations\MorphToMany<TRelated> The relation.
     * @throws \InvalidArgumentException When a model's primary key is
     *         composite or unnamed.
     */
    protected function morphedByMany(
        string $related,
        string $morphName,
        ?string $table = null,
    ): Relations\MorphToMany {
        return (new Relations\MorphToMany($this, $related, $morphName, $table, inverse: true))
            ->withName(self::relationName());
    }

    /**
     * Cache a relation's loaded result on the instance.
     *
     * Written by the eager loader; a relation method's lazy access never
     * consults the cache.
     *
     * @param string $name The relation name (the relation method's name).
     * @param Model|Collection<Model>|null $value The loaded result — a
     *        single related model (HasOne/BelongsTo), a collection
     *        (HasMany/through), or null (an empty single-valued relation).
     * @return static The model.
     */
    final public function setRelation(string $name, Model|Collection|null $value): static
    {
        $this->relations[$name] = $value;

        return $this;
    }

    /**
     * A loaded relation's cached result — the loader's read path.
     *
     * Internal: the eager loader reads nested children through it, and
     * {@see Relation::getResults()} consults it for the cache-backed read.
     * Public so the Relations namespace can reach it (the relation owns
     * the cache-hit decision); there is deliberately NO public typed
     * accessor — callers read relations through the relation METHOD
     * (`$user->posts()->getResults()`), which is typed by the method's
     * declared return and shares this cache when the relation is loaded
     * and unfiltered.
     *
     * @param string $name The relation name.
     * @return Model|Collection<Model>|null The cached result, or null when
     *         not loaded (or loaded empty).
     */
    final public function cachedRelation(string $name): Model|Collection|null
    {
        $value = $this->relations[$name] ?? null;

        if (!$value instanceof Model && !$value instanceof Collection) {
            return null;
        }

        return $value;
    }

    /**
     * The relation-method name the CURRENT factory call came from.
     *
     * The factories (`hasMany` etc.) are called from relation methods
     * (`posts()`); the relation needs that caller's name as its cache key
     * so {@see Relation::getResults()} can find the eagerly-loaded result.
     * A bounded backtrace reads it — no relation method has to pass its
     * own name, and a typo'd explicit name cannot silently break the
     * cache. The walk skips every frame INSIDE the ORM's own namespace
     * (the factories, any internal helper a relation method delegates
     * through, the loader) and takes the first frame outside it — a
     * factory called from a helper method still resolves to the relation
     * method above it. The ORM's own test namespace is EXEMPT from the
     * skip: this repo's fixtures are the stand-in for user models, so
     * `RelUser::posts()` must stamp just like a model in a host app
     * would. A factory reached from anywhere else (the loader's prototype
     * invocation, user code that is not a relation method) stamps nothing
     * and the cache path stays off.
     *
     * @return string|null The calling relation method's name, or null when
     *         the factory was not called from a relation method.
     */
    private static function relationName(): string|null
    {
        $ormNamespace = __NAMESPACE__ . '\\';
        // This repo's fixtures are user-model stand-ins — not plumbing.
        $exemptNamespace = __NAMESPACE__ . '\\Tests\\';

        // Frame 0 is relationName() itself, frame 1 the factory — the
        // caller of interest is frame 2 and up.
        $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 6);

        foreach (array_slice($frames, 2) as $frame) {
            $class = $frame['class'] ?? null;

            if (is_string($class)) {
                $isFramework = str_starts_with($class, $ormNamespace)
                    && !str_starts_with($class, $exemptNamespace);

                if (!$isFramework) {
                    return $frame['function'];
                }

                continue;
            }

            // A class-less frame is a plain function — never a relation
            // method (those are class methods), so the factory was not
            // called from one.
            return null;
        }

        return null;
    }

    /**
     * Whether a relation has been (eager-)loaded on this instance.
     *
     * @param string $name The relation name.
     * @return bool True when the relation result is cached.
     */
    final public function relationLoaded(string $name): bool
    {
        return array_key_exists($name, $this->relations);
    }

    /**
     * The FK default for a relation keyed off `$localKey` (this model's).
     *
     * @param string|list<string> $localKey The local key the FK mirrors.
     * @return string The FK default (a scalar column).
     * @throws \LogicException When the local key is composite.
     */
    private static function defaultForeignKeyFor(string|array $localKey): string
    {
        self::assertDerivableKey($localKey);

        return self::defaultForeignKey();
    }

    /**
     * The FK default for a belongsTo keyed off `$ownerKey` (pointing at
     * `$related`).
     *
     * @param string|list<string> $ownerKey The owner key the FK mirrors.
     * @param class-string<Model> $related The model the FK references.
     * @return string The FK default (a scalar column).
     * @throws \LogicException When the owner key is composite.
     */
    private static function defaultForeignKeyFromKey(string|array $ownerKey, string $related): string
    {
        self::assertDerivableKey($ownerKey);

        return self::defaultForeignKeyFrom($related);
    }

    /**
     * Fail fast when a composite key cannot derive its FK counterpart.
     *
     * There is no naming convention for a column tuple — the caller must
     * declare both sides explicitly.
     *
     * @param string|list<string> $key The key whose counterpart is wanted.
     * @return void
     * @throws \LogicException When the key is composite.
     */
    private static function assertDerivableKey(string|array $key): void
    {
        if (is_array($key)) {
            throw new \LogicException(
                'A relation over a composite key cannot derive its counterpart columns by '
                . 'convention; declare both sides explicitly as matching column lists, e.g. '
                . 'hasMany(Post::class, [\'region_id\', \'country\']).'
            );
        }
    }

    /**
     * The snake_case foreign-key default for THIS model: its short class
     * name + `_id`.
     *
     * @return string The FK column name.
     */
    private static function defaultForeignKey(): string
    {
        $short = (new \ReflectionClass(static::class))->getShortName();

        return strtolower((string) preg_replace('/(?<=[a-z0-9])([A-Z])/', '_$1', $short)) . '_id';
    }

    /**
     * The snake_case foreign-key default pointing AT another model: that
     * model's short class name + `_id` (the belongsTo direction).
     *
     * @param class-string<Model> $related The model the FK references.
     * @return string The FK column name.
     */
    private static function defaultForeignKeyFrom(string $related): string
    {
        $short = (new \ReflectionClass($related))->getShortName();

        return strtolower((string) preg_replace('/(?<=[a-z0-9])([A-Z])/', '_$1', $short)) . '_id';
    }

    /**
     * THIS model's primary-key column(s) (the local-key default).
     *
     * A single PK returns the column name; a composite PK returns the full
     * column list — the relation then carries BOTH sides as lists, and the
     * FK side must be declared explicitly (no naming convention exists for
     * a tuple).
     *
     * @return string|list<string> The PK column name, or the column list.
     * @throws \LogicException When the model has no primary key at all.
     */
    private static function defaultLocalKey(): string|array
    {
        return self::primaryKeyNamesOf(static::class);
    }

    /**
     * Another model's primary-key column(s) (the owner-key default).
     *
     * @param class-string<Model> $related The model whose PK to resolve.
     * @return string|list<string> The PK column name, or the column list.
     * @throws \LogicException When the model has no primary key at all.
     */
    private static function defaultLocalKeyOf(string $related): string|array
    {
        return self::primaryKeyNamesOf($related);
    }

    /**
     * A model's primary-key column name(s).
     *
     * @param class-string<Model> $class The model to resolve.
     * @return string|list<string> The single column name, or the column list.
     * @throws \LogicException When the model declares no primary key, or a
     *         PK column resolves without a name (never happens post-build —
     *         the factory names every column — but the guard keeps the
     *         contract provable).
     */
    private static function primaryKeyNamesOf(string $class): string|array
    {
        $keys = MetadataFactory::for($class)->primaryKeys;

        if ($keys === []) {
            throw new \LogicException(
                "Relation endpoints require a primary key; model [{$class}] declares none."
            );
        }

        $names = [];

        foreach ($keys as $key) {
            if ($key->name === null) {
                throw new \LogicException(
                    "Relation endpoints require a named primary key; model [{$class}] has one without a name."
                );
            }

            $names[] = $key->name;
        }

        return count($names) === 1 ? $names[0] : $names;
    }

    /**
     * Fail fast when a column does not exist on a model.
     *
     * @param class-string<Model> $model The model the column must exist on.
     * @param string|list<string> $column The column name (or column list).
     * @param string $role What the column is (for the message).
     * @return void
     * @throws \InvalidArgumentException When the column is unknown.
     */
    private static function assertColumnExists(string $model, string|array $column, string $role): void
    {
        foreach (is_array($column) ? $column : [$column] as $name) {
            self::assertSingleColumnExists($model, $name, $role);
        }
    }

    /**
     * Fail fast when ONE column does not exist on a model.
     *
     * @param class-string<Model> $model The model the column must exist on.
     * @param string $column The column name.
     * @param string $role What the column is (for the message).
     * @return void
     * @throws \InvalidArgumentException When the column is unknown.
     */
    private static function assertSingleColumnExists(string $model, string $column, string $role): void
    {
        $metadata = MetadataFactory::for($model);

        foreach ($metadata->properties as $mapping) {
            if ($mapping->columnName === $column) {
                return;
            }
        }

        throw new \InvalidArgumentException(
            "Unknown {$role} column [{$column}] on model [{$model}]. A relation's columns "
            . 'must match the model\'s declared column names.'
        );
    }
}
