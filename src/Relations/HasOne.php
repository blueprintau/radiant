<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Relations;

use BlueprintAU\Radiant\Collection;
use BlueprintAU\Radiant\Database\Query\WhereBuilder;
use BlueprintAU\Radiant\Metadata\MetadataFactory;
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\ModelQueryBuilder;

/**
 * One-to-one: HasMany's first row, stably ordered by the related PK.
 *
 * Multiple matching rows are a data concern, not a relation error — the
 * first row wins (ordered by the related model's PK for determinism) and
 * uniqueness is the schema's job (`#[Column(unique: true)]` on the FK).
 *
 * @template TRelated of Model
 * @extends HasMany<TRelated>
 */
final class HasOne extends HasMany
{
    /**
     * Constrain the related query to the parent's key, stably ordered.
     *
     * @return void
     */
    protected function addConstraints(): void
    {
        $primaryKeys = MetadataFactory::for($this->related)->primaryKeys;

        if (count($primaryKeys) === 1 && $primaryKeys[0]->name !== null) {
            $this->query->orderBy($primaryKeys[0]->name);
        }

        if ($this->isComposite()) {
            // The tuple lands inside a whereNested GROUP — the relation
            // constraint is ONE unit: the parts AND within the parens, and
            // a caller's later `->orWhere(...)` ORs at the constraint's
            // edges instead of against the tuple's PARTS.
            $this->query->whereNested(fn (WhereBuilder $nested) => self::applyKeyTuple(
                $nested,
                $this->getForeignKeys(),
                $this->getLocalKeys(),
                $this->parentKeyValues($this->getLocalKeys()),
            ));

            return;
        }

        $parentKey = $this->parent->attribute($this->getLocalKey());

        if ($parentKey === null) {
            // Null parent key → no results, without compiling a meaningless
            // query (BelongsTo's convention; `fk = NULL` matches no rows).
            $this->query->whereRaw('1 = 0', []);
            return;
        }

        $this->query->where($this->getForeignKey(), '=', $parentKey);
    }

    /**
     * Run the constrained query and keep only the first match.
     *
     * @return Collection<TRelated> A one-element (or empty) collection.
     */
    #[\Override]
    protected function executeResults(): Collection
    {
        $first = $this->query->first();

        return Collection::make($first === null ? [] : [$first]);
    }

    /**
     * Order the eager-load query stably, mirroring the lazy path.
     *
     * The lazy path orders by the related PK before taking the first row
     * ({@see addConstraints()}); the eager path must apply the same order
     * or the two paths can return different rows for the same parent when
     * duplicate FK rows exist. Without the order, `match()` keeps whatever
     * row the database happened to return first — non-deterministic across
     * backends, plans, and page sizes.
     *
     * @param ModelQueryBuilder<TRelated> $query The chunk's eager query.
     * @return void
     */
    protected function applyEagerOrdering(ModelQueryBuilder $query): void
    {
        $primaryKeys = MetadataFactory::for($this->related)->primaryKeys;

        if (count($primaryKeys) === 1 && $primaryKeys[0]->name !== null) {
            $query->orderBy($primaryKeys[0]->name);
        }
    }

    /**
     * Distribute eager results onto parents — first match per FK value.
     *
     * @param list<Model> $parents The parents to populate.
     * @param Collection<TRelated> $results The related models.
     * @param string $name The relation name (the cache key).
     * @param list<int|string|null|list<int|string|null>>|null $eagerParentKeys
     *        Unused here — the FK lives on each related model (accepted for
     *        signature parity with the through relations).
     * @return void
     */
    public function match(array $parents, Collection $results, string $name, ?array $eagerParentKeys = null): void
    {
        $first = [];

        foreach ($results as $related) {
            $key = $this->isComposite()
                ? self::serializeKey(self::tupleValues($related, $this->getForeignKeys()))
                : $related->attribute($this->getForeignKey());

            if (!isset($first[self::serializeKey($key)])) {
                $first[self::serializeKey($key)] = $related;
            }
        }

        foreach ($parents as $parent) {
            $key = $this->isComposite()
                ? self::tupleValues($parent, $this->getLocalKeys())
                : $parent->attribute($this->getLocalKey());
            $related = $first[self::serializeKey($key)] ?? null;
            $parent->setRelation($name, $related);
        }
    }
}
