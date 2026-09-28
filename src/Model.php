<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant;

use BlueprintAU\Radiant\Concerns\FiltersStaticQuery;
use BlueprintAU\Radiant\Database\Connections\ConnectionInterface;
use BlueprintAU\Radiant\Database\Connections\SqlConnection;
use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Attributes\Hook;
use BlueprintAU\Radiant\Database\Query\Enums\SortDirection;
use BlueprintAU\Radiant\Database\Query\Enums\WhereBoolean;
use BlueprintAU\Radiant\Database\Query\Enums\WhereOperator;
use BlueprintAU\Radiant\Metadata\MetadataFactory;
use BlueprintAU\Radiant\Metadata\PropertyMapping;

/**
 * The Active Record base model.
 *
 * Typed properties + `#[Column]` attributes declare the schema; hydration
 * reconstitutes instances without running the constructor.
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
     * Columns and pivot columns must not start with it — a user column
     * named `radiant_foo` would collide with the internal aliases.
     */
    public const RESERVED_PREFIX = 'radiant_';

    /**
     * The loaded values at hydration time, keyed by column name.
     *
     * Values live in the encoded (bindable) space so dirty tracking
     * compares like with like.
     *
     * @var array<string, mixed>
     */
    protected array $original = [];

    /**
     * Runtime overrides for synthetic columns — columns the metadata
     * declares but no PHP property backs.
     *
     * @var array<string, mixed>
     */
    protected array $syntheticValues = [];

    /**
     * Whether the model exists in the database.
     *
     * @var bool
     */
    protected bool $exists = false;

    /**
     * Loaded relation results, keyed by relation name.
     *
     * @var array<string, Model|Collection<Model>|null>
     */
    protected array $relations = [];

    /**
     * Lifecycle callbacks registered on this instance, keyed by event
     * name (`saving`, `saved`, `deleting`, `deleted`, `restoring`,
     * `restored`). Attempt listeners may return false to veto the action.
     *
     * @var array<string, list<callable>>
     */
    private array $lifecycleCallbacks = [];

    /**
     * Pivot values carried onto this model by a BelongsToMany eager load,
     * keyed by pivot column name.
     *
     * @var array<string, mixed>
     */
    protected array $pivotValues = [];

    /**
     * Fail fast when a column name starts with the ORM's reserved prefix.
     *
     * @param  string  $column
     * @param  string  $role
     * @return void
     * @throws \InvalidArgumentException
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
     * The default connection name for this model.
     *
     * @var string|null
     */
    protected static ?string $connection = null;

    /**
     * The connection this model runs on.
     *
     * @return ConnectionInterface
     */
    final public static function connection(): ConnectionInterface
    {
        return static::resolveConnection();
    }

    /**
     * The single seam for connection resolution.
     *
     * @return ConnectionInterface
     */
    protected static function resolveConnection(): ConnectionInterface
    {
        return Database::connection(static::$connection);
    }

    /**
     * The table name for this model.
     *
     * @return string
     */
    final public static function table(): string
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
     * @return ModelQueryBuilder<static>
     */
    final public static function newQuery(): ModelQueryBuilder
    {
        return new ModelQueryBuilder(static::class, static::connection());
    }

    /**
     * Find a model by its primary key.
     *
     * @param  KeyValue  $id  The primary-key value, or a column => value map for a composite key.
     * @return static|null
     */
    final public static function find(int|string|null|array $id): ?static
    {
        return static::newQuery()->find($id);
    }

    /**
     * Find a model by its primary key or throw if it does not exist.
     *
     * @param  KeyValue  $id  The primary-key value, or a column => value map for a composite key.
     * @return static
     * @throws \BlueprintAU\Radiant\Database\Exceptions\ModelNotFoundException
     */
    final public static function findOrFail(int|string|null|array $id): static
    {
        return static::newQuery()->findOrFail($id);
    }

    /**
     * Get the first model of the table or throw if the table is empty.
     *
     * @return static
     * @throws \BlueprintAU\Radiant\Database\Exceptions\ModelNotFoundException
     */
    final public static function firstOrFail(): static
    {
        return static::newQuery()->firstOrFail();
    }

    /**
     * Get the single model of the table or throw if the count differs.
     *
     * @return static
     * @throws \BlueprintAU\Radiant\Database\Exceptions\ModelNotFoundException
     * @throws \BlueprintAU\Radiant\Database\Exceptions\MultipleRecordsFoundException
     */
    final public static function sole(): static
    {
        return static::newQuery()->sole();
    }

    /**
     * Get every model in the table.
     *
     * @return Collection<static>
     */
    final public static function all(): Collection
    {
        return static::newQuery()->get();
    }

    // ---- Static filter-modifier forwarders ----

    /**
     * Start a model query with a where clause.
     *
     * @param  string  $column
     * @param  WhereOperator|string  $operator
     * @param  mixed  $value
     * @param  WhereBoolean  $boolean
     * @return ModelQueryBuilder<static>
     */
    final public static function where(
        string $column,
        WhereOperator|string $operator,
        mixed $value,
        WhereBoolean $boolean = WhereBoolean::And,
    ): ModelQueryBuilder {
        return static::newQuery()->where($column, $operator, $value, $boolean);
    }

    /**
     * Start a model query with a nested where group.
     *
     * @param  callable(\BlueprintAU\Radiant\Database\Query\WhereBuilder): \BlueprintAU\Radiant\Database\Query\WhereBuilder  $callback
     * @param  WhereBoolean  $boolean
     * @return ModelQueryBuilder<static>
     */
    final public static function whereNested(
        callable $callback,
        WhereBoolean $boolean = WhereBoolean::And,
    ): ModelQueryBuilder {
        return static::newQuery()->whereNested($callback, $boolean);
    }

    /**
     * Start a model query with an order-by clause.
     *
     * @param  string  $column
     * @param  SortDirection|string  $direction
     * @return ModelQueryBuilder<static>
     */
    final public static function orderBy(
        string $column,
        SortDirection|string $direction = SortDirection::Asc,
    ): ModelQueryBuilder {
        return static::newQuery()->orderBy($column, $direction);
    }

    /**
     * Start a model query with a row limit.
     *
     * @param  int  $limit
     * @return ModelQueryBuilder<static>
     */
    final public static function limit(int $limit): ModelQueryBuilder
    {
        return static::newQuery()->limit($limit);
    }

    /**
     * Start a model query with a row offset.
     *
     * @param  int  $offset
     * @return ModelQueryBuilder<static>
     */
    final public static function offset(int $offset): ModelQueryBuilder
    {
        return static::newQuery()->offset($offset);
    }

    /**
     * Start a model query with an explicit column selection.
     *
     * @param  string|\BlueprintAU\Radiant\Database\Query\Expression|\BlueprintAU\Radiant\Database\Query\Aggregate  ...$columns
     * @return ModelQueryBuilder<static>
     */
    final public static function select(string|\BlueprintAU\Radiant\Database\Query\Expression|\BlueprintAU\Radiant\Database\Query\Aggregate ...$columns): ModelQueryBuilder
    {
        return static::newQuery()->select(...$columns);
    }

    /**
     * Start a model query grouped by one or more columns.
     *
     * @param  string|array<int, string>  $columns
     * @return ModelQueryBuilder<static>
     */
    final public static function groupBy(string|array $columns): ModelQueryBuilder
    {
        return static::newQuery()->groupBy($columns);
    }

    /**
     * Start a model query with a having clause.
     *
     * @param  string|\BlueprintAU\Radiant\Database\Query\Expression|\BlueprintAU\Radiant\Database\Query\Aggregate  $column
     * @param  WhereOperator|string  $operator
     * @param  mixed  $value
     * @return ModelQueryBuilder<static>
     */
    final public static function having(
        string|\BlueprintAU\Radiant\Database\Query\Expression|\BlueprintAU\Radiant\Database\Query\Aggregate $column,
        WhereOperator|string $operator,
        mixed $value,
    ): ModelQueryBuilder {
        return static::newQuery()->having($column, $operator, $value);
    }

    /**
     * Start a model query with eager-loaded relations.
     *
     * Dot-notation nests: `'posts.comments'` eager-loads posts, then each
     * post's comments.
     *
     * @param  string  ...$relations
     * @return ModelQueryBuilder<static>
     * @throws \InvalidArgumentException
     */
    final public static function with(string ...$relations): ModelQueryBuilder
    {
        // Variadics are already a list — the @param on with() narrows it.
        /** @var list<string> $relations */
        return static::newQuery()->with($relations);
    }

    // ---- Persistence ----

    /**
     * A fresh timestamp for the stamp and delete columns.
     *
     * @return \Carbon\Carbon
     */
    protected function freshTimestamp(): \Carbon\Carbon
    {
        return \Carbon\Carbon::now();
    }

    // ---- Lifecycle callbacks ----

    /**
     * Register a callback to run after a successful INSERT or UPDATE.
     *
     * @param  callable(static $model): void  $callback
     * @return void
     */
    final public function saved(callable $callback): void
    {
        $this->lifecycleCallbacks['saved'][] = $callback;
    }

    /**
     * Register a callback to run after a successful delete — soft or hard.
     *
     * @param  callable(static $model): void  $callback
     * @return void
     */
    final public function deleted(callable $callback): void
    {
        $this->lifecycleCallbacks['deleted'][] = $callback;
    }

    /**
     * Register a callback to run before the INSERT/UPDATE payload is
     * built — listeners may mutate the model (the mutation lands in the
     * write) or return false to cancel the save.
     *
     * @param  callable(static $model): (bool|void)  $callback
     * @return void
     */
    final public function saving(callable $callback): void
    {
        $this->lifecycleCallbacks['saving'][] = $callback;
    }

    /**
     * Register a callback to run before a delete is attempted — soft or
     * hard. Returning false vetoes the delete.
     *
     * @param  callable(static $model): (bool|void)  $callback
     * @return void
     */
    final public function deleting(callable $callback): void
    {
        $this->lifecycleCallbacks['deleting'][] = $callback;
    }

    /**
     * Register a callback to run before restore() clears the soft-delete
     * stamp. Returning false vetoes the restore.
     *
     * @param  callable(static $model): (bool|void)  $callback
     * @return void
     */
    final public function restoring(callable $callback): void
    {
        $this->lifecycleCallbacks['restoring'][] = $callback;
    }

    /**
     * Register a callback to run after restore() clears the soft-delete
     * stamp.
     *
     * @param  callable(static $model): void  $callback
     * @return void
     */
    final public function restored(callable $callback): void
    {
        $this->lifecycleCallbacks['restored'][] = $callback;
    }

    /**
     * Fire one lifecycle event's callbacks in registration order.
     *
     * Attempt events (`saving`, `deleting`, `restoring`) stop at the first
     * listener returning `false` — the action is vetoed. Success events
     * (`saved`, `deleted`, `restored`) run every listener.
     *
     * @param  string  $event
     * @return bool False when an attempt listener vetoed the action.
     */
    final protected function fireLifecycle(string $event): bool
    {
        foreach ($this->lifecycleCallbacks[$event] ?? [] as $callback) {
            $result = $callback($this);

            if ($result === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Save the model — INSERT when new, UPDATE of the dirty columns when not.
     *
     * A `saving` listener returning false vetoes the save — nothing is
     * written and `false` is reported.
     *
     * @return bool
     * @throws \LogicException
     */
    final public function save(): bool
    {
        if (!$this->fireLifecycle('saving')) {
            return false;
        }

        if (!$this->exists) {
            return $this->performInsert();
        }

        $softDeleteColumn = MetadataFactory::for(static::class)->softDeleteColumn;

        if ($softDeleteColumn !== null && ($this->original[$softDeleteColumn] ?? null) !== null) {
            throw new \LogicException(
                'The model [' . static::class . '] is soft-deleted — its UPDATE would carry the '
                . 'auto-applied `deleted_at IS NULL` scope, match 0 rows, and report success for '
                . 'a write that never landed. Call restore() first (or forceDelete() to remove '
                . 'the row permanently).'
            );
        }

        // MTI children update per-partition (single query when the dirty
        // columns land on one table, a transaction across tables otherwise).
        if (MetadataFactory::for(static::class)->isMtiChild()) {
            $updated = $this->performMtiUpdate();

            if ($updated) {
                $this->fireLifecycle('saved');
            }

            return $updated;
        }

        return $this->performUpdate();
    }

    /**
     * Delete the model — a trait's `#[WriteHook(Hook::Delete)]` method may
     * claim the delete (e.g. soft delete); with no claimant the row is
     * hard-deleted.
     *
     * A `deleting` listener returning false vetoes the delete — the model
     * is untouched and `false` is reported.
     *
     * @return bool
     */
    final public function delete(): bool
    {
        if (!$this->fireLifecycle('deleting')) {
            return false;
        }

        $claimed = $this->dispatchWriteHooks(Hook::Delete);

        if ($claimed !== null) {
            // The claiming trait owns the instance bookkeeping for its
            // path — a soft delete leaves the row in the table (exists
            // stays true; trashed() reflects state), while a stale
            // instance clears it. Model only fires the event on success.
            if ($claimed) {
                $this->fireLifecycle('deleted');
            }

            return $claimed;
        }

        $hardDeleted = $this->performDelete();

        if ($hardDeleted) {
            $this->fireLifecycle('deleted');
        }

        return $hardDeleted;
    }

    /**
     * Dispatch one hook path's trait methods in collection order.
     *
     * A `null` return is an observer — dispatch continues. A `bool`
     * return claims the write and ends dispatch: `true` = performed and
     * succeeded, `false` = owned and failed/refused.
     *
     * @param  Hook  $hook
     * @return bool|null Null when no trait claimed the write.
     */
    final protected function dispatchWriteHooks(Hook $hook): ?bool
    {
        foreach (MetadataFactory::for(static::class)->writeHooks as $entry) {
            if ($entry['hook'] !== $hook) {
                continue;
            }

            $result = $this->{$entry['method']}();

            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    /**
     * The real DELETE by primary key.
     *
     * @return bool
     */
    final protected function performDelete(): bool
    {
        // A null key would compile to `WHERE pk IS NULL` — matching nothing,
        // or the wrong rows on dialects that permit NULL keys.
        $this->assertKeyResolvedForWrite();

        // Destroy observers run on EVERY hard delete — delete() with no
        // claimant AND forceDelete() — so audit traits always see the row
        // going away. The hard DELETE itself is unclaimable.
        $this->dispatchWriteHooks(Hook::Destroy);

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
                            $query = $query->where($pk->name, WhereOperator::Eq, $key[$pk->name] ?? null);
                        }
                    }
                } else {
                    $query = $query->where($pks[0]->name ?? 'id', WhereOperator::Eq, $key);
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
     * @return bool
     */
    final protected function performInsert(): bool
    {
        $metadata = MetadataFactory::for(static::class);

        // MTI: split the insert per table — root first (generating the id),
        // then each descendant, in ONE transaction on a SQL connection.
        if ($metadata->isMtiChild()) {
            $inserted = $this->performMtiInsert($metadata);

            if ($inserted) {
                $this->fireLifecycle('saved');
            }

            return $inserted;
        }

        // Insert-path trait hooks — observers stamp values BEFORE
        // getColumnValues() builds the payload, so the insert carries them
        // and syncOriginal() snapshots them. A claimant performs the
        // insert itself.
        $claimed = $this->dispatchWriteHooks(Hook::Insert);

        if ($claimed !== null) {
            return $claimed;
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
        $this->fireLifecycle('saved');

        return true;
    }

    /**
     * Materialize declared column defaults onto uninitialized properties
     * after a successful INSERT.
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

            $property->setValue($this, $mapping->column->decode($default, $mapping->propertyType));
        }
    }

    /**
     * INSERT an MTI chain: root partition first, then each level, all in
     * one transaction.
     *
     * @param  \BlueprintAU\Radiant\Metadata\ClassMetadata  $metadata
     * @return bool
     * @throws \BlueprintAU\Radiant\Database\Exceptions\UnsupportedFeatureException
     */
    final protected function performMtiInsert(\BlueprintAU\Radiant\Metadata\ClassMetadata $metadata): bool
    {
        // Insert-path trait hooks — MTI children get the same stamping as
        // single-table models (the hook fires before the payload build).
        $claimed = $this->dispatchWriteHooks(Hook::Insert);

        if ($claimed !== null) {
            return $claimed;
        }

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

        // A caller-assigned (non-auto-increment) root key MUST be present:
        // without it the root INSERT omits the PK and the descendant
        // partitions have nothing to link against. (An auto-increment root
        // generates its own — an unset key is expected there.)
        if (!self::rootAutoIncrement($leafClass)) {
            $this->assertAssignedMtiKeyPresent($pkName);
        }

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
                                $mapping->propertyType,
                            );
                        }
                        continue;
                    }

                    if ($mapping->property->isInitialized($this) === false) {
                        continue;
                    }

                    $values[$mapping->columnName] = $mapping->column->encode(
                        $mapping->property->getValue($this),
                        $mapping->propertyType,
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
     * Whether the MTI chain's root table has an auto-increment key.
     *
     * @param  class-string<Model>  $leafClass
     * @return bool
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
     * The encoded PK value from a typed property for a new model.
     *
     * @param  \BlueprintAU\Radiant\Metadata\PropertyMapping  $mapping
     * @return string|int|null
     */
    private function encodedPkValue(\BlueprintAU\Radiant\Metadata\PropertyMapping $mapping): string|int|null
    {
        if ($mapping->property === null || $mapping->property->isInitialized($this) === false) {
            return null;
        }

        $value = $mapping->column->encode($mapping->property->getValue($this), $mapping->propertyType);

        return is_string($value) || is_int($value) ? $value : null;
    }

    /**
     * Write the generated id back onto the single auto-increment PK property.
     *
     * @param  string|int|null  $id
     * @return void
     */
    final protected function setPrimaryKey(string|int|null $id): void
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
     * @return bool
     */
    final protected function performUpdate(): bool
    {
        // Update-path trait hooks — stamping BEFORE getDirty() means the
        // bumped values show up in the dirty set (and a no-op update with
        // no other changes still writes the stamp). A claimant performs
        // the update itself.
        $claimed = $this->dispatchWriteHooks(Hook::Update);

        if ($claimed !== null) {
            return $claimed;
        }

        $dirty = $this->getDirty();

        if ($dirty !== []) {
            // A null key would compile to `WHERE pk IS NULL` — the UPDATE
            // would match nothing yet report success.
            $this->assertKeyResolvedForWrite();

            $this->newQuery()->whereKey($this->getKeyForRefresh())->update($dirty);
        }

        $this->syncOriginal();
        $this->fireLifecycle('saved');

        return true;
    }

    /**
     * UPDATE the dirty columns, split per owning table when the model is
     * an MTI child.
     *
     * @return bool
     */
    final protected function performMtiUpdate(): bool
    {
        // Update-path trait hooks — MTI children get the same stamping as
        // single-table models.
        $claimed = $this->dispatchWriteHooks(Hook::Update);

        if ($claimed !== null) {
            return $claimed;
        }

        $metadata = MetadataFactory::for(static::class);
        $dirty = $this->getDirty();

        if ($dirty === []) {
            $this->syncOriginal();

            return true;
        }

        // A null key would compile to `WHERE pk IS NULL` per partition —
        // the UPDATE would match nothing yet report success.
        $this->assertKeyResolvedForWrite();

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
                            $query = $query->where($pk->name, WhereOperator::Eq, $key[$pk->name] ?? null);
                        }
                    }
                } else {
                    $query = $query->where($pks[0]->name ?? 'id', WhereOperator::Eq, $key);
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
     * The comparison is strict against the encoded snapshot — PHP's loose
     * `!=` would silently drop legitimate writes like an int `0` onto a
     * column loaded as the string `'0'`.
     *
     * @return array<string, mixed>
     */
    final protected function getDirty(): array
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
    final protected function syncOriginal(): void
    {
        $this->original = $this->getColumnValues();
    }

    /**
     * The model's primary-key value for re-targeting the row.
     *
     * Lenient by design: read paths (Collection::find()/fresh()) treat an
     * unresolved key as "no match". Write paths guard separately via
     * {@see assertKeyResolvedForWrite()}.
     *
     * @return KeyValue
     */
    final public function getKeyForRefresh(): int|string|null|array
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

    /**
     * Fail fast when a write cannot target its row — a primary-key
     * component with no value compiles to `WHERE pk IS NULL`, which
     * matches nothing (or, on dialects that permit NULL keys, the wrong
     * rows) while the caller is told the write succeeded.
     *
     * Read paths stay lenient — {@see getKeyForRefresh()} feeding
     * Collection::find()/fresh() treats an unresolved key as "no match" —
     * only the write paths (update, delete) pay for this guard.
     *
     * @return void
     * @throws \LogicException
     */
    private function assertKeyResolvedForWrite(): void
    {
        $key = $this->getKeyForRefresh();

        $missing = [];

        if (is_array($key)) {
            foreach ($key as $column => $value) {
                if ($value === null) {
                    $missing[] = $column;
                }
            }
        } elseif ($key === null) {
            $missing[] = static::getPrimaryKeys()[0]->name ?? '(unnamed)';
        }

        if ($missing === []) {
            return;
        }

        throw new \LogicException(
            'The write on model [' . static::class . '] cannot target its row — primary-key '
            . 'column(s) [' . implode(', ', $missing) . '] hold no value (the model was never '
            . 'saved, or its key was never hydrated). The statement would compile to '
            . '`WHERE pk IS NULL`, matching nothing — or, on dialects that permit NULL keys, '
            . 'the wrong rows. Save the model to generate its key, or assign a caller-managed '
            . 'key first.'
        );
    }

    /**
     * Fail fast when an MTI insert's caller-assigned (non-auto-increment)
     * root key is missing — the root INSERT would omit the PK entirely and
     * the descendant partitions would have nothing to link against.
     *
     * @param  string  $pkName
     * @return void
     * @throws \LogicException
     */
    private function assertAssignedMtiKeyPresent(string $pkName): void
    {
        if (isset($this->original[$pkName])) {
            return;
        }

        foreach (static::getProperties() as $mapping) {
            if ($mapping->columnName === $pkName && $this->encodedPkValue($mapping) !== null) {
                return;
            }
        }

        throw new \LogicException(
            'The MTI insert on model [' . static::class . '] needs its caller-assigned '
            . 'primary key [' . $pkName . '] set — the root table does not auto-generate '
            . 'one, so the root INSERT would omit the key and the descendant partitions '
            . 'would have nothing to link against.'
        );
    }

    // ---- Hydration (reconstitution, not creation) ----

    /**
     * Reconstitute a model from a raw row.
     *
     * Hydration does not run the constructor; each column property is
     * decoded through its column's cast.
     *
     * @param  \stdClass  $row
     * @return static
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

            $instance->hydrateProperty($mapping, $mapping->column->decode($row->{$columnName}, $mapping->propertyType));
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
     * @param  string  $column
     * @return mixed
     */
    final public function pivotValue(string $column): mixed
    {
        return $this->pivotValues[$column] ?? null;
    }

    /**
     * Write one decoded value onto the instance.
     *
     * @param  PropertyMapping  $mapping
     * @param  mixed  $value
     * @return void
     */
    private function hydrateProperty(PropertyMapping $mapping, mixed $value): void
    {
        $property = $mapping->property;

        if ($property === null) {
            return;
        }

        $propertyType = $mapping->propertyType;

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
     * Read a column's current value by DB column name.
     *
     * Works for both typed-property columns and synthetic columns.
     *
     * @param  string  $columnName
     * @return mixed
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

            return $encoded === null ? null : $mapping->column->decode($encoded, $mapping->propertyType);
        }

        if ($mapping->property->isInitialized($this) === false) {
            return null;
        }

        return $mapping->property->getValue($this);
    }

    /**
     * Write a synthetic column's runtime value.
     *
     * @param  string  $columnName
     * @param  mixed  $value
     * @return void
     * @throws \InvalidArgumentException
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
     * @return PropertyMapping[]
     */
    protected static function getProperties(): array
    {
        return MetadataFactory::for(static::class)->properties;
    }

    /**
     * The class's primary-key column declarations.
     *
     * @return list<Column>
     */
    protected static function getPrimaryKeys(): array
    {
        return MetadataFactory::for(static::class)->primaryKeys;
    }

    /**
     * The current column values, keyed by column name with their encoded
     * (bindable) values.
     *
     * Unset (uninitialized) typed properties are skipped.
     *
     * @return array<string, mixed>
     */
    final protected function getColumnValues(): array
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
                        $mapping->propertyType,
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
                $mapping->propertyType,
            );
        }

        return $values;
    }

    /**
     * Encode a single value for a column.
     *
     * @param  string  $columnName
     * @param  mixed  $value
     * @return mixed
     */
    final protected function castForWrite(string $columnName, mixed $value): mixed
    {
        $mapping = MetadataFactory::for(static::class)->mappingFor($columnName);

        return $mapping->column->encode($value, $mapping->propertyType);
    }

    // ---- Relations ----

    /**
     * A one-to-many relation: this model's key is referenced by the
     * related table's FK.
     *
     * @template TRelated of Model
     *
     * @param  class-string<TRelated>  $related
     * @param  string|list<string>|null  $foreignKey
     * @param  string|list<string>|null  $localKey
     * @return Relations\HasMany<TRelated>
     * @throws \InvalidArgumentException
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
     * A one-to-one relation: this model's key is referenced by the
     * related table's FK.
     *
     * @template TRelated of Model
     *
     * @param  class-string<TRelated>  $related
     * @param  string|list<string>|null  $foreignKey
     * @param  string|list<string>|null  $localKey
     * @return Relations\HasOne<TRelated>
     * @throws \InvalidArgumentException
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
     * @template TRelated of Model
     *
     * @param  class-string<TRelated>  $related
     * @param  string|list<string>|null  $foreignKey
     * @param  string|list<string>|null  $ownerKey
     * @return Relations\BelongsTo<TRelated>
     * @throws \InvalidArgumentException
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
     * A one-to-one two-hop relation through an intermediate model.
     *
     * @template TRelated of Model
     *
     * @param  class-string<TRelated>  $related
     * @param  class-string<Model>  $through
     * @param  string|list<string>|null  $firstKey
     * @param  string|list<string>|null  $secondKey
     * @param  string|list<string>|null  $localKey
     * @return Relations\HasOneThrough<TRelated>
     * @throws \InvalidArgumentException
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
     * @param  class-string<TRelated>  $related
     * @param  class-string<Model>  $through
     * @param  string|list<string>|null  $firstKey
     * @param  string|list<string>|null  $secondKey
     * @param  string|list<string>|null  $localKey
     * @return Relations\HasManyThrough<TRelated>
     * @throws \InvalidArgumentException
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
     * A one-to-many polymorphic relation: the related table's FK + type
     * columns point back at models of any class.
     *
     * @template TRelated of Model
     *
     * @param  class-string<TRelated>  $related
     * @param  string|null  $morphName
     * @param  string|null  $foreignKey
     * @param  string|null  $localKey
     * @param  string|null  $typeColumn
     * @return Relations\MorphMany<TRelated>
     * @throws \InvalidArgumentException
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
        self::assertMorphKeyMatches($related, $foreignKey, static::class);

        return (new Relations\MorphMany($this, $related, $foreignKey, $localKey, $typeColumn))
            ->withName(self::relationName());
    }

    /**
     * A one-to-one polymorphic relation — the first row of a morphMany,
     * stably ordered by the related PK.
     *
     * @template TRelated of Model
     *
     * @param  class-string<TRelated>  $related
     * @param  string|null  $morphName
     * @param  string|null  $foreignKey
     * @param  string|null  $localKey
     * @param  string|null  $typeColumn
     * @return Relations\MorphOne<TRelated>
     * @throws \InvalidArgumentException
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
        self::assertMorphKeyMatches($related, $foreignKey, static::class);

        return (new Relations\MorphOne($this, $related, $foreignKey, $localKey, $typeColumn))
            ->withName(self::relationName());
    }

    /**
     * The inverse polymorphic relation: this model's (type, key) pair
     * points at a row of any model table, resolved per row from the type
     * column.
     *
     * @template TRelated of Model The classes the allowlist admits — inferred from `$types`.
     *
     * @param  string|null  $morphName
     * @param  string|null  $typeColumn
     * @param  string|null  $foreignKey
     * @param  string|null  $ownerKey
     * @param  list<class-string<TRelated>>|null  $types  The optional morph-alias allowlist; null resolves any model class.
     * @return ($types is null ? Relations\MorphTo<Model> : Relations\MorphTo<TRelated>)
     * @throws \InvalidArgumentException
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
     * @param  string|null  $morphName
     * @param  class-string<Model>  $model
     * @param  string|null  $foreignKey
     * @param  string|null  $typeColumn
     * @return string
     * @throws \InvalidArgumentException
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
     * @param  string|null  $morphName
     * @param  class-string<Model>  $model
     * @param  string|null  $foreignKey
     * @return string
     * @throws \InvalidArgumentException
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
     * @template TRelated of Model
     *
     * @param  class-string<TRelated>  $related
     * @param  string|null  $table
     * @param  string|null  $foreignPivotKey
     * @param  string|null  $relatedPivotKey
     * @param  string|null  $parentKey
     * @param  string|null  $relatedKey
     * @return Relations\BelongsToMany<TRelated>
     * @throws \InvalidArgumentException
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
     * A many-to-many polymorphic relation: the pivot's parent side is a
     * (type, key) pair, so models of any class share the pool.
     *
     * @template TRelated of Model
     *
     * @param  class-string<TRelated>  $related
     * @param  string  $morphName
     * @param  string|null  $table
     * @return Relations\MorphToMany<TRelated>
     * @throws \InvalidArgumentException
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
     * The inverse polymorphic many-to-many relation: this model is the
     * related side of the pivot.
     *
     * @template TRelated of Model
     *
     * @param  class-string<TRelated>  $related
     * @param  string  $morphName
     * @param  string|null  $table
     * @return Relations\MorphToMany<TRelated>
     * @throws \InvalidArgumentException
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
     * @param  string  $name
     * @param  Model|Collection<Model>|null  $value
     * @return static
     */
    final public function setRelation(string $name, Model|Collection|null $value): static
    {
        $this->relations[$name] = $value;

        return $this;
    }

    /**
     * A loaded relation's cached result — the loader's read path.
     *
     * @param  string  $name
     * @return Model|Collection<Model>|null
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
     * The relation-method name the current factory call came from.
     *
     * A bounded backtrace walk skips every frame inside the ORM's own
     * namespace and takes the first frame outside it; a factory reached
     * from anywhere else stamps nothing and the cache path stays off.
     *
     * @return string|null
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
     * @param  string  $name
     * @return bool
     */
    final public function relationLoaded(string $name): bool
    {
        return array_key_exists($name, $this->relations);
    }

    /**
     * The FK default for a relation keyed off `$localKey` (this model's).
     *
     * @param  string|list<string>  $localKey
     * @return string
     * @throws \LogicException
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
     * @param  string|list<string>  $ownerKey
     * @param  class-string<Model>  $related
     * @return string
     * @throws \LogicException
     */
    private static function defaultForeignKeyFromKey(string|array $ownerKey, string $related): string
    {
        self::assertDerivableKey($ownerKey);

        return self::defaultForeignKeyFrom($related);
    }

    /**
     * Fail fast when a composite key cannot derive its FK counterpart.
     *
     * @param  string|list<string>  $key
     * @return void
     * @throws \LogicException
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
     * The snake_case foreign-key default for this model: its short class
     * name + `_id`.
     *
     * @return string
     */
    private static function defaultForeignKey(): string
    {
        $short = (new \ReflectionClass(static::class))->getShortName();

        return strtolower((string) preg_replace('/(?<=[a-z0-9])([A-Z])/', '_$1', $short)) . '_id';
    }

    /**
     * The snake_case foreign-key default pointing at another model: that
     * model's short class name + `_id`.
     *
     * @param  class-string<Model>  $related
     * @return string
     */
    private static function defaultForeignKeyFrom(string $related): string
    {
        $short = (new \ReflectionClass($related))->getShortName();

        return strtolower((string) preg_replace('/(?<=[a-z0-9])([A-Z])/', '_$1', $short)) . '_id';
    }

    /**
     * This model's primary-key column(s) (the local-key default).
     *
     * @return string|list<string>
     * @throws \LogicException
     */
    private static function defaultLocalKey(): string|array
    {
        return self::primaryKeyNamesOf(static::class);
    }

    /**
     * Another model's primary-key column(s) (the owner-key default).
     *
     * @param  class-string<Model>  $related
     * @return string|list<string>
     * @throws \LogicException
     */
    private static function defaultLocalKeyOf(string $related): string|array
    {
        return self::primaryKeyNamesOf($related);
    }

    /**
     * A model's primary-key column name(s).
     *
     * @param  class-string<Model>  $class
     * @return string|list<string>
     * @throws \LogicException
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
     * Fail fast when a morph key column's type cannot hold the primary
     * key it stores.
     *
     * @param  class-string<Model>  $holder  The model whose table carries the `{name}_id` column.
     * @param  string  $keyColumn
     * @param  class-string<Model>  $target  The model whose primary key the column must hold.
     * @return void
     * @throws \InvalidArgumentException
     */
    private static function assertMorphKeyMatches(string $holder, string $keyColumn, string $target): void
    {
        $declared = MetadataFactory::for($holder)->mappingFor($keyColumn)->column->type;
        $primaryKey = self::singlePrimaryKeyOf($target);

        if ($primaryKey->type !== $declared) {
            throw new \InvalidArgumentException(
                "The morph key column [{$keyColumn}] on [{$holder}] is [{$declared->value}], but "
                . "[{$target}]'s primary key is [{$primaryKey->type->value}]. A morph pair can "
                . 'only point at models whose primary-key type matches the key column — '
                . 'declare the pair with a matching keyType (or uuidMorphs()).'
            );
        }
    }

    /**
     * A model's single named primary-key column.
     *
     * @param  class-string<Model>  $class
     * @return Column
     * @throws \LogicException
     */
    private static function singlePrimaryKeyOf(string $class): Column
    {
        $keys = MetadataFactory::for($class)->primaryKeys;

        if (count($keys) !== 1 || $keys[0]->name === null) {
            throw new \LogicException(
                "A morph target requires a single named primary key; model [{$class}] "
                . 'declares none, a composite key, or an unnamed key.'
            );
        }

        return $keys[0];
    }

    /**
     * Fail fast when a column does not exist on a model.
     *
     * @param  class-string<Model>  $model
     * @param  string|list<string>  $column
     * @param  string  $role
     * @return void
     * @throws \InvalidArgumentException
     */
    private static function assertColumnExists(string $model, string|array $column, string $role): void
    {
        foreach (is_array($column) ? $column : [$column] as $name) {
            self::assertSingleColumnExists($model, $name, $role);
        }
    }

    /**
     * Fail fast when one column does not exist on a model.
     *
     * @param  class-string<Model>  $model
     * @param  string  $column
     * @param  string  $role
     * @return void
     * @throws \InvalidArgumentException
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
