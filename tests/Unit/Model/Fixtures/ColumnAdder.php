<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Model\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;

/**
 * Fixture: a model whose bulk-insert hook adds a rank column that the
 * rows themselves never carry.
 */
class ColumnAdder extends Model
{
    use ColumnAddingHookTrait;

    /**
     * The auto-increment primary key.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * The row's name.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 64)]
    public string $name;

    /**
     * The hook-added rank.
     *
     * @var int
     */
    #[Column(type: ColumnType::Int, nullable: true)]
    public int|null $rank;
}
