<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Schema\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\SoftDeletes;
use BlueprintAU\Radiant\Timestamps;
use Carbon\Carbon;

/**
 * A model using BOTH SoftDeletes and Timestamps — the composition must
 * work without a trait-method collision.
 */
final class TpPost extends Model
{
    use SoftDeletes;
    use Timestamps;

    /**
     * The auto-increment primary key.
     *
     * @var int
     */
    #[Column(ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * The post name.
     *
     * @var string
     */
    #[Column(ColumnType::String, length: 64)]
    public string $name;

    /**
     * The insert stamp.
     *
     * @var \Carbon\Carbon|null
     */
    #[Column(ColumnType::DateTime, nullable: true, precision: 3)]
    public ?Carbon $created_at;

    /**
     * The update stamp.
     *
     * @var \Carbon\Carbon|null
     */
    #[Column(ColumnType::DateTime, nullable: true, precision: 3)]
    public ?Carbon $updated_at;

    /**
     * The soft-delete stamp.
     *
     * @var \Carbon\Carbon|null
     */
    #[Column(ColumnType::DateTime, nullable: true, precision: 3)]
    public ?Carbon $deleted_at;
}
