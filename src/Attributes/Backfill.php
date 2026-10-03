<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Attributes;

/**
 * Declares the one-time value existing rows receive when the property's
 * column is added to a table that already has data.
 *
 * It must ride a property that also declares {@see Column}, and it is
 * consulted only when the column is being added — on a fresh create it
 * is a no-op.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Backfill
{
    /**
     * Create a backfill declaration.
     *
     * @param  mixed  $value  A scalar or an SQL Expression — the value existing rows are backfilled with.
     */
    final public function __construct(
        public mixed $value,
    ) {
    }
}
