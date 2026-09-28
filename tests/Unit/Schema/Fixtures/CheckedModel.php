<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Schema\Fixtures;

use BlueprintAU\Radiant\Attributes\Check;
use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Attributes\Table;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;

/**
 * A model with a class-level #[Check] — the portable predicate flows
 * through fromMetadata() into the checks shape and compiles on every
 * dialect.
 */
#[Check(expression: 'price >= 0', name: 'price_positive')]
#[Table(name: 'sync_meta_check')]
class CheckedModel extends Model
{
    /**
     * The primary key.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * The checked column.
     *
     * @var float
     */
    #[Column(type: ColumnType::Float)]
    public float $price;
}
