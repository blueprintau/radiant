<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Attributes;

/**
 * Marks a static trait method as a model scope provider.
 *
 * The method takes no parameters and returns a list of ScopeCondition
 * DTOs; every condition is applied to every model query unless stripped
 * by an opt-out (withoutScopes() / withoutScope(TraitClass) /
 * withTrashed() for the soft-delete scope). The method name is arbitrary
 * — two scope-declaring traits on one class never collide.
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class ModelScope
{
}
