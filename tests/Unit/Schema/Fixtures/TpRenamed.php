<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Schema\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Attributes\Table;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\Timestamps;
use Carbon\Carbon;

/**
 * A stamped model whose stamp columns carry non-default names.
 */
#[Table('tp_renamed')]
final class TpRenamed extends Model
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
     * The row name.
     *
     * @var string
     */
    #[Column(ColumnType::String, length: 64)]
    public string $name;

    /**
     * The renamed created-at stamp.
     *
     * @var \Carbon\Carbon|null
     */
    #[Column(ColumnType::DateTime, nullable: true, precision: 3)]
    public ?Carbon $began_at;

    /**
     * The renamed updated-at stamp.
     *
     * @var \Carbon\Carbon|null
     */
    #[Column(ColumnType::DateTime, nullable: true, precision: 3)]
    public ?Carbon $modified_at;

    /**
     * The renamed created-at column.
     *
     * @return string
     */
    public static function createdAtColumn(): string
    {
        return 'began_at';
    }

    /**
     * The renamed updated-at column.
     *
     * @return string
     */
    public static function updatedAtColumn(): string
    {
        return 'modified_at';
    }
}
