<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;

/**
 * A target model that declares columns but NO primary key — the
 * referenced-model-has-no-PK throw trigger.
 */
class NoPkTargetModel extends Model
{
    /**
     * A code column — deliberately not a primary key.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 32)]
    public string $code;
}
