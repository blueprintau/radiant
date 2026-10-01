<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures;

use BlueprintAU\Radiant\Attributes\Morphs;
use BlueprintAU\Radiant\Model;

/**
 * A metadata error fixture: #[Morphs] declared with an empty name.
 */
#[Morphs(name: '')]
class EmptyMorphNameModel extends Model
{
}
