<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Model\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;

/**
 * Fixture: a model with a declared FK column but NO primary key — the
 * owner-key convention cannot derive one.
 */
class NoPkRelationTarget extends Model
{
    /**
     * The FK column back to the parent.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt)]
    public int $post_id;
}
