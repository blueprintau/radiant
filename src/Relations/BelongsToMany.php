<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Relations;

use BlueprintAU\Radiant\Collection;
use BlueprintAU\Radiant\Database\Connections\SqlConnection;
use BlueprintAU\Radiant\Database\Query\QueryBuilder;
use BlueprintAU\Radiant\Database\Query\Enums\WhereOperator;
use BlueprintAU\Radiant\Database\Exceptions\UnsupportedFeatureException;
use BlueprintAU\Radiant\Metadata\MetadataFactory;
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\ModelQueryBuilder;

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
     * @param  Model  $parent
     * @param  class-string<TRelated>  $related
     * @param  string|null  $table
     * @param  string|null  $foreignPivotKey
     * @param  string|null  $relatedPivotKey
     * @param  string|null  $parentKey
     * @param  string|null  $relatedKey
     * @throws \InvalidArgumentException
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
     * @param  class-string<Model>  $class
     * @param  string  $side
     * @return string
     * @throws \InvalidArgumentException
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
     * @return string
     */
    final public function getPivotTable(): string
    {
        return $this->pivotTable;
    }

    /**
     * The pivot column pointing at the parent.
     *
     * @return string
     */
    final public function getForeignPivotKey(): string
    {
        return $this->foreignPivotKey;
    }

    /**
     * The pivot column pointing at the related model.
     *
     * @return string
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
     * replaces the list.
     *
     * @param  string  ...$columns
     * @return static
     * @throws \InvalidArgumentException
     */
    final public function withPivot(string ...$columns): static
    {
        foreach ($columns as $column) {
            Model::assertNotReservedPrefix($column, 'pivot column');
        }

        $clone = clone $this;
        $clone->pivotColumns = array_values($columns);

        return $clone->markComposed();
    }

    /**
     * Carry the pivot's created_at/updated_at pair — sugar for
     * `withPivot('created_at', 'updated_at')`.
     *
     * @return static
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
     * @param  string  $table
     * @param  string  $column
     * @return string
     */
    final protected static function qualify(string $table, string $column): string
    {
        return $table . '.' . $column;
    }

    /**
     * The constrained query — with the pivot select when pivot columns
     * are declared.
     *
     * @return ModelQueryBuilder<TRelated>
     */
    #[\Override]
    protected function readQuery(): ModelQueryBuilder
    {
        if ($this->pivotColumns === []) {
            return $this->getQuery();
        }

        $selects = [];

        foreach ($this->pivotColumns as $column) {
            $selects[] = self::qualify($this->pivotTable, $column) . ' as radiant_pivot_' . $column;
        }

        $selects[] = $this->related::table() . '.*';

        return $this->getQuery()->select(...$selects);
    }

    /**
     * Run the constrained query.
     *
     * @return Collection<TRelated>
     */
    #[\Override]
    protected function executeResults(): Collection
    {
        return $this->readQuery()->get();
    }

    /**
     * Run the eager query: join the pivot for ALL parents at once,
     * selecting the parent key alongside the related columns.
     *
     * @param  list<KeyValue>  $parentKeys
     * @return EagerResult<TRelated>
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
     * Run one eager-load query for a CHUNK of parent keys.
     *
     * @param  list<KeyValue>  $parentKeys
     * @return EagerResult<TRelated>
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
     * @param  list<Model>  $parents
     * @param  Collection<TRelated>  $results
     * @param  string  $name
     * @param  list<int|string|null|list<int|string|null>>|null  $eagerParentKeys
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
     * builder.
     *
     * @return SqlConnection
     * @throws UnsupportedFeatureException
     */
    protected function sqlConnection(): SqlConnection
    {
        $connection = $this->parent::connection();

        SqlConnection::assertSql($connection);

        return $connection;
    }

    /**
     * Stamp a pivot row about to be INSERTed — the subclass hook for
     * relation-specific columns.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function stampRow(array $row): array
    {
        return $row;
    }

    /**
     * Attach related models to the parent — INSERT into the pivot.
     *
     * @param  int|string|list<int|string>|array<string, mixed>  $ids
     * @param  array<string, mixed>  $pivotAttributes
     * @return void
     * @throws \RuntimeException
     */
    public function attach(int|string|array $ids, array $pivotAttributes = []): void
    {
        $connection = $this->sqlConnection();

        $rows = [];

        foreach ($this->normalizeIds($ids) as $id => $attributes) {
            $rows[] = $this->stampRow([
                $this->foreignPivotKey => $this->parent->attribute($this->parentKey),
                $this->relatedPivotKey => $id,
                ...$pivotAttributes,
                ...$attributes,
            ]);
        }

        if ($rows === []) {
            return;
        }

        $connection->table($this->pivotTable)->insert($rows);
    }

    /**
     * A query builder on the pivot table — the shared entry point for
     * every pivot READ/DELETE/UPDATE path.
     *
     * @param  SqlConnection  $connection
     * @return QueryBuilder
     */
    protected function pivotQuery(SqlConnection $connection): QueryBuilder
    {
        return $connection->table($this->pivotTable);
    }

    /**
     * Detach related models from the parent — DELETE from the pivot.
     *
     * @param  int|string|list<int|string>|null  $ids  Null detaches all.
     * @return int
     * @throws \RuntimeException
     */
    final public function detach(int|string|array|null $ids = null): int
    {
        $connection = $this->sqlConnection();

        $query = $this->pivotQuery($connection)
            ->where($this->foreignPivotKey, WhereOperator::Eq, $this->parent->attribute($this->parentKey));

        if ($ids !== null) {
            $query = $query->whereIn($this->relatedPivotKey, is_array($ids) ? $ids : [$ids]);
        }

        return $query->delete();
    }

    /**
     * Sync the pivot to exactly the given ids — attach the missing,
     * detach the extra, update the shared.
     *
     * Runs inside a transaction.
     *
     * @param  list<int|string>|array<string, mixed>  $ids
     * @param  bool  $detaching
     * @return array{attached: list<int|string>, detached: list<int|string>, updated: list<int|string>}
     * @throws \RuntimeException
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
            $table = $this->pivotQuery($connection);

            foreach ($desired as $id => $attributes) {
                $attributes = $isList ? $sharedAttributes : ($perIdAttributes[$id] ?? []);

                if (!isset($current[$id])) {
                    $table->insert([$this->stampRow([
                        $this->foreignPivotKey => $this->parent->attribute($this->parentKey),
                        $this->relatedPivotKey => $id,
                        ...$attributes,
                    ])]);
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
     * Sync without detaching the ids not in the list — attach the missing
     * only.
     *
     * @param  list<int|string>|array<string, mixed>  $ids
     * @return array{attached: list<int|string>, detached: list<int|string>, updated: list<int|string>}
     */
    final public function syncWithoutDetaching(array $ids): array
    {
        return $this->sync($ids, detaching: false);
    }

    /**
     * Toggle the given ids — attach the ones not attached, detach the
     * ones attached.
     *
     * @param  list<int|string>  $ids
     * @return array{attached: list<int|string>, detached: list<int|string>}
     * @throws \RuntimeException
     */
    final public function toggle(array $ids): array
    {
        $connection = $this->sqlConnection();
        $current = $this->currentPivotRows($connection);

        $attached = [];
        $detached = [];

        $table = $this->pivotQuery($connection);

        foreach ($ids as $id) {
            if (isset($current[$id])) {
                $table
                    ->where($this->foreignPivotKey, WhereOperator::Eq, $this->parent->attribute($this->parentKey))
                    ->where($this->relatedPivotKey, WhereOperator::Eq, $id)
                    ->delete();
                $detached[] = $id;
            } else {
                $table->insert([$this->stampRow([
                    $this->foreignPivotKey => $this->parent->attribute($this->parentKey),
                    $this->relatedPivotKey => $id,
                ])]);
                $attached[] = $id;
            }
        }

        return ['attached' => $attached, 'detached' => $detached];
    }

    /**
     * The parent's current pivot rows, keyed by related id.
     *
     * @param  SqlConnection  $connection
     * @return array<int|string, array<string, mixed>>
     */
    private function currentPivotRows(SqlConnection $connection): array
    {
        $rows = $this->pivotQuery($connection)
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
     * The list/map distinction is value-based, not key-based: any array
     * value marks the input as a map.
     *
     * @param  int|string|list<int|string>|array<string, mixed>  $ids
     * @return array<int|string, array<string, mixed>>
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
