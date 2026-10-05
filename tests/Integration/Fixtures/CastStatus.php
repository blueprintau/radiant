<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Integration\Fixtures;

/**
 * Fixture: a string-backed enum owned by the integration cast fixture —
 * one enum per fixture family.
 */
enum CastStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}
