<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures;

use BlueprintAU\Radiant\SoftDeletes;

/**
 * The outer trait — uses {@see SoftDeletes} so the synthetic deleted_at
 * column must be injected through the nesting.
 */
trait OuterSoftDeleteTrait
{
    use SoftDeletes;
}
