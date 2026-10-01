<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures;

use BlueprintAU\Radiant\Attributes\Check;
use BlueprintAU\Radiant\Model;

/**
 * A metadata error fixture: a #[Check] with a whitespace-only expression.
 */
#[Check(expression: '   ')]
class EmptyCheckModel extends Model
{
}
