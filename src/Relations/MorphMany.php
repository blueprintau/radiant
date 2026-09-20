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
     * @return Collection<TRelated> Every related model matching the
     *         parent's key AND morph alias.
     */
    #[\Override]
    protected function executeResults(): Collection
    {
        return $this->query->get();
    }

    /**
     * Distribute eager results onto parents, keyed by the FK value.
     *
     * The type filter already ran in the eager query, so every result
     * belongs to THIS parent class; grouping by FK value alone is correct.
     * Parents with no matching children get an empty collection — the
     * relation is loaded either way.
     *
     * @param list<Model> $parents The parents to populate.
     * @param Collection<TRelated> $results The related models.
     * @param string $name The relation name (the cache key).
     * @param list<int|string|null|list<int|string|null>>|null $eagerParentKeys
     *        Unused here — the FK lives on each related model (accepted for
     *        signature parity with the through relations).
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
            $parent->setRelation($name, Collection::make($grouped[$key] ?? []));
        }
    }
}
