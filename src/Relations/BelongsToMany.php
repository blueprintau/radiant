<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Relations;

use BlueprintAU\Radiant\Collection;
use BlueprintAU\Radiant\Database\Connections\SqlConnection;
use BlueprintAU\Radiant\Database\Query\Enums\WhereOperator;
use BlueprintAU\Radiant\Database\Exceptions\UnsupportedFeatureException;
use BlueprintAU\Radiant\Metadata\MetadataFactory;
use BlueprintAU\Radiant\Model;

/**
 * Many-to-many: the parent and the related model link THROUGH a pivot
 * table (`Post` ↔ `Tag` via `posts_tags`).
 *
 * The relation query INNER JOINs the pivot: a parent with no pivot rows
 * legitimately has no related models, so INNER is the honest semantics
 * (the same call {@see HasManyThrough} makes). Joins are SQL-only — a
 * non-SQL connection throws
 * {@see \BlueprintAU\Radiant\Database\Exceptions\UnsupportedFeatureException}
 * at execution.
 *
 * The pivot table default is `{parentTable}_{relatedTable}` — deterministic
 * concatenation, no singularization guessing (Radiant's pluralizer is
 * naive; a reverse singularizer would mangle irregulars). Pass an explicit
 * `$table` for any other name. The pivot key columns default to
 * `{parentTable}_id` / `{relatedTable}_id`.
 *
 * Pivot data rides the eager select: `withPivot()` adds pivot columns to
 * the select (aliased `radiant_pivot_{column}`), readable per related
 * model through `pivotValue()`. `withTimestamps()` is sugar for the
 * created_at/updated_at pair.
 *
 * The write API — attach/detach/sync/toggle — operates on the pivot table
 * directly through the connection's plain builder (no hydration, no
 * events); `sync()` wraps its diff in a transaction.
 *
 * @template TRelated of Model
 * @extends Relation<TRelated>
 * @phpstan-import-type KeyValue from \BlueprintAU\Radiant\Model
 */
class BelongsToMany extends Relation
{
    /**
     * The pivot table name.
     *
     * @var string
     */
    protected readonly string $pivotTable;

    /**
     * The pivot column pointing at the parent.
     *
     * @var string
     */
    protected readonly string $foreignPivotKey;

    /**
     * The pivot column pointing at the related model.
     *
     * @var string
     */
    protected readonly string $relatedPivotKey;

    /**
     * The parent-side key column (the parent's PK by default).
     *
     * @var string
     */
    protected readonly string $parentKey;

    /**
     * The related-side key column (the related model's PK by default).
     *
     * @var string
     */
    protected readonly string $relatedKey;

    /**
     * The pivot columns selected onto the related models.
     *
     * @var list<string>
     */
    protected array $pivotColumns = [];

    /**
     * Create a many-to-many relation.
     *
     * @param Model $parent The model owning the relation.
     * @param class-string<TRelated> $related The related model class.
     * @param string|null $table The pivot table name; null derives
     *        `{parentTable}_{relatedTable}`.
     * @param string|null $foreignPivotKey The pivot column → parent; null
     *        derives `{parentTable}_id`.
     * @param string|null $relatedPivotKey The pivot column → related; null
     *        derives `{relatedTable}_id`.
     * @param string|null $parentKey The parent's key column; null derives
     *        its primary key.
     * @param string|null $relatedKey The related model's key column; null
     *        derives its primary key.
     * @throws \InvalidArgumentException When a derived key is missing or
     *         the models' keys are composite (pivot keys are scalar-only
     *         in v1 — a composite tuple pivot has no portable eager
     *         strategy).
     */
    public function __construct(
        Model $parent,
        string $related,
        ?string $table = null,
        ?string $foreignPivotKey = null,
        ?string $relatedPivotKey = null,
        ?string $parentKey = null,
        ?string $relatedKey = null,
    ) {
        $this->pivotTable = $table ?? $parent::table() . '_' . $related::table();
        $this->foreignPivotKey = $foreignPivotKey ?? $parent::table() . '_id';
        $this->relatedPivotKey = $relatedPivotKey ?? $related::table() . '_id';
        $this->parentKey = $parentKey ?? self::singlePrimaryKeyOf($parent::class, 'parent');
        $this->relatedKey = $relatedKey ?? self::singlePrimaryKeyOf($related, 'related');

        parent::__construct($parent, $related, $this->relatedPivotKey, $this->relatedKey);
    }

    /**
     * A model's SINGLE primary-key column — pivot keys are scalar-only.
     *
     * @param class-string<Model> $class The model to resolve.
     * @param string $side Which side (for the message).
     * @return string The PK column name.
     * @throws \InvalidArgumentException When the model has no PK, an
     *         unnamed PK, or a composite PK.
     */
    private static function singlePrimaryKeyOf(string $class, string $side): string
    {
        $keys = MetadataFactory::for($class)->primaryKeys;

        if (count($keys) !== 1 || $keys[0]->name === null) {
            throw new \InvalidArgumentException(
                "A belongsToMany relation requires a single named primary key on the {$side} "
                . "model [{$class}]; pivot keys are scalar-only."
            );
        }

        return $keys[0]->name;
    }

    /**
     * The pivot table name.
     *
     * @return string The table.
     */
    final public function getPivotTable(): string
    {
        return $this->pivotTable;
    }

    /**
     * The pivot column pointing at the parent.
     *
     * @return string The column.
     */
    final public function getForeignPivotKey(): string
    {
        return $this->foreignPivotKey;
    }

    /**
     * The pivot column pointing at the related model.
     *
     * @return string The column.
     */
    final public function getRelatedPivotKey(): string
    {
        return $this->relatedPivotKey;
    }

    /**
     * Select pivot columns onto the related models.
     *
     * Each column is selected aliased `radiant_pivot_{column}` and readable
     * through `pivotValue()` on the related model. Calling it again
     * REPLACES the list (Laravel's semantics). A pivot column must not
     * start with the ORM's reserved `radiant_` prefix — the alias would
     * collide with the reserved namespace the row lift treats as internal
     * state.
     *
     * @param string ...$columns The pivot columns to carry.
     * @return static The relation (chainable).
     * @throws \InvalidArgumentException When a pivot column starts with
     *         the reserved `radiant_` prefix.
     */
    final public function withPivot(string ...$columns): static
    {
        foreach ($columns as $column) {
            Model::assertNotReservedPrefix($column, 'pivot column');
        }

        $this->pivotColumns = array_values($columns);
        $this->markComposed();

        return $this;
    }

    /**
     * Carry the pivot's created_at/updated_at pair — sugar for
     * `withPivot('created_at', 'updated_at')`.
     *
     * @return static The relation (chainable).
     */
    final public function withTimestamps(): static
    {
        return $this->withPivot('created_at', 'updated_at');
    }

    /**
     * Constrain the query: join the pivot, filter by the parent's key.
     *
     * @return void
     */
    #[\Override]
    protected function addConstraints(): void
    {
        $relatedTable = $this->related::table();

        $this->query = $this->query->join(
            $this->pivotTable,
            self::qualify($relatedTable, $this->relatedKey),
            '=',
            self::qualify($this->pivotTable, $this->relatedPivotKey),
        );

        $parentKey = $this->parent->attribute($this->parentKey);

        if ($parentKey === null) {
            // Null parent key → no results, without compiling a meaningless
            // query (BelongsTo's convention).
            $this->query = $this->query->whereRaw('1 = 0', []);
            return;
        }

        $this->query = $this->query->where(
            self::qualify($this->pivotTable, $this->foreignPivotKey),
            '=',
            $parentKey,
        );
    }

    /**
     * Qualify a column to its table — `table.column`.
     *
     * @param string $table The owning table.
     * @param string $column The column name.
     * @return string The qualified spec.
     */
    final protected static function qualify(string $table, string $column): string
    {
        return $table . '.' . $column;
    }

    /**
     * Run the constrained query.
     *
     * When `withPivot()` columns are declared, the lazy path selects them
     * aliased `radiant_pivot_{column}` — the same lift the eager path's
     * raw select performs, so `pivotValue()` works on both paths.
     *
     * @return Collection<TRelated> The related models.
     */
    #[\Override]
    protected function executeResults(): Collection
    {
        if ($this->pivotColumns !== []) {
            $selects = [];

            foreach ($this->pivotColumns as $column) {
                $selects[] = self::qualify($this->pivotTable, $column) . ' as radiant_pivot_' . $column;
            }

            $selects[] = $this->related::table() . '.*';
            $this->query = $this->query->select(...$selects);
        }

        return $this->query->get();
    }

    /**
     * Run the eager query: join the pivot for ALL parents at once,
     * selecting the parent key alongside the related columns.
     *
     * The per-row parent keys ride the EagerResult (the through-relation
     * statelessness pattern — the relation object is cached and shared, so
     * per-call state never lands on the instance).
     *
     * @param list<KeyValue> $parentKeys The parents' key values.
     * @return EagerResult<TRelated> The models plus the per-row parent keys.
     */
    #[\Override]
    public function eagerLoad(array $parentKeys): EagerResult
    {
        if ($parentKeys === []) {
            return EagerResult::fromModels([]);
        }

        $models = [];
        $parentKeysOut = [];

        foreach (array_chunk($parentKeys, self::EAGER_KEY_CHUNK) as $chunk) {
            $chunkResult = $this->eagerLoadChunk($chunk);
            array_push($models, ...$chunkResult->models->all());
            array_push($parentKeysOut, ...($chunkResult->parentKeys ?? []));
        }

        return new EagerResult(EagerResult::listToCollection($models), $parentKeysOut);
    }

    /**
     * Run one eager-load query for a CHUNK of parent keys — the join +
     * synthetic-parent-key select for one bounded key list.
     *
     * @param list<KeyValue> $parentKeys The chunk's key values.
     * @return EagerResult<TRelated> The models plus the per-row parent keys,
     *         positionally paired.
     */
    #[\Override]
    protected function eagerLoadChunk(array $parentKeys): EagerResult
    {
        $relatedTable = $this->related::table();
        $parentFk = 'radiant_pivot_parent_' . $this->pivotTable;

        $builder = $this->related::newQuery()
            ->join(
                $this->pivotTable,
                self::qualify($relatedTable, $this->relatedKey),
                '=',
                self::qualify($this->pivotTable, $this->relatedPivotKey),
            )
            ->whereIn(
                self::qualify($this->pivotTable, $this->foreignPivotKey),
                $parentKeys,
            );

        // The parent-key select carries the synthetic alias; the pivot
        // columns ride alongside (aliased per column).
        $selects = [
            self::qualify($this->pivotTable, $this->foreignPivotKey) . ' as ' . $parentFk,
        ];

        foreach ($this->pivotColumns as $column) {
            $selects[] = self::qualify($this->pivotTable, $column) . ' as radiant_pivot_' . $column;
        }

        $selects[] = "{$relatedTable}.*";

        $builder = $builder->select(...$selects);

        $rows = $builder->getRaw();

        $keys = [];
        $models = [];

        foreach ($rows->all() as $row) {
            $keys[] = $row->{$parentFk} ?? null;
            $models[] = $this->related::fromRow($row);
        }

        return new EagerResult(EagerResult::listToCollection($models), $keys);
    }

    /**
     * Distribute eager results onto parents, grouped by the parent key
     * carried on the EagerResult.
     *
     * @param list<Model> $parents The parents to populate.
     * @param Collection<TRelated> $results The related models.
     * @param string $name The relation name (the cache key).
     * @param list<int|string|null|list<int|string|null>>|null $eagerParentKeys
     *        The per-row parent keys from eagerLoad(), positionally paired
     *        with the results.
     * @return void
     */
    #[\Override]
    final public function match(array $parents, Collection $results, string $name, ?array $eagerParentKeys = null): void
    {
        if ($eagerParentKeys === null) {
            throw new \LogicException(
                static::class . '::match() requires the EagerResult parent keys; '
                . 'call it with the array returned by eagerLoad(), not the models alone.'
            );
        }

        $grouped = [];

        foreach ($results as $i => $model) {
            $parentKey = $eagerParentKeys[$i] ?? null;

            if ($parentKey === null) {
                continue;
            }

            $grouped[self::serializeKey($parentKey)][] = $model;
        }

        foreach ($parents as $parent) {
            $key = $parent->attribute($this->parentKey);
            $parent->setRelation($name, Collection::make($grouped[self::serializeKey($key)] ?? []));
        }
    }

    // ---- Pivot write API (SQL-only) ----

    /**
     * The parent's SQL connection — the pivot writes run on the plain
     * builder (no hydration, no events). A non-SQL connection fails fast:
     * the pivot API is join-backed and cannot work on CSV.
     *
     * @return SqlConnection The connection.
     * @throws UnsupportedFeatureException When the connection is not SQL.
     */
    protected function sqlConnection(): SqlConnection
    {
        $connection = $this->parent::connection();

        SqlConnection::assertSql($connection);

        return $connection;
    }

    /**
     * Attach related models to the parent — INSERT into the pivot.
     *
     * @param int|string|list<int|string>|array<string, mixed> $ids A single
     *        id, a list of ids, or a map of id => pivot attributes.
     * @param array<string, mixed> $pivotAttributes Attributes for EVERY
     *        attached row (merged under any per-id map values).
     * @return void
     * @throws \RuntimeException When the connection is not a SQL connection.
     */
    public function attach(int|string|array $ids, array $pivotAttributes = []): void
    {
        $connection = $this->sqlConnection();

        $rows = [];

        foreach ($this->normalizeIds($ids) as $id => $attributes) {
            $rows[] = [
                $this->foreignPivotKey => $this->parent->attribute($this->parentKey),
                $this->relatedPivotKey => $id,
                ...$pivotAttributes,
                ...$attributes,
            ];
        }

        if ($rows === []) {
            return;
        }

        $connection->table($this->pivotTable)->insert($rows);
    }

    /**
     * Detach related models from the parent — DELETE from the pivot.
     *
     * @param int|string|list<int|string>|null $ids The ids to detach; null
     *        detaches ALL of the parent's pivot rows.
     * @return int The number of detached rows.
     * @throws \RuntimeException When the connection is not a SQL connection.
     */
    final public function detach(int|string|array|null $ids = null): int
    {
        $connection = $this->sqlConnection();

        $query = $connection->table($this->pivotTable)
            ->where($this->foreignPivotKey, WhereOperator::Eq, $this->parent->attribute($this->parentKey));

        if ($ids !== null) {
            $query = $query->whereIn($this->relatedPivotKey, is_array($ids) ? $ids : [$ids]);
        }

        return $query->delete();
    }

    /**
     * Sync the pivot to EXACTLY the given ids — attach the missing,
     * detach the extra, update the shared.
     *
     * Runs inside a transaction: a partial sync (attached but not
     * detached) would leave the pivot in a state neither the caller nor
     * the diff describes.
     *
     * @param list<int|string>|array<string, mixed> $ids The desired ids —
     *        a list, or a map of id => pivot attributes (shared attributes
     *        update in place).
     * @param bool $detaching Whether to detach ids NOT in the list (false
     *        makes this `syncWithoutDetaching`).
     * @return array{attached: list<int|string>, detached: list<int|string>, updated: list<int|string>} The diff.
     * @throws \RuntimeException When the connection is not a SQL connection.
     */
    final public function sync(array $ids, bool $detaching = true): array
    {
        $connection = $this->sqlConnection();

        $desired = $this->normalizeIds($ids);
        $current = $this->currentPivotRows($connection);

        $attached = [];
        $detached = [];
        $updated = [];

        $isList = !in_array(true, array_map(is_array(...), $ids), true);

        $sharedAttributes = $isList ? ($desired === [] ? [] : reset($desired)) : [];
        $perIdAttributes = $isList ? [] : $desired;

        $connection->transaction(function () use ($connection, $desired, $current, $sharedAttributes, $perIdAttributes, $isList, $detaching, &$attached, &$detached, &$updated): void {
            $table = $connection->table($this->pivotTable);

            foreach ($desired as $id => $attributes) {
                $attributes = $isList ? $sharedAttributes : ($perIdAttributes[$id] ?? []);

                if (!isset($current[$id])) {
                    $table->insert([
                        $this->foreignPivotKey => $this->parent->attribute($this->parentKey),
                        $this->relatedPivotKey => $id,
                        ...$attributes,
                    ]);
                    $attached[] = $id;
                } elseif ($attributes !== [] && $current[$id] !== $attributes) {
                    $table
                        ->where($this->foreignPivotKey, WhereOperator::Eq, $this->parent->attribute($this->parentKey))
                        ->where($this->relatedPivotKey, WhereOperator::Eq, $id)
                        ->update($attributes);
                    $updated[] = $id;
                }
            }

            if ($detaching) {
                foreach (array_keys($current) as $id) {
                    if (!isset($desired[$id])) {
                        $table
                            ->where($this->foreignPivotKey, WhereOperator::Eq, $this->parent->attribute($this->parentKey))
                            ->where($this->relatedPivotKey, WhereOperator::Eq, $id)
                            ->delete();
                        $detached[] = $id;
                    }
                }
            }
        });

        return ['attached' => $attached, 'detached' => $detached, 'updated' => $updated];
    }

    /**
     * Sync WITHOUT detaching the ids not in the list — attach the missing
     * only.
     *
     * @param list<int|string>|array<string, mixed> $ids The desired ids.
     * @return array{attached: list<int|string>, detached: list<int|string>, updated: list<int|string>} The diff
     *         (detached is always empty).
     */
    final public function syncWithoutDetaching(array $ids): array
    {
        return $this->sync($ids, detaching: false);
    }

    /**
     * Toggle the given ids — attach the ones not attached, detach the
     * ones attached.
     *
     * @param list<int|string> $ids The ids to flip.
     * @return array{attached: list<int|string>, detached: list<int|string>} The diff.
     * @throws \RuntimeException When the connection is not a SQL connection.
     */
    final public function toggle(array $ids): array
    {
        $connection = $this->sqlConnection();
        $current = $this->currentPivotRows($connection);

        $attached = [];
        $detached = [];

        $table = $connection->table($this->pivotTable);

        foreach ($ids as $id) {
            if (isset($current[$id])) {
                $table
                    ->where($this->foreignPivotKey, WhereOperator::Eq, $this->parent->attribute($this->parentKey))
                    ->where($this->relatedPivotKey, WhereOperator::Eq, $id)
                    ->delete();
                $detached[] = $id;
            } else {
                $table->insert([
                    $this->foreignPivotKey => $this->parent->attribute($this->parentKey),
                    $this->relatedPivotKey => $id,
                ]);
                $attached[] = $id;
            }
        }

        return ['attached' => $attached, 'detached' => $detached];
    }

    /**
     * The parent's current pivot rows, keyed by related id.
     *
     * @param SqlConnection $connection The connection.
     * @return array<int|string, array<string, mixed>> id => pivot row.
     */
    private function currentPivotRows(SqlConnection $connection): array
    {
        $rows = $connection->table($this->pivotTable)
            ->where($this->foreignPivotKey, WhereOperator::Eq, $this->parent->attribute($this->parentKey))
            ->get();

        $current = [];

        foreach ($rows as $row) {
            $current[$row->{$this->relatedPivotKey}] = (array) $row;
        }

        return $current;
    }

    /**
     * Normalize the attach/sync id input to a map of id => attributes.
     *
     * The list/map distinction is VALUE-based, not key-based: a map like
     * `[1 => ['position' => 'x'], 2]` has int keys but is not a list —
     * any array value marks the input as a map (id => attributes), and a
     * bare scalar under an int key in a map is an id without attributes.
     *
     * @param int|string|list<int|string>|array<string, mixed> $ids The raw
     *        input.
     * @return array<int|string, array<string, mixed>> id => attributes.
     */
    protected function normalizeIds(int|string|array $ids): array
    {
        if (is_int($ids) || is_string($ids)) {
            return [$ids => []];
        }

        $isMap = false;

        foreach ($ids as $value) {
            if (is_array($value)) {
                $isMap = true;
                break;
            }
        }

        $normalized = [];

        if (!$isMap) {
            foreach ($ids as $id) {
                $normalized[$id] = [];
            }

            return $normalized;
        }

        foreach ($ids as $key => $value) {
            if (is_array($value)) {
                $normalized[$key] = $value;
            } else {
                $normalized[$value] = []; // bare id in a mixed map
            }
        }

        return $normalized;
    }
}
