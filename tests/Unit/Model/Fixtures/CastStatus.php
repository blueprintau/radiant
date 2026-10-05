<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Model\Fixtures;

/**
 * Fixture: a string-backed enum owned by the cast fixtures — one enum
 * per fixture family, so CodecStatus stays the codec suite's subject.
 */
enum CastStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}
