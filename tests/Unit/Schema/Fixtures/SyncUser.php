<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Schema\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;

/**
 * A sync-able model — exercises {@see \BlueprintAU\Radiant\Database\Schema\Blueprint::fromMetadata()}.
 */
class SyncUser extends Model
{
    /**
     * The auto-increment primary key.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * A unique email column.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 255, unique: true)]
    public string $email;

    /**
     * An indexed FK column.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, index: true)]
    public int $roleId;
}
