<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Attributes;

use BlueprintAU\Radiant\Model;

/**
 * Declares a parent-delete action over a morph relation (class-level).
 *
 * A polymorphic pair cannot carry a real foreign key — the child's
 * `{name}_type` column holds class-strings, not table references — so the
 * schema layer's `onDelete:` actions are unusable there. This attribute
 * supplies the missing referential action: when a row of the DECLARING
 * (parent) model is hard-deleted, the action runs against the RELATED
 * model's matching children (same `{name}_id` as the parent's key AND
 * same `{name}_type` as the parent's class-string).
 *
 * The action fires on the HARD delete path only — a soft delete leaves
 * the parent row in place, so its children stay referenced (the SQL
 * `ON DELETE` analogy: referential actions fire when the row leaves the
 * table). Validated at metadata build: the related class must be a model
 * declaring both morph columns, and a `Detach` action requires the pair
 * nullable.
 *
 * Bulk builder deletes bypass the action — the same documented gap as the
 * instance-side hooks.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final class MorphDelete
{
    /**
     * Create a morph-delete declaration.
     *
     * @param  class-string<Model>  $related  The model whose children the action touches.
     * @param  string  $morphName  The `#[Morphs]` name on the related model (`{name}_type`/`{name}_id`).
     * @param  MorphDeleteAction  $action  The parent-delete action.
     */
    public function __construct(
        public string $related,
        public string $morphName,
        public MorphDeleteAction $action = MorphDeleteAction::Restrict,
    ) {
    }
}
