<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Relations;

use BlueprintAU\Radiant\Database\Query\Enums\WhereOperator;
use BlueprintAU\Radiant\Database\Query\WhereBuilder;
use BlueprintAU\Radiant\Model;

/**
 * The shared base of the polymorphic one-to-one/one-to-many relations.
 *
 * A morph relation is a HasMany whose FK match carries a SECOND constraint:
 * the related rows must also declare THIS parent's morph alias in the
 * `{name}_type` column. Two parents of different classes can share the same
 * `{name}_id` value — the type column is what keeps their children apart.
 *
 * The morph alias is the parent model's FULL class-string (FQCN): stable
 * under namespaces, unambiguous across modules, and the same value the
 * inverse {@see MorphTo} dispatches on. Renaming a class changes the stored
 * alias — a data-migration concern, documented in docs/relations.md.
 *
 * @template TRelated of Model
 * @extends Relation<TRelated>
 */
abstract class MorphOneOrMany extends Relation
{
    /**
     * The type-discriminator column on the related table.
     *
     * @var string
     */
    protected readonly string $typeColumn;

    /**
     * Create a polymorphic relation.
     *
     * @param Model $parent The model owning the relation.
     * @param class-string<TRelated> $related The related model class.
     * @param string $foreignKey The FK column on the related table
     *        (`{name}_id` by convention).
     * @param string $localKey The parent-side key column.
     * @param string $typeColumn The type-discriminator column on the
     *        related table (`{name}_type` by convention).
     */
    public function __construct(
        Model $parent,
        string $related,
        string $foreignKey,
        string $localKey,
        string $typeColumn,
    ) {
        $this->typeColumn = $typeColumn;

        parent::__construct($parent, $related, $foreignKey, $localKey);
    }

    /**
     * The type-discriminator column on the related table.
     *
     * @return string The column name.
     */
    final public function getTypeColumn(): string
    {
        return $this->typeColumn;
    }

    /**
     * THIS parent's morph alias — the value the related table's type
     * column must hold to point back here.
     *
     * @return string The parent's FQCN.
     */
    final protected function parentMorphAlias(): string
    {
        return $this->parent::class;
    }

    /**
     * Apply the relation's constraint: the FK match PLUS the type filter.
     *
     * The two clauses land inside ONE whereNested group — the morph
     * constraint is a single unit (key AND type), so a caller's later
     * `->orWhere(...)` ORs at the constraint's edges, never against the
     * key or the type individually.
     *
     * @return void
     */
    #[\Override]
    protected function addConstraints(): void
    {
        $parentKey = $this->parent->attribute($this->getLocalKey());

        if ($parentKey === null) {
            // Null parent key → no results, without compiling a meaningless
            // query (BelongsTo's convention).
            $this->query = $this->query->whereRaw('1 = 0', []);
            return;
        }

        $alias = $this->parentMorphAlias();
        $foreignKey = $this->getForeignKey();
        $typeColumn = $this->typeColumn;

        $this->query = $this->query->whereNested(
            fn (WhereBuilder $nested): WhereBuilder => $nested
                ->where($foreignKey, WhereOperator::Eq, $parentKey)
                ->where($typeColumn, WhereOperator::Eq, $alias)
        );
    }

    /**
     * Apply the eager-path ordering AND the type filter to the chunk query.
     *
     * The eager chunk query is built fresh (the base eagerLoadChunk builds
     * `$this->related::newQuery()`), so the type filter must ride the
     * ordering hook — the one place subclasses decorate the chunk query
     * before the FK `IN` is applied.
     *
     * @param \BlueprintAU\Radiant\ModelQueryBuilder<TRelated> $query The chunk's eager query.
     * @return \BlueprintAU\Radiant\ModelQueryBuilder<TRelated> The filtered chunk query.
     */
    #[\Override]
    protected function applyEagerOrdering(\BlueprintAU\Radiant\ModelQueryBuilder $query): \BlueprintAU\Radiant\ModelQueryBuilder
    {
        // The chunk query is immutable — the type filter returns a new
        // instance, which the eager loader must receive.
        return $query->where($this->typeColumn, WhereOperator::Eq, $this->parentMorphAlias());
    }
}
