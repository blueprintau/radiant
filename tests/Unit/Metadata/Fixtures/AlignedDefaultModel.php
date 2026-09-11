<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;

/**
 * A valid fixture: the PHP property default agrees exactly with the
 * declared column default — redundant, but not an error.
 */
class AlignedDefaultModel extends Model
{
    /**
     * The PHP default equals the column default — no divergence.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 64, default: 'anon')]
    public string $name = 'anon';

    /**
     * No PHP default — uninitialized after `new`, so the column default
     * applies on INSERT.
     *
     * @var int
     */
    #[Column(type: ColumnType::Int, default: 0)]
    public int $hits;
}
