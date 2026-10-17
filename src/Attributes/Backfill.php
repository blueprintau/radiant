<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Attributes;

/**
 * Declares the one-time value existing rows receive when the property's
 * column is added to a table that already has data — or filled before a
 * nullability tightening (nullable -> NOT NULL) modifies it.
 *
 * It must ride a property that also declares {@see Column}. On an add
 * the value fills existing rows; on a tightening modify it fills the
 * NULL rows ahead of the SET NOT NULL (the modify stays flagged
 * destructive). On a fresh create it is a no-op. A null value is a
 * mis-declaration and fails fast at metadata build.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Backfill
{
    /**
     * Create a backfill declaration.
     *
     * @param  mixed  $value  A scalar or an SQL Expression — the value existing rows are backfilled with; never null.
     */
    final public function __construct(
        public mixed $value,
    ) {
    }
}
