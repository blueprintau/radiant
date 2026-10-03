<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures;

use BlueprintAU\Radiant\Attributes\Backfill;
use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;

/**
 * A metadata error fixture: #[Backfill(null)] — null is not a backfill
 * value, so the declaration fails fast at build.
 */
class NullBackfillModel extends Model
{
    /**
     * A backfill whose value is null — triggers the build error.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 64)]
    #[Backfill(null)]
    public string $displayName;
}
