<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Attributes\Fixtures;

/**
 * Fixture: an int-backed enum for the string-cell decode arms.
 */
enum CodecIntStatus: int
{
    case Low = 1;
    case High = 5;
}
