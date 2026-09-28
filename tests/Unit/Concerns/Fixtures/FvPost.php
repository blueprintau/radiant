<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Concerns\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\Relations\BelongsTo;

/**
 * Fixture: filter-vocabulary child.
 */
class FvPost extends Model
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

    /**
     * The view count.
     *
     * @var int
     */
    #[Column(type: ColumnType::Int)]
    public int $views;

    /**
     * The post's author.
     *
     * @return BelongsTo<FvUser>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(FvUser::class, 'user_id');
    }
}
