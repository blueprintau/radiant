<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Relations;

use BlueprintAU\Radiant\Collection;
use BlueprintAU\Radiant\Database\Connections\SqlConnection;
use BlueprintAU\Radiant\Database\Query\QueryBuilder;
use BlueprintAU\Radiant\Model;

/**
 * Many-to-many polymorphic: the pivot's parent-side key is a (type, key)
 * pair, so models of any class share the same related pool through one
 * pivot table.
 *
 * Every query — lazy and eager — filters the type column to this side's
 * morph alias (the FQCN convention {@see MorphOneOrMany} writes), and the
 * write API stamps the alias on every inserted row. `morphedByMany()` is
 * the inverse direction: the constructor's `$inverse` flag swaps which
 * side's alias filters the type column and which side's key the queries
 * filter on.
 *
 * @template TRelated of Model
 * @template TPool of Model
 * @extends BelongsToMany<TRelated>
 */
class MorphToMany extends BelongsToMany
{
    /**
     * The type-discriminator column on the pivot table.
     *
     * @var string
     */
    protected readonly string $morphTypeColumn;

    /**
     * The morph alias this side filters (and writes) — the parent's FQCN
     * in the direct direction, the related's in the inverse.
     *
     * @var string
     */
    protected readonly string $morphAlias;

    /**
     * The pivot column carrying the morph key on THIS side.
     *
     * @var string
     */
    protected readonly string $morphKeyColumn;

    /**
     * The optional pool allowlist — the classes `pool()` may resolve.
     *
     * @var list<class-string<Model>>|null
     */
    protected readonly array|null $poolTypes;

    /**
     * Create a polymorphic many-to-many relation.
     *
     * @param  Model  $parent
     * @param  class-string<TRelated>  $related
     * @param  string  $morphName
     * @param  string|class-string<Model>|null  $table
     * @param  bool  $inverse  True for `morphedByMany`.
     * @param  list<class-string<TPool>>|null  $poolTypes  The pool allowlist for the inverse side's `pool()` read.
     * @throws \InvalidArgumentException
     */
    public function __construct(
        Model $parent,
        string $related,
        string $morphName,
        ?string $table = null,
        bool $inverse = false,
        array|null $poolTypes = null,
    ) {
        $this->morphTypeColumn = $morphName . '_type';
        $this->morphKeyColumn = $morphName . '_id';
        $this->morphAlias = $inverse ? $related : $parent::class;
        $this->poolTypes = $poolTypes === null || $poolTypes === [] ? null : $poolTypes;

        // The direct direction: the pivot's morph columns point at the
        // parent (Post), the related table's id column at the related
        // model (Tag). The INVERSE swaps the roles: the morph columns
        // point at the related (Post — the morph parent side), and the
        // PARENT's (Tag's) table id column is the other side.
        $foreignPivotKey = $this->morphKeyColumn;
        $relatedPivotKey = $related::table() . '_id';

        if ($inverse) {
            $foreignPivotKey = $parent::table() . '_id';
            $relatedPivotKey = $this->morphKeyColumn;
        }

        parent::__construct(
            $parent,
            $related,
            self::resolvePivotTable($table, 'pivot') ?? $morphName,
            $foreignPivotKey,
            $relatedPivotKey,
        );
    }

    /**
     * Constrain the query: the join + parent key filter PLUS the morph
     * type filter.
     *
     * @return void
     */
    #[\Override]
    protected function addConstraints(): void
    {
        parent::addConstraints();

        // The type filter rides AFTER the base constraint — the pivot
        // join is already in place, so the column resolves unambiguously.
        $this->query = $this->query->where(
            self::qualify($this->pivotTable, $this->morphTypeColumn),
            '=',
            $this->morphAlias,
        );
    }

    /**
     * Run one eager-load query for a CHUNK of parent keys — the base join
     * plus the morph type filter.
     *
     * @param  list<int|string>  $parentKeys
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
            ->where(
                self::qualify($this->pivotTable, $this->morphTypeColumn),
                '=',
                $this->morphAlias,
            )
            ->whereIn(
                self::qualify($this->pivotTable, $this->foreignPivotKey),
                $parentKeys,
            );

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
     * Scope every pivot READ/DELETE/UPDATE path to the morph alias — the
     * {@see BelongsToMany::pivotQuery()} hook.
     *
     * @param  SqlConnection  $connection
     * @return QueryBuilder
     */
    #[\Override]
    protected function pivotQuery(SqlConnection $connection): QueryBuilder
    {
        return parent::pivotQuery($connection)->where(
            self::qualify($this->pivotTable, $this->morphTypeColumn),
            '=',
            $this->morphAlias,
        );
    }

    /**
     * Stamp the morph alias onto every pivot row the write API inserts —
     * the {@see BelongsToMany::stampRow()} hook.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    #[\Override]
    protected function stampRow(array $row): array
    {
        $row[$this->morphTypeColumn] = $this->morphAlias;

        return $row;
    }

    /**
     * Attach related models — every inserted row carries the morph alias.
     *
     * @param  int|string|list<int|string>|array<string, mixed>  $ids
     * @param  array<string, mixed>  $pivotAttributes
     * @return void
     */
    #[\Override]
    final public function attach(int|string|array $ids, array $pivotAttributes = []): void
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

    // ---- The cross-type pool read ----

    /**
     * Read the shared pivot pool across every morph type.
     *
     * An allowlist restricts the read to exactly those classes and
     * ignores every other stored alias; the same list narrows the
     * static bound. Without an allowlist every stored alias resolves
     * and validates — an unknown type value fails fast. The read is
     * always fresh: it never serves the `with()` cache, never composes
     * the relation's filters, and pivot values ride along per query.
     *
     * @return Collection<TPool>
     * @throws \InvalidArgumentException
     * @throws \LogicException
     */
    public function pool(): Collection
    {
        if (!$this->isInversePool()) {
            throw new \LogicException(
                'pool() reads the shared pivot pool across morph types — available only on '
                . 'the inverse direction (morphedByMany), where this side\'s pivot columns '
                . 'carry a (type, key) pair. The direct direction resolves one static class.'
            );
        }

        $parentKey = $this->parent->attribute($this->parentKey);

        if ($parentKey === null) {
            return Collection::make([]);
        }

        $models = [];

        foreach ($this->poolAliases($parentKey) as $alias) {
            $class = $this->validatedPoolClass($alias);

            array_push($models, ...$this->poolQueryFor($class, $parentKey)->get()->all());
        }

        return Collection::make($models);
    }

    /**
     * Whether this side's pivot columns carry the morph (type, key) pair.
     *
     * @return bool
     */
    private function isInversePool(): bool
    {
        return $this->foreignPivotKey === $this->parent::table() . '_id';
    }

    /**
     * The morph aliases this pool read covers, in query order.
     *
     * The declared allowlist when present; otherwise every distinct type
     * value stored under this parent's pivot rows.
     *
     * @param  int|string  $parentKey
     * @return list<string>
     */
    private function poolAliases(int|string $parentKey): array
    {
        if ($this->poolTypes !== null) {
            return $this->poolTypes;
        }

        $rows = $this->sqlConnection()
            ->table($this->pivotTable)
            ->select($this->morphTypeColumn)
            ->distinct()
            ->where($this->foreignPivotKey, '=', $parentKey)
            ->get();

        $aliases = [];

        foreach ($rows as $row) {
            $alias = $row->{$this->morphTypeColumn} ?? null;

            if (is_string($alias) && $alias !== '') {
                $aliases[$alias] = true;
            }
        }

        return array_keys($aliases);
    }

    /**
     * Validate one resolved morph alias into a model class-string.
     *
     * @param  string  $alias
     * @return class-string<TPool>
     * @throws \InvalidArgumentException
     */
    private function validatedPoolClass(string $alias): string
    {
        if ($this->poolTypes !== null && !in_array($alias, $this->poolTypes, true)) {
            throw new \InvalidArgumentException(
                'Morph type [' . $alias . '] on pivot [' . $this->pivotTable
                . '] is not in the pool allowlist.'
            );
        }

        if (!class_exists($alias) || !is_a($alias, Model::class, true)) {
            throw new \InvalidArgumentException(
                'Morph type [' . $alias . '] on pivot [' . $this->pivotTable
                . '] does not resolve to an existing model class.'
            );
        }

        // The runtime checks back the template bound — the same inline
        // narrowing the MorphTo marker trick uses.
        /** @var class-string<TPool> */
        return $alias;
    }

    /**
     * Build one morph type's pool query.
     *
     * @param  class-string<TPool>  $class
     * @param  int|string  $parentKey
     * @return \BlueprintAU\Radiant\ModelQueryBuilder<TPool>
     */
    private function poolQueryFor(string $class, int|string $parentKey): \BlueprintAU\Radiant\ModelQueryBuilder
    {
        $typeTable = $class::table();

        $builder = $class::newQuery()
            ->join(
                $this->pivotTable,
                self::qualify($typeTable, 'id'),
                '=',
                self::qualify($this->pivotTable, $this->relatedPivotKey),
            )
            ->where(
                self::qualify($this->pivotTable, $this->morphTypeColumn),
                '=',
                $class,
            )
            ->where(
                self::qualify($this->pivotTable, $this->foreignPivotKey),
                '=',
                $parentKey,
            );

        if ($this->pivotColumns === []) {
            return $builder;
        }

        $selects = [];

        foreach ($this->pivotColumns as $column) {
            $selects[] = self::qualify($this->pivotTable, $column) . ' as radiant_pivot_' . $column;
        }

        $selects[] = "{$typeTable}.*";

        /** @var \BlueprintAU\Radiant\ModelQueryBuilder<TPool> */
        return $builder->select(...$selects);
    }
}
