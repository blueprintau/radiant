<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Relations;

use BlueprintAU\Radiant\Collection;
use BlueprintAU\Radiant\Database\Query\WhereBuilder;
use BlueprintAU\Radiant\Database\Query\Enums\WhereOperator;
use BlueprintAU\Radiant\Metadata\MetadataFactory;
use BlueprintAU\Radiant\Model;

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
    protected readonly string $through;

    /**
     * FK on the intermediate table pointing back at the parent.
     *
     * @var string|list<string>
     */
    protected readonly string|array $firstKey;

    /**
     * FK on the related table pointing at the intermediate.
     *
     * @var string|list<string>
     */
    protected readonly string|array $secondKey;

    /**
     * Create a through relation.
     *
     * @param  Model  $parent
     * @param  class-string<TRelated>  $related
     * @param  class-string<Model>  $through
     * @param  string|list<string>  $firstKey
     * @param  string|list<string>  $secondKey
     * @param  string|list<string>  $localKey
     * @throws \InvalidArgumentException
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
        // the constraint's EDGES, never against the tuple's PARTS. The
        // values come from the parent's LOCAL key columns (positionally
        // paired with the first keys) — the first-key names are the THROUGH
        // table's FK columns and do not exist on the parent.
        $localKeys = $this->getLocalKeys();

        $this->query = $this->query->whereNested(
            function (WhereBuilder $nested) use ($throughTable, $firstKeys, $localKeys): WhereBuilder {
                foreach ($firstKeys as $i => $firstKey) {
                    $value = $this->parent->attribute($localKeys[$i]);
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
     * The intermediate table's PK column(s) that the related table's FK
     * points at.
     *
     * @param  list<string>  $secondKeys
     * @return list<string>
     * @throws \InvalidArgumentException
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
     * The related→through FK columns as a plain list.
     *
     * @return list<string>
     */
    private function secondKeyList(): array
    {
        return is_array($this->secondKey) ? $this->secondKey : [$this->secondKey];
    }

    /**
     * The composite form of the related→through FK columns ($secondKey).
     *
     * @return list<string>
     * @throws \LogicException
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
     * @return Collection<int, TRelated>
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
     * @param  list<KeyValue>  $parentKeys
     * @return EagerResult<TRelated>
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
     * Run one eager-load query for a CHUNK of parent keys.
     *
     * @param  list<KeyValue>  $parentKeys
     * @return EagerResult<TRelated>
     */
    #[\Override]
    protected function eagerLoadChunk(array $parentKeys): EagerResult
    {
        $throughTable = $this->through::table();
        $relatedTable = $this->related::table();
        $parentFk = self::throughParentAlias($this->related);

        $firstKeys = $this->isComposite() ? $this->getForeignKeys() : [$this->getForeignKey()];
        $localKeys = $this->isComposite() ? $this->getLocalKeys() : [$this->getLocalKey()];
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
            // The OR-of-groups lands INSIDE one outer AND-group: the key
            // set is ONE constraint unit. The related builder auto-applies
            // trait scopes (e.g. soft-delete `deleted_at IS NULL`) as
            // leading AND-groups — flat top-level ORs would compile to
            // `(scope) OR (fk = ? AND ...) OR ...` and let a scope-excluded
            // row back in whenever its key matched. Grouped, the scope
            // ANDs against the whole set.
            $builder = $builder->whereNested(
                function (WhereBuilder $nested) use ($throughTable, $firstKeys, $localKeys, $parentKeys): WhereBuilder {
                    $grouped = $nested;

                    foreach ($parentKeys as $parentKey) {
                        if (!is_array($parentKey)) {
                            throw new \InvalidArgumentException(
                                'A composite through-relation key requires column => value key maps '
                                . 'for eager loading; got ' . get_debug_type($parentKey) . '.'
                            );
                        }

                        $grouped = $grouped->orWhereNested(
                            function (WhereBuilder $keyGroup) use ($throughTable, $firstKeys, $localKeys, $parentKey): WhereBuilder {
                                foreach ($firstKeys as $i => $firstKey) {
                                    // The key map is keyed by the parent's
                                    // LOCAL key columns (what the loader
                                    // collects); the constraint targets the
                                    // THROUGH table's first-key columns.
                                    $value = $parentKey[$localKeys[$i]] ?? null;
                                    $keyGroup = $keyGroup->where(
                                        self::qualify($throughTable, $firstKey),
                                        $value === null ? WhereOperator::Null : WhereOperator::Eq,
                                        $value,
                                    );
                                }

                                return $keyGroup;
                            }
                        );
                    }

                    return $grouped;
                }
            );
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
     * @param  class-string<Model>  $related
     * @return string
     */
    private static function throughParentAlias(string $related): string
    {
        return 'radiant_through_parent_' . $related::table();
    }

    /**
     * Distribute eager results onto parents, grouped by the parent key
     * carried on the {@see EagerResult}.
     *
     * @param  list<Model>  $parents
     * @param  Collection<int, TRelated>  $results
     * @param  string  $name
     * @param  list<int|string|null|list<int|string|null>>|null  $eagerParentKeys
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
            // The bag's items came off $results (TRelated) — every one IS
            // a Model; setRelation accepts Collection<int, Model> and the item
            // template is not covariant.
            /** @var Collection<int, Model> $bag */
            $bag = Collection::make($grouped[self::serializeKey($key)] ?? []);
            $parent->setRelation($name, $bag);
        }
    }
}
