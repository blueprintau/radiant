<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Schema\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Attributes\ForeignKey;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;

/**
 * A composite-constraint model — exercises the class-level attribute path.
 */
#[ForeignKey(columns: ['roleId'], references: 'sync_users', referencesColumns: ['id'])]
class SyncPost extends Model
{
    /**
     * The primary key.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * The FK column.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt)]
    public int $roleId;

    /**
     * A plain column.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 64)]
    public string $title;
}
