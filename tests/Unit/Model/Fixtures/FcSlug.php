<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Model\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;

/**
 * Fixture: a caller-assigned (non-auto-increment) string PK for the
 * create-family PK fail-fast probes.
 */
class FcSlug extends Model
{
    /**
     * The caller-assigned primary key (NOT auto-generated).
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 64, primaryKey: true)]
    public string $slug;

    /**
     * The label.
     *
     * @var string|null
     */
    #[Column(type: ColumnType::String, length: 128, nullable: true)]
    public ?string $label = null;
}
