<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\PHPStan\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Attributes\Table;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;

/**
 * The related model of the type-flow fixture.
 */
#[Table(name: 'type_flow_posts')]
class TypeFlowPost extends Model
{
    /**
     * The auto-increment primary key.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * The author's id.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, name: 'user_id')]
    public int $userId;

    /**
     * The post title.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 64)]
    public string $title;
}
