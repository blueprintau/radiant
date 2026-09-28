<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Schema\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\Timestamps;
use Carbon\Carbon;

/**
 * A stamped model with millisecond-precision datetime columns.
 */
final class TpEvent extends Model
{
    use Timestamps;

    /**
     * The auto-increment primary key.
     *
     * @var int
     */
    #[Column(ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * The event name.
     *
     * @var string
     */
    #[Column(ColumnType::String, length: 64)]
    public string $name;

    /**
     * When the event started — millisecond precision.
     *
     * @var \Carbon\Carbon|null
     */
    #[Column(ColumnType::DateTime, nullable: true, precision: 3)]
    public ?Carbon $started_at;

    /**
     * The insert stamp — millisecond precision.
     *
     * @var \Carbon\Carbon|null
     */
    #[Column(ColumnType::DateTime, nullable: true, precision: 3)]
    public ?Carbon $created_at;

    /**
     * The update stamp — millisecond precision.
     *
     * @var \Carbon\Carbon|null
     */
    #[Column(ColumnType::DateTime, nullable: true, precision: 3)]
    public ?Carbon $updated_at;
}
