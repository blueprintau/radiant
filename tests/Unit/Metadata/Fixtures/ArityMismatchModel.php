<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Attributes\ForeignKey;
use BlueprintAU\Radiant\Model;

/**
 * A metadata error fixture: a #[ForeignKey] whose columns and references
 * have mismatched arity.
 */
#[ForeignKey(columns: ['regionId'], references: 'geo_regions', referencesColumns: ['id', 'country'])]
class ArityMismatchModel extends Model
{
    /**
     * The FK column.
      *
     * @var int
     */
    #[Column(type: ColumnType::BigInt)]
    public int $regionId;
}
