<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Relations;

use BlueprintAU\Radiant\Model;

/**
 * Many-to-many POLYMORPHIC: the pivot's parent-side key is a (type, key)
 * pair, so models of ANY class can share the same related pool through
 * one pivot table (`Post` and `Video` both tag through `taggables`).
 *
 * The pivot carries `{morphName}_id` + `{morphName}_type` on the parent
 * side and `{relatedTable}_id` on the related side. Every query — lazy
 * and eager — filters the type column to THIS parent's morph alias (the
 * FQCN convention {@see MorphOneOrMany} writes), and the write API
 * stamps the alias on every inserted row.
 *
 * `morphedByMany()` is the INVERSE direction: the parent is the RELATED
 * side of the pivot (a `Tag` lists every post and video tagged with it).
 * The constructor's `$inverse` flag swaps which side's alias filters the
 * type column and which side's key the queries filter on.
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
     * The morph alias THIS side filters (and writes) — the parent's FQCN
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
     * @param Model $parent The model owning the relation.
     * @param class-string<TRelated> $related The related model class.
     * @param string $morphName The morph alias prefix — the pivot's
     *        `{morphName}_id`/`{morphName}_type` columns.
     * @param string|null $table The pivot table name; null derives the
     *        morph name ITSELF (`taggable`) — deterministic and identical
     *        across every direction and parent class sharing the morph
     *        name (`Post::tags()`, `Video::tags()`, and `Tag::posts()`
     *        all land on the same pivot). Pass an explicit `$table` for
     *        any other name.
     * @param bool $inverse True for `morphedByMany` — the parent is the
     *        RELATED side of the pivot.
     * @throws \InvalidArgumentException When a model's primary key is
     *         composite or unnamed.
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
        $this->query->where(
            self::qualify($this->pivotTable, $this->morphTypeColumn),
            '=',
            $this->morphAlias,
        );
    }

    /**
     * Run one eager-load query for a CHUNK of parent keys — the base join
     * plus the morph type filter.
     *
     * @param list<int|string> $parentKeys The chunk's key values.
     * @return EagerResult<TRelated> The models plus the per-row parent keys.
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

        $builder->select(...$selects);

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
     * Stamp the morph alias onto every pivot row the write API inserts.
     *
     * @param array<string, mixed> $row The pivot row about to be written.
     * @return array<string, mixed> The row with the type column set.
     */
    protected function stampMorphAlias(array $row): array
    {
        $row[$this->morphTypeColumn] = $this->morphAlias;

        return $row;
    }

    /**
     * Attach related models — every inserted row carries the morph alias.
     *
     * @param int|string|list<int|string>|array<string, mixed> $ids A single
     *        id, a list of ids, or a map of id => pivot attributes.
     * @param array<string, mixed> $pivotAttributes Attributes for EVERY
     *        attached row.
     * @return void
     */
    #[\Override]
    final public function attach(int|string|array $ids, array $pivotAttributes = []): void
    {
        $connection = $this->sqlConnection();

        $rows = [];

        foreach ($this->normalizeIds($ids) as $id => $attributes) {
            $rows[] = $this->stampMorphAlias([
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
