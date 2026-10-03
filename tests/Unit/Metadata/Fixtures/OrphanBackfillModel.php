<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures;

use BlueprintAU\Radiant\Attributes\Backfill;
use BlueprintAU\Radiant\Model;

/**
 * A metadata error fixture: #[Backfill] without a #[Column] — the
 * backfill could never be consumed.
 */
class OrphanBackfillModel extends Model
{
    /**
     * A backfill with no column to ride — triggers the build error.
     *
     * @var string
     */
    #[Backfill('unknown')]
    public string $displayName;
}
