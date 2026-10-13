<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Model\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\ModelQueryBuilder;
use BlueprintAU\Radiant\Prunable;
use BlueprintAU\Radiant\SoftDeletes;

/**
 * Fixture: a Prunable model that ALSO soft-deletes — the forceDelete
 * path: trashed rows are prune candidates too, and pruning runs the
 * real DELETE (Hook::Destroy observers fire).
 */
class PrSoftPost extends Model
{
    use SoftDeletes;
    use Prunable;

    /**
     * The post's id.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * The post title.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 255)]
    public string $title;

    /**
     * The row's creation instant — the expiry column.
     *
     * @var \Carbon\Carbon
     */
    #[Column(type: ColumnType::DateTime, name: 'created_at')]
    public \Carbon\Carbon $createdAt;

    /**
     * The expiry instant the fixture's prunable() compares against.
     *
     * @var \Carbon\Carbon
     */
    public static \Carbon\Carbon $expiry;

    /**
     * The retention predicate: rows expired before the static cutoff.
     *
     * @return ModelQueryBuilder<static>
     */
    public static function prunable(): ModelQueryBuilder
    {
        return static::newQuery()->where('created_at', '<', self::$expiry);
    }
}
