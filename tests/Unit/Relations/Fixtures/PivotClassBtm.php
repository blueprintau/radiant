<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Relations\Fixtures;

use BlueprintAU\Radiant\Attributes\Table;
use BlueprintAU\Radiant\Model;

/**
 * A pivot-declaring model — no columns, so it owns no table — whose
 * explicit #[Table] names the pivot other models point at.
 */
#[Table(name: 'btm_join')]
class PivotClassBtm extends Model
{
}
