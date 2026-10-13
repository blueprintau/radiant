<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Attributes;

/**
 * The parent-delete actions a `#[MorphDelete]` declaration can take.
 *
 * The morph-pair analogue of the schema layer's `ForeignKeyAction`: a
 * polymorphic pair cannot carry a real foreign key, so these actions are
 * enforced by the ORM at parent-delete time instead of by the engine.
 */
enum MorphDeleteAction: string
{
    /** Null the children's morph pair — the rows survive, unreferenced. */
    case Detach = 'detach';

    /** Hard-delete the children. */
    case Delete = 'delete';

    /** Refuse the parent delete while any child still references it. */
    case Restrict = 'restrict';
}
