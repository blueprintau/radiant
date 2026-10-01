<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Model\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;

/**
 * The consuming model for {@see TwoConditionScopeTrait} — the scope's
 * second condition joins the first with an explicit boolean.
 */
class TwoStatePost extends Model
{
    use TwoConditionScopeTrait;

    /**
     * The auto-increment primary key.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * The post's state — the scope filters on it.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 32)]
    public string $status;
}
