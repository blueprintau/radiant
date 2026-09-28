<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Schema\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Attributes\Table;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;
use Carbon\Carbon;

/**
 * An unstamped model with a second-precision datetime column.
 */
#[Table('tp_plain')]
final class TpPlain extends Model
{
    /**
     * The auto-increment primary key.
     *
     * @var int
     */
    #[Column(ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * The row name.
     *
     * @var string
     */
    #[Column(ColumnType::String, length: 64)]
    public string $name;

    /**
     * When the row started — whole seconds.
     *
     * @var \Carbon\Carbon|null
     */
    #[Column(ColumnType::DateTime, nullable: true)]
    public ?Carbon $started_at;
}
