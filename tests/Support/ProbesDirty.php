<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Support;

/**
 * Shared probe surface for fixtures that expose the protected dirty-
 * tracking read of {@see \BlueprintAU\Radiant\Model} — the established
 * pattern for asserting snapshot-space internals without widening the
 * production API.
 *
 * Use it on a behavior-only subclass of the model under test (rule 1:
 * same table, same columns, no new declarations) so the probe's metadata
 * resolves to the parent's table and it hydrates identically.
 *
 * @phpstan-require-extends \BlueprintAU\Radiant\Model
 */
trait ProbesDirty
{
    /**
     * The dirty columns (encoded space), exposed for tests.
     *
     * @return array<string, mixed> column => encoded value
     */
    public function dirtyColumns(): array
    {
        return $this->getDirty();
    }
}
