<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Attributes\Fixtures;

/**
 * Fixture: a string-backed enum for the mapping and decode arms.
 */
enum CodecStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}
