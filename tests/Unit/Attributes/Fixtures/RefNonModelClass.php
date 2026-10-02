<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Attributes\Fixtures;

/**
 * Fixture: a NAMESPACED class that is NOT a Radiant model — the
 * defense-in-depth is_a guard in ForeignKey::resolvedReferencesColumns()
 * fires on it (resolve() would have thrown first, so the guard is probed
 * directly on the attribute).
 */
final class RefNonModelClass
{
}
