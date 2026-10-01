<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures;

/**
 * The outer trait — uses {@see InnerScopeTrait}.
 */
trait OuterScopeTrait
{
    use InnerScopeTrait;
}
