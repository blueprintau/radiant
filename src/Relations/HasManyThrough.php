<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Relations;

use BlueprintAU\Radiant\Collection;
use BlueprintAU\Radiant\Database\Query\WhereBuilder;
use BlueprintAU\Radiant\Database\Query\Enums\WhereOperator;
use BlueprintAU\Radiant\Metadata\MetadataFactory;
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\ModelQueryBuilder;

/**
 * A two-hop relation: the parent links to the related model THROUGH an
 * intermediate model (`Mechanic` has many `Owners` through `Car`).
 *
 * The query INNER JOINs the intermediate table: a parent with no
 * intermediate row legitimately has no through-result, so INNER is the
 * honest semantics. Joins are a SQL-only feature — a non-SQL connection
 * throws {@see \BlueprintAU\Radiant\Database\Exceptions\UnsupportedFeatureException}
 * at execution, mirroring every other join path.
 *
 * @template TRelated of Model
 * @extends Relation<TRelated>
 * @phpstan-import-type KeyValue from \BlueprintAU\Radiant\Model
 */
class HasManyThrough extends Relation
{
    /**
     * The intermediate model bridging parent and related.
     *
     * @var class-string<Model>
     */
    protected string $through;

    /**
     * FK on the intermediate table pointing back at the parent.
     *
     * @var string|list<string>
     */
    protected string|array $firstKey;

    /**
     * FK on the related table pointing at the intermediate.
     *
     * @var string|list<string>
     */
    protected string|array $secondKey;

    /**
     * Create a through relation.
     *
     * @param Model $parent The model owning the relation.
     * @param class-string<TRelated> $related The related model class.
     * @param class-string<Model> $through The intermediate model class.
     * @param string|list<string> $firstKey FK on the intermediate table → parent.
     * @param string|list<string> $secondKey FK on the related table → intermediate.
     * @param string|list<string> $localKey The parent-side key column (or list).
     * @throws \InvalidArgumentException When the key shapes do not line up
     *         (scalar/composite mixes are rejected by the base ctor; the
     *         two FK sides must also agree with EACH OTHER).
     */
    public function __construct(
        Model $parent,
        string $related,
        string $through,
        string|array $firstKey,
        string|array $secondKey,
        string|array $localKey,
    ) {
        // The base ctor validates foreignKey↔localKey agreement; the two
        // hop keys must additionally agree with each other (both scalar or
        // both composite, matching arity), or the join is unbuildable.
        if (is_array($firstKey) !== is_array($secondKey)) {
            throw new \InvalidArgumentException(
                "A through relation's first and second keys must be BOTH single columns or "
                . 'BOTH composite column lists; got one of each.'
            );
        }

        if (is_array($firstKey) && is_array($secondKey)) {
            if ($firstKey === [] || $secondKey === []) {
                throw new \InvalidArgumentException(
                    "A through relation's composite keys require at least one column; got an empty list."
                );
            }

            if (count($firstKey) !== count($secondKey)) {
                throw new \InvalidArgumentException(
                    "A through relation's composite first and second keys must have matching "
                    . 'arity; got ' . count($firstKey) . ' and ' . count($secondKey) . '.'
                );
            }
        }

        $this->through = $through;
        $this->firstKey = $firstKey;
        $this->secondKey = $secondKey;

        parent::__construct($parent, $related, $firstKey, $localKey);
    }

    /**
     * Constrain the query: join the intermediate table, filter by the
     * parent's key.
     *
     * A composite key joins AND filters on the full tuple: the join gets
     * one ON pair per key column, the parent filter one where per column
     * (null components are IS NULL).
     *
     * @return void
     */
    protected function addConstraints(): void
    {
        $throughTable = $this->through::table();
        $relatedTable = $this->related::table();

        // The through relation stores the PARENT-side link in the base
        // relation's key slots: foreignKey = the through table's FK back to
        // the parent (firstKey), localKey = the parent's own key. The
        // related→through hop rides $secondKey.
        $firstKeys = $this->isComposite() ? $this->getForeignKeys() : [$this->getForeignKey()];
        $secondKeys = $this->secondKeyList();
        $intermediateKeys = $this->intermediateKeys($secondKeys);

        $this->query = $this->query->join(
            $throughTable,
            self::qualify($relatedTable, $secondKeys[0]),
            '=',
            self::qualify($throughTable, $intermediateKeys[0]),
        );

        foreach (array_slice($secondKeys, 1) as $i => $secondKey) {
            $this->query = $this->query->on(
                self::qualify($relatedTable, $secondKey),
                '=',
                self::qualify($throughTable, $intermediateKeys[$i + 1]),
            );
        }

        if (!$this->isComposite()) {
            $parentKey = $this->parent->attribute($this->getLocalKey());

            if ($parentKey === null) {
                // Null parent key → no results, without compiling a
                // meaningless query (BelongsTo's convention).
                $this->query = $this->query->whereRaw('1 = 0', []);
                return;
            }

            $this->query = $this->query->where(
                self::qualify($throughTable, $firstKeys[0]),
                '=',
                $parentKey,
            );

            return;
        }

        // The tuple lands inside a whereNested GROUP — the parent filter is
        // ONE constraint unit: a caller's later `->orWhere(...)` must OR at
        // the constraint's EDGES, never against the tuple's PARTS.
        $this->query = $this->query->whereNested(
            function (WhereBuilder $nested) use ($throughTable, $firstKeys): WhereBuilder {
                foreach ($firstKeys as $firstKey) {
                    $value = $this->parent->attribute($firstKey);
                    $nested = $nested->where(
                        self::qualify($throughTable, $firstKey),
                        $value === null ? WhereOperator::Null : WhereOperator::Eq,
                        $value,
                    );
                }

                return $nested;
            }
        );
    }

    /**
     * Qualify a column to its table — `table.column` (the Grammar wraps
     * the segments; no raw splicing here).
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
     * The intermediate table's PK column(s) that the related table's FK
     * points at.
     *
     * A composite intermediate PK names ALL its columns — the join pairs
     * the related table's FK columns against them in declared order.
     *
     * @param list<string> $secondKeys The related-side FK columns (arity
     *        check against the intermediate PK).
     * @return list<string> The PK column names.
     * @throws \InvalidArgumentException When the intermediate has no
     *         primary key, an unnamed key, or the FK arity mismatches.
     */
    protected function intermediateKeys(array $secondKeys): array
    {
        $metadata = MetadataFactory::for($this->through);
        $names = [];

        foreach ($metadata->primaryKeys as $primaryKey) {
            if ($primaryKey->name === null) {
                throw new \InvalidArgumentException(
                    "Through-relation intermediate [{$this->through}] must have a named primary key."
                );
            }

            $names[] = $primaryKey->name;
        }

        if ($names === [] || $secondKeys === [] || count($names) !== count($secondKeys)) {
            throw new \InvalidArgumentException(
                "Through-relation intermediate [{$this->through}] has a primary key of "
                . count($names) . ' column(s); the second key declares ' . count($secondKeys)
                . ' — the join must pair every key column.'
            );
        }

        return $names;
    }

    /**
     * The related→through FK columns as a plain list — scalar wrapped,
     * composite passed through.
     *
     * @return list<string> The column list.
     */
    private function secondKeyList(): array
    {
        return is_array($this->secondKey) ? $this->secondKey : [$this->secondKey];
    }

    /**
     * The composite form of the related→through FK columns ($secondKey).
     *
     * @return list<string> The column list.
     * @throws \LogicException When the key is scalar.
     */
    final public function getSecondKeys(): array
    {
        return is_array($this->secondKey)
            ? $this->secondKey
            : throw new \LogicException(
                'This through relation uses a single second key.'
            );
    }

    /**
     * Run the constrained query.
     *
     * @return Collection<TRelated> The related models.
     */
    #[\Override]
    protected function executeResults(): Collection
    {
        return $this->query->get();
    }

    /**
     * Run the eager query: join the intermediate table for ALL parents at
     * once, selecting the parent key alongside the related columns.
     *
     * A composite first key widens the parent filter to an OR of AND-groups
     * (one nested group per parent tuple — portable, unlike tuple IN).
     *
     * The join's select is declared as plain `table.column` specs plus the
     * standard `as` alias form — the Grammar's {@see Grammar::wrapColumn()}
     * owns quoting and the `AS` rendering; nothing raw is spliced here.
     *
     * The per-row parent keys are returned IN the EagerResult rather than
     * recorded on the relation: a relation object is cached and shared
     * (ModelQueryBuilder::$relationCache), so instance state here would let
     * two interleaved eager loads of the same through-relation — concurrent
     * under Swoole/Fiber — read each other's keys and distribute children
     * to the wrong parents.
     *
     * @param list<KeyValue> $parentKeys The parents' local-key values —
     *        scalars, or column => value maps for a composite key.
     * @return EagerResult<TRelated> The models plus the per-row parent keys.
     */
    #[\Override]
    public function eagerLoad(array $parentKeys): EagerResult
    {
        if ($parentKeys === []) {
            return EagerResult::fromModels([]);
        }

        // Chunked: SQL size grows O(parents × arity); driver caps (SQLite
        // 999 placeholders, MySQL max_allowed_packet) turn an oversized
        // single query into a hard failure. One query per chunk, merged.
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
     * @return EagerResult<TRelated> The models plus the per-row parent keys, positionally
     *         paired (index i of parentKeys is the key of the parent that
     *         models[i] belongs to).
     */
    #[\Override]
    protected function eagerLoadChunk(array $parentKeys): EagerResult
    {
        $throughTable = $this->through::table();
        $relatedTable = $this->related::table();
        $parentFk = self::throughParentAlias($this->related);

        $firstKeys = $this->isComposite() ? $this->getForeignKeys() : [$this->getForeignKey()];
        $secondKeys = $this->secondKeyList();
        $intermediateKeys = $this->intermediateKeys($secondKeys);

        $builder = $this->related::newQuery()
            ->join(
                $throughTable,
                self::qualify($relatedTable, $secondKeys[0]),
                '=',
                self::qualify($throughTable, $intermediateKeys[0]),
            );

        // Subclass ordering hook (HasOneThrough): applies the related-PK
        // order so first-wins matching stays deterministic, exactly like
        // the lazy path. No-op for the base many-row relation.
        $builder = $this->applyEagerOrdering($builder);

        foreach (array_slice($secondKeys, 1) as $i => $secondKey) {
            $builder = $builder->on(
                self::qualify($relatedTable, $secondKey),
                '=',
                self::qualify($throughTable, $intermediateKeys[$i + 1]),
            );
        }

        if (!$this->isComposite()) {
            $builder = $builder->whereIn(
                self::qualify($throughTable, $firstKeys[0]),
                $parentKeys,
            );
        } else {
            foreach ($parentKeys as $parentKey) {
                if (!is_array($parentKey)) {
                    throw new \InvalidArgumentException(
                        'A composite through-relation key requires column => value key maps '
                        . 'for eager loading; got ' . get_debug_type($parentKey) . '.'
                    );
                }

                $builder = $builder->orWhereNested(
                    function (WhereBuilder $nested) use ($throughTable, $firstKeys, $parentKey): WhereBuilder {
                        foreach ($firstKeys as $firstKey) {
                            $value = $parentKey[$firstKey] ?? null;
                            $nested = $nested->where(
                                self::qualify($throughTable, $firstKey),
                                $value === null ? WhereOperator::Null : WhereOperator::Eq,
                                $value,
                            );
                        }

                        return $nested;
                    }
                );
            }
        }

        // Columns are plain (qualified) specs with the standard `as`
        // alias — the Grammar wraps them like any other column list. The
        // parent-key select carries EVERY first-key column, aliased to
        // the namespaced synthetic alias per column when composite.
        $selects = [];

        foreach ($firstKeys as $firstKey) {
            $selects[] = self::qualify($throughTable, $firstKey)
                . ' as '
                . ($firstKeys[0] === $firstKey ? $parentFk : $parentFk . '_' . $firstKey);
        }

        $selects[] = "{$relatedTable}.*";

        $builder = $builder->select(...$selects);

        $rows = $builder->getRaw();

        $keys = [];
        $models = [];

        foreach ($rows->all() as $row) {
            if (!$this->isComposite()) {
                $keys[] = $row->{$parentFk} ?? null;
            } else {
                $tuple = [];

                foreach ($firstKeys as $firstKey) {
                    $column = $firstKeys[0] === $firstKey ? $parentFk : $parentFk . '_' . $firstKey;
                    $tuple[] = $row->{$column} ?? null;
                }

                $keys[] = $tuple;
            }

            $models[] = $this->related::fromRow($row);
        }

        return new EagerResult(EagerResult::listToCollection($models), $keys);
    }

    /**
     * The synthetic alias carrying the parent key through the join.
     *
     * Namespaced PER RELATED CLASS (`radiant_through_parent_{$table}`): a
     * fixed alias collided with any real column of the same name — the
     * driver's row bag would hold two values for that key, the real
     * column would typically win, and children would be distributed to
     * the wrong parent. The related table's own name is part of the
     * alias, so a through-relation over two different related tables
     * never aliases the same synthetic name either.
     *
     * @param class-string<Model> $related The related model class.
     * @return string The synthetic alias.
     */
    private static function throughParentAlias(string $related): string
    {
        return 'radiant_through_parent_' . $related::table();
    }

    /**
     * Distribute eager results onto parents, grouped by the parent key
     * carried on the {@see EagerResult} (recorded per-call — the relation
     * object is cached and shared, so per-call state never lands here).
     *
     * A composite first key groups by the full tuple, serialized to a
     * stable string key.
     *
     * @param list<Model> $parents The parents to populate.
     * @param Collection<TRelated> $results The related models.
     * @param string $name The relation name (the cache key).
     * @param list<int|string|null|list<int|string|null>>|null $eagerParentKeys
     *        The per-row parent keys from eagerLoad(), positionally paired
     *        with the results.
     * @return void
     */
    public function match(array $parents, Collection $results, string $name, ?array $eagerParentKeys = null): void
    {
        if ($eagerParentKeys === null) {
            // A caller matched WITHOUT the eager-load context — the per-row
            // keys are unavailable. Fail loudly: silently matching by
            // re-querying (or matching nothing) would hide the contract.
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

        $localKeys = $this->isComposite() ? $this->getLocalKeys() : [$this->getLocalKey()];

        foreach ($parents as $parent) {
            $key = $this->isComposite()
                ? self::tupleValues($parent, $localKeys)
                : $parent->attribute($localKeys[0]);
            $parent->setRelation($name, Collection::make($grouped[self::serializeKey($key)] ?? []));
        }
    }
}
