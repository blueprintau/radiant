<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Relations;

use BlueprintAU\Radiant\Collection;
use BlueprintAU\Radiant\Database\Query\Enums\WhereOperator;
use BlueprintAU\Radiant\Metadata\MetadataFactory;
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\ModelQueryBuilder;

/**
 * The inverse polymorphic relation: the parent holds the (type, key) pair
 * and the related class is resolved per row from the type column.
 *
 * The constrained builder is built lazily per resolved type; eager loading
 * runs one chunked `IN` query per distinct type and merges the results
 * into a single mixed-class {@see EagerResult} dispatched by
 * {@see MorphTo::match()}. An optional `$types` allowlist restricts which
 * classes may resolve — and narrows the template statically to exactly
 * those classes; without one the honest bound is `Model`.
 *
 * @template TRelated of Model The classes the allowlist admits (Model
 *         when no allowlist is declared).
 * @extends Relation<TRelated>
 * @phpstan-import-type KeyValue from \BlueprintAU\Radiant\Model
 */
final class MorphTo extends Relation
{
    /**
     * The type-discriminator column on the parent's table.
     *
     * @var string
     */
    protected readonly string $typeColumn;

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
     * the type column, and the base's constraint pass is skipped via
     * {@see Relation::defersConstraints()}.
     *
     * @param  Model  $parent
     * @param  string  $typeColumn
     * @param  string  $foreignKey
     * @param  string  $ownerKey
     * @param  list<class-string<TRelated>>|null  $types
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
     * the related class resolves per parent from the type column.
     *
     * @return bool
     */
    #[\Override]
    protected function defersConstraints(): bool
    {
        return true;
    }

    /**
     * The related classes a dotted path's DEEPER segments resolve against.
     *
     * @return list<class-string<Model>>
     */
    #[\Override]
    public function relatedClasses(): array
    {
        return [];
    }

    /**
     * The type-discriminator column on the parent's table.
     *
     * @return string
     */
    final public function getTypeColumn(): string
    {
        return $this->typeColumn;
    }

    /**
     * Unused — the related class is dynamic; constraints build lazily.
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
     * @param  Model  $parent
     * @return class-string<Model>|null
     * @throws \InvalidArgumentException
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

        $this->assertKeyMatches($alias);

        return $alias;
    }

    /**
     * Fail fast when the resolved target's primary-key type cannot be
     * held by this parent's morph key column.
     *
     * @param  class-string<Model>  $alias
     * @return void
     * @throws \InvalidArgumentException
     */
    private function assertKeyMatches(string $alias): void
    {
        $declared = MetadataFactory::for($this->parent::class)
            ->mappingFor($this->getForeignKey())->column->type;
        $primaryKey = MetadataFactory::for($alias)->primaryKeys;

        if (count($primaryKey) !== 1 || $primaryKey[0]->name === null) {
            throw new \LogicException(
                'A morph target requires a single named primary key; model [' . $alias
                . '] declares none, a composite key, or an unnamed key.'
            );
        }

        if ($primaryKey[0]->type !== $declared) {
            throw new \InvalidArgumentException(
                'The morph key column [' . $this->getForeignKey() . '] on [' . $this->parent::class
                . '] is [' . $declared->value . '], but [' . $alias . ']\'s primary key is ['
                . $primaryKey[0]->type->value . ']. A morph pair can only point at models whose '
                . 'primary-key type matches the key column — declare the pair with a matching '
                . 'keyType (or uuidMorphs()).'
            );
        }
    }

    /**
     * The resolved type's constrained query — the row reads' target.
     *
     * @return ModelQueryBuilder<TRelated>
     */
    #[\Override]
    protected function readQuery(): ModelQueryBuilder
    {
        $alias = $this->aliasOf($this->parent);

        if ($alias === null) {
            // Null morph pair → no results, without compiling a meaningless
            // query (BelongsTo's convention). The no-match query builds on
            // the PARENT's class — the Model::class marker cannot (its
            // table() throws), and a `1 = 0` query never hydrates a row.
            /** @var ModelQueryBuilder<TRelated> */
            return ($this->parent)::newQuery()->whereRaw('1 = 0', []);
        }

        // aliasOf() validated the alias against the allowlist (or the
        // bound IS Model without one) — the resolved builder's rows are
        // all TRelated.
        /** @var ModelQueryBuilder<TRelated> */
        return $this->queryFor($alias);
    }

    /**
     * The related model class — the fail-fast exceptions' identity.
     *
     * The resolved alias when the morph pair resolves; the parent's own
     * class when it does not (a null pair has no related class — the
     * no-match query builds on the parent for the same reason).
     *
     * @return class-string<Model>
     */
    #[\Override]
    protected function relatedClass(): string
    {
        return $this->aliasOf($this->parent) ?? $this->parent::class;
    }

    /**
     * Build the constrained query for ONE resolved type.
     *
     * @param  class-string<Model>  $alias
     * @return ModelQueryBuilder<Model>
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
     * @return list<string>
     */
    #[\Override]
    public function eagerKeyColumn(): array
    {
        return [$this->typeColumn, $this->getForeignKey()];
    }

    /**
     * Run the eager queries — ONE chunked `IN` per distinct type.
     *
     * @param  list<KeyValue>  $parentKeys  The parents' [type, FK] tuples.
     * @return EagerResult<Model>
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

        /** @var list<Model> $models */
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

        $collection = Collection::make($models);

        return new EagerResult($collection, $pairs);
    }

    /**
     * Distribute eager results onto parents by (alias, key) pair.
     *
     * @param  list<Model>  $parents
     * @param  Collection<int, Model>  $results
     * @param  string  $name
     * @param  list<array{string, string}>|null  $eagerParentKeys
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
     * @return Collection<int, Model>
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
