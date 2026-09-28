<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Model\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;

/**
 * Fixture: entity with a caller-assigned string PK.
 */
class MstGuid extends Model
{
    /**
     * The caller-assigned UUID key (NOT auto-increment).
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 36, primaryKey: true)]
    public string $uuid;

    /**
     * The label.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 64)]
    public string $label;
}
