<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Relations;

use BlueprintAU\Radiant\Collection;
use BlueprintAU\Radiant\Model;

/**
 * One-to-many polymorphic: the parent's key + morph alias are referenced
 * by the related table's FK + type columns.
 *
 * `Post::comments()` → `Comment::newQuery()->where(commentable_id,
 * $post->id)->where(commentable_type, Post::class)`. The type filter is
 * what makes the relation polymorphic — a Video with the same id shares
 * the comment pool without ever seeing Post's comments.
 *
 * @template TRelated of Model
 * @extends MorphOneOrMany<TRelated>
 */
final class MorphMany extends MorphOneOrMany
{
    /**
     * Run the constrained query.
     *
     * @return Collection<TRelated>
     */
    #[\Override]
    protected function executeResults(): Collection
    {
        return $this->query->get();
    }

    /**
     * Distribute eager results onto parents, keyed by the FK value.
     *
     * Parents with no matching children get an empty collection — the
     * relation is loaded either way.
     *
     * @param  list<Model>  $parents
     * @param  Collection<TRelated>  $results
     * @param  string  $name
     * @param  list<int|string|null|list<int|string|null>>|null  $eagerParentKeys  Unused.
     * @return void
     */
    #[\Override]
    public function match(array $parents, Collection $results, string $name, ?array $eagerParentKeys = null): void
    {
        $grouped = [];

        foreach ($results as $related) {
            $grouped[self::serializeKey($related->attribute($this->getForeignKey()))][] = $related;
        }

        foreach ($parents as $parent) {
            $key = self::serializeKey($parent->attribute($this->getLocalKey()));
            // The bag's items came off $results (TRelated) — every one IS
            // a Model; setRelation accepts Collection<Model> and the item
            // template is not covariant.
            /** @var Collection<Model> $bag */
            $bag = Collection::make($grouped[$key] ?? []);
            $parent->setRelation($name, $bag);
        }
    }
}
