<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Attributes\Table;
use BlueprintAU\Radiant\Model;

/**
 * A backed string enum for cast round-trip tests.
 */
enum StringStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}

/**
 * A backed int enum for cast round-trip tests.
 */
enum IntLevel: int
{
    case Low = 1;
    case High = 5;
}

/**
 * A unit enum for cast round-trip tests.
 */
enum UnitKind
{
    case Alpha;
    case Beta;
}

/**
 * A model exercising PHP enum property casting.
 */
#[Table(name: 'enum_posts')]
class EnumPost extends Model
{
    /**
     * The primary key.
     *
     * @var int|null
     */
    #[Column(type: \BlueprintAU\Radiant\Database\Schema\Enums\ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int|null $id;

    /**
     * The string-backed enum status.
     *
     * @var StringStatus
     */
    #[Column(type: \BlueprintAU\Radiant\Database\Schema\Enums\ColumnType::Enum, values: ['draft', 'published'])]
    public StringStatus $status;

    /**
     * The int-backed enum level.
     *
     * @var IntLevel
     */
    #[Column(type: \BlueprintAU\Radiant\Database\Schema\Enums\ColumnType::Int)]
    public IntLevel $level;

    /**
     * The unit enum kind.
     *
     * @var UnitKind
     */
    #[Column(type: \BlueprintAU\Radiant\Database\Schema\Enums\ColumnType::String, length: 10)]
    public UnitKind $kind;
}
