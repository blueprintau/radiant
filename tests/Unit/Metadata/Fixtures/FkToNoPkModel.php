<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Attributes\ForeignKey;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;

/**
 * A metadata error fixture: a #[ForeignKey] referencing a model that
 * declares no primary key.
 */
#[ForeignKey(columns: ['targetCode'], references: NoPkTargetModel::class)]
class FkToNoPkModel extends Model
{
    /**
     * The FK column.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 32)]
    public string $targetCode;
}
