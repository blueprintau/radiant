<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Model\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Attributes\Unique;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;

/**
 * Fixture: a UNIQUE-backed column for the firstOrCreate race/dupe probes.
 */
#[Unique(columns: ['email'])]
class FcUser extends Model
{
    /**
     * The auto-increment primary key.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * The unique email (the find-else-create identity).
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 128)]
    public string $email;

    /**
     * The user's name.
     *
     * @var string|null
     */
    #[Column(type: ColumnType::String, length: 64, nullable: true)]
    public ?string $name = null;
}
