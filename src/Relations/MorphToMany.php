<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Relations;

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
     * Create a polymorphic many-to-many relation.
     *
     * @param  Model  $parent
     * @param  class-string<TRelated>  $related
     * @param  string  $morphName
     * @param  string|null  $table
     * @param  bool  $inverse  True for `morphedByMany`.
     * @throws \InvalidArgumentException
     */
    public function __construct(
        Model $parent,
        string $related,
        string $morphName,
        ?string $table = null,
        bool $inverse = false,
    ) {
        $this->morphTypeColumn = $morphName . '_type';
        $this->morphKeyColumn = $morphName . '_id';
        $this->morphAlias = $inverse ? $related : $parent::class;

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
            $table ?? $morphName,
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
}
