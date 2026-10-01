<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures;

use BlueprintAU\Radiant\Attributes\Morphs;
use BlueprintAU\Radiant\Model;

/**
 * A metadata error fixture: #[Morphs] declared twice with the same name.
 */
#[Morphs(name: 'tag')]
#[Morphs(name: 'tag')]
class DuplicateMorphsModel extends Model
{
}
