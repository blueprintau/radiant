<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Attributes;

/**
 * Marks a trait method as a write-path hook.
 *
 * The method name is arbitrary — two traits on one class never collide.
 * The claim contract: a `null` return is an observer (dispatch
 * continues); a `bool` return claims the write (`true` = performed and
 * succeeded, `false` = owned and failed/refused) and ends dispatch.
 * `Hook::Destroy` observers must return void — the hard DELETE itself
 * is unclaimable.
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class WriteHook
{
    /**
     * Create a write-hook declaration.
     *
     * @param  Hook  $hook
     */
    final public function __construct(
        public Hook $hook,
    ) {
    }
}
