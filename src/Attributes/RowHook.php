<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Attributes;

/**
 * Marks a static trait method as a bulk-write hook.
 *
 * The method receives the row set (or the update values map) by
 * reference, in the PHP value space before column encoding. The method
 * must be static and declare a `void` or `bool` return type — `void` is
 * an observer; a `bool` return of `false` vetoes the whole write.
 * Declared in traits, mirroring {@see WriteHook}.
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class RowHook
{
    /**
     * Create a row-hook declaration.
     *
     * @param  Hook  $hook
     */
    final public function __construct(
        public Hook $hook,
    ) {
    }
}
