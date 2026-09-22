<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Relations;

use BlueprintAU\Radiant\Collection;
use BlueprintAU\Radiant\Database\Query\Enums\WhereOperator;
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\ModelQueryBuilder;

/**
 * The inverse polymorphic relation: the PARENT holds the (type, key) pair
 * and the related class is resolved PER ROW from the type column.
 *
 * `Comment::commentable()` reads `commentable_type` (a model class-string
 * — the morph alias {@see MorphOneOrMany} writes) and `commentable_id`,
 * then queries THAT model's table for the matching key. One relation name
 * spans many target tables.
 *
 * Because the related class is dynamic, the constrained builder cannot be
 * built in the constructor (the base does) — it is built lazily per
 * resolved type. Eager loading groups the parents by type value and runs
 * ONE chunked `IN` query per distinct type (the per-type strategy: no
 * grammar changes, each type's rows hydrate through its own model class,
 * portable across backends), then merges the results into a single
 * mixed-class {@see EagerResult} whose per-row (alias, key) pairs
 * {@see MorphTo::match()} dispatches by.
 *
 * The static type rides the `$types` allowlist: a relation constructed
 * WITH an allowlist (`morphTo('commentable', types: [Post::class])`)
 * narrows its template to exactly those classes, so
 * `commentable()->getResults()->first()` types as `(Post|Video)|null` —
 * the same classes the runtime allowlist already enforces, now visible
 * statically. Without an allowlist the template is the honest bound
 * `Model` — any class can resolve, and callers narrow with a local
 * `instanceof`.
 *
 * An optional `$types` allowlist restricts which classes may resolve:
 * a parent whose type value is not on the list fails fast (a typo'd or
 * stale alias is a data bug, not an empty result).
 *
 * @template TRelated of Model The classes the allowlist admits (Model
 *         when no allowlist is declared).
 * @extends Relation<TRelated>
 * @phpstan-import-type KeyValue from \BlueprintAU\Radiant\Model
 */
final class MorphTo extends Relation
{
    /**
     * The type-discriminator column on THIS (the parent's) table.
     *
     * @var string
     */
    protected string $typeColumn;

    /**
     * The optional morph-alias allowlist.
     *
     * @var list<class-string<Model>>|null
     */
    private array|null $types;

    /**
     * Create the inverse polymorphic relation.
     *
     * The base constructor's `$related` slot is filled with the abstract
     * {@see Model::class} marker — the real class resolves per parent from
     * the type column, so no fixed related class exists at construction.
     * The base's query build + constraint pass are skipped via
     * {@see Relation::defersConstraints()} (overridden below) — the base
     * body RUNS, assigns the promoted properties, and returns early.
     *
     * @param Model $parent The model owning the relation.
     * @param string $typeColumn The type-discriminator column on THIS
     *        table (`{name}_type` by convention).
     * @param string $foreignKey The FK column on THIS table (`{name}_id`
     *        by convention).
     * @param string $ownerKey The key column on the TARGET tables (their
     *        primary key, by convention).
     * @param list<class-string<TRelated>>|null $types The optional
     *        morph-alias allowlist — null resolves any model class. The
     *        template binds to it: with an allowlist the relation's reads
     *        narrow to exactly those classes.
     */
    public function __construct(
        Model $parent,
        string $typeColumn,
        string $foreignKey,
        string $ownerKey,
        array|null $types = null,
    ) {
        // The marker slot: no fixed related class exists — the real class
        // resolves per parent from the type column, and the runtime allowlist
        // validates every resolution (aliasOf). Statically, Model::class does
        // not satisfy class-string<TRelated> for a narrowed allowlist, so the
        // marker narrows through an inline var: the declaration is backed by
        // the runtime check, exactly the conditional-return pattern the rest
        // of the ORM uses for dynamic types.
        /** @var class-string<TRelated> $marker */
        $marker = Model::class;

        parent::__construct($parent, $marker, $foreignKey, $ownerKey);

        $this->typeColumn = $typeColumn;
        $this->types = $types;
    }

    /**
     * The base constructor's query build + constraint pass cannot run —
     * the related class resolves per parent from the type column, so
     * neither the builder nor the constraint exists at construction.
     *
     * @return bool Always true.
     */
    #[\Override]
    protected function defersConstraints(): bool
    {
        return true;
    }

    /**
     * The related classes a dotted path's DEEPER segments resolve against.
     *
     * MorphTo's related set is dynamic — resolved per row from the type
     * column — so the path validator cannot check deeper segments
     * statically. Returning [] tells the validator to stop here; the
     * runtime recursion resolves the deeper segments off the actually-
     * loaded models.
     *
     * @return list<class-string<Model>> Always [] — the dynamic marker.
     */
    #[\Override]
    public function relatedClasses(): array
    {
        return [];
    }

    /**
     * The type-discriminator column on the parent's table.
     *
     * @return string The column name.
     */
    final public function getTypeColumn(): string
    {
        return $this->typeColumn;
    }

    /**
     * Unused — the related class is dynamic; constraints build lazily.
     *
     * The base declares this abstract; MorphTo's constructor does NOT call
     * it (it skips the base body), so this body only satisfies the
     * contract and fails loudly if a future refactor ever invokes it.
     *
     * @return void
     */
    #[\Override]
    protected function addConstraints(): void
    {
        throw new \LogicException(
            'MorphTo builds its constraints lazily per resolved type; addConstraints() '
            . 'must not be called directly.'
        );
    }

    /**
     * Resolve ONE parent's morph alias — the shared validation path.
     *
     * @param Model $parent The parent to resolve.
     * @return class-string<Model>|null The alias, or null when the type
     *         column is null (an unset morph target — legitimately empty).
     * @throws \InvalidArgumentException On a corrupt alias (non-string,
     *         unknown class, non-model class) or a disallowed one (not on
     *         the allowlist).
     */
    private function aliasOf(Model $parent): string|null
    {
        $alias = $parent->attribute($this->typeColumn);

        if ($alias === null) {
            return null;
        }

        if (!is_string($alias) || $alias === '') {
            throw new \InvalidArgumentException(
                'Morph type column [' . $this->typeColumn . '] on [' . $parent::class
                . '] holds a non-string value; the morph alias must be a model class-string.'
            );
        }

        if ($this->types !== null && !in_array($alias, $this->types, true)) {
            throw new \InvalidArgumentException(
                'Morph type [' . $alias . '] on [' . $parent::class
                . '] is not in the relation\'s allowlist.'
            );
        }

        if (!class_exists($alias) || !is_a($alias, Model::class, true)) {
            throw new \InvalidArgumentException(
                'Morph type [' . $alias . '] on [' . $parent::class
                . '] does not resolve to an existing model class.'
            );
        }

        return $alias;
    }

    /**
     * Build the constrained query for ONE resolved type.
     *
     * @param class-string<Model> $alias The resolved target class.
     * @return ModelQueryBuilder<Model> The constrained builder.
     */
    private function queryFor(string $alias): ModelQueryBuilder
    {
        $fkValue = $this->parent->attribute($this->getForeignKey());

        if ($fkValue === null) {
            // Null FK → no results, without compiling a meaningless query
            // (BelongsTo's convention).
            return $alias::newQuery()->whereRaw('1 = 0', []);
        }

        return $alias::newQuery()->where($this->getLocalKey(), WhereOperator::Eq, $fkValue);
    }

    /**
     * The parent column(s) the eager loader collects key values from.
     *
     * The (type, key) pair lives on the PARENT — the loader must collect
     * BOTH columns (the BelongsTo convention for the key, plus the type
     * column the eager strategy groups by). The pair travels through the
     * loader as the key tuple, keeping the eager contract key-shaped.
     *
     * @return list<string> The parent's [type, FK] columns.
     */
    #[\Override]
    public function eagerKeyColumn(): array
    {
        return [$this->typeColumn, $this->getForeignKey()];
    }

    /**
     * Run the eager queries — ONE chunked `IN` per distinct type.
     *
     * The parents' (type, key) pairs arrive as the key tuples (the loader
     * collects {@see eagerKeyColumn()}'s columns per parent and dedups
     * them). Each distinct type gets its own chunked query through its
     * own model class, and the results merge into one mixed-class
     * EagerResult whose per-row (alias, serialized key) pairs match()
     * dispatches by.
     *
     * @param list<KeyValue> $parentKeys The parents' [type, FK] tuples
     *        (deduplicated by the loader) — each a two-element list whose
     *        first element is the morph alias.
     * @return EagerResult<Model> The related models of ALL resolved types,
     *         with the per-row (alias, key) pairs match() dispatches by.
     */
    #[\Override]
    public function eagerLoad(array $parentKeys): EagerResult
    {
        if ($parentKeys === []) {
            return EagerResult::fromModels([]);
        }

        // Group the wanted keys by resolved type; within a type, index by
        // the serialized FK so each loaded row maps straight back to its
        // referencing parents.
        $byAlias = [];

        foreach ($parentKeys as $pair) {
            $alias = $pair[$this->typeColumn] ?? null;
            $fk = $pair[$this->getForeignKey()] ?? null;

            if (!is_string($alias) || $alias === '' || $fk === null) {
                throw new \InvalidArgumentException(
                    'A MorphTo eager load requires the (type, key) tuple keyed by its column '
                        . 'names [' . $this->typeColumn . ', ' . $this->getForeignKey() . ']; got '
                        . get_debug_type($pair) . '.'
                );
            }

            $byAlias[$alias][self::serializeKey($fk)] = true;
        }

        $models = [];
        $pairs = [];

        foreach ($byAlias as $alias => $wantedKeys) {
            foreach (array_chunk(array_keys($wantedKeys), self::EAGER_KEY_CHUNK) as $chunk) {
                $rows = $alias::newQuery()
                    ->whereIn($this->getLocalKey(), $chunk)
                    ->get();

                foreach ($rows as $model) {
                    $key = self::serializeKey($model->attribute($this->getLocalKey()));

                    if (!isset($wantedKeys[$key])) {
                        continue; // a row no parent in THIS load references
                    }

                    $models[] = $model;
                    $pairs[] = [$alias, $key];
                }
            }
        }

        return new EagerResult(Collection::make($models), $pairs);
    }

    /**
     * Distribute eager results onto parents by (alias, key) pair.
     *
     * @param list<Model> $parents The parents to populate.
     * @param Collection<Model> $results The related models of mixed classes.
     * @param string $name The relation name (the cache key).
     * @param list<array{string, string}>|null $eagerParentKeys The per-row
     *        (alias, serialized-key) pairs from eagerLoad(), positionally
     *        paired with the results.
     * @return void
     */
    #[\Override]
    public function match(array $parents, Collection $results, string $name, ?array $eagerParentKeys = null): void
    {
        if ($eagerParentKeys === null) {
            throw new \LogicException(
                static::class . '::match() requires the EagerResult (alias, key) pairs; '
                . 'call it with the array returned by eagerLoad(), not the models alone.'
            );
        }

        $byPair = [];

        foreach ($results as $i => $model) {
            $pair = $eagerParentKeys[$i] ?? null;

            if ($pair === null) {
                continue;
            }

            $byPair[$pair[0] . '|' . $pair[1]] = $model;
        }

        foreach ($parents as $parent) {
            $alias = $parent->attribute($this->typeColumn);
            $fk = $parent->attribute($this->getForeignKey());

            if (!is_string($alias) || $fk === null) {
                // No (type, key) pair → the single-valued relation loads
                // as NULL (an empty collection would lie about cardinality).
                $parent->setRelation($name, null);
                continue;
            }

            $model = $byPair[$alias . '|' . self::serializeKey($fk)] ?? null;
            $parent->setRelation($name, $model);
        }
    }

    /**
     * Run the constrained query against the parent's resolved type.
     *
     * @return Collection<Model> A one-element (or empty) collection — the
     *         single related model unwraps at the accessor.
     */
    #[\Override]
    protected function executeResults(): Collection
    {
        $alias = $this->aliasOf($this->parent);

        if ($alias === null) {
            return Collection::make([]);
        }

        $first = $this->queryFor($alias)->first();

        return Collection::make($first === null ? [] : [$first]);
    }
}
