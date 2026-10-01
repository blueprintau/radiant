<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures;

use BlueprintAU\Radiant\Attributes\ForeignKey;
use BlueprintAU\Radiant\Model;

/**
 * A metadata error fixture: a #[ForeignKey] with an empty column list.
 */
#[ForeignKey(columns: [], references: 'geo_regions', referencesColumns: ['id'])]
class EmptyFkColumnsModel extends Model
{
}
