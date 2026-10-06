<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Model\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Attributes\Table;
use BlueprintAU\Radiant\Model;

/**
 * A backed string enum for the where-binding tests — one enum per
 * fixture family, so CastStatus stays the cast suite's subject.
 */
enum WhereSource: string
{
    case Login = 'login';
    case Reset = 'reset';
}

/**
 * A backed int enum for the where-binding tests.
 */
enum WhereLevel: int
{
    case Low = 1;
    case High = 5;
}

/**
 * A unit enum for the where-binding tests — encoded by case name.
 */
enum WhereKind
{
    case Alpha;
    case Beta;
}

/**
 * A second string-backed enum — a case of THIS enum against a column
 * declared for {@see WhereSource} fails fast, the wrong-enum guard.
 */
enum OtherSource: string
{
    case Login = 'login';
}

/**
 * Fixture: a model exercising enum cases in where values — one column
 * per enum backing (string, int, unit) mirroring EnumPost's cast
 * declarations.
 */
#[Table(name: 'where_probes')]
class EnumWhereProbe extends Model
{
    /**
     * The auto-increment primary key.
     *
     * @var int|null
     */
    #[Column(type: \BlueprintAU\Radiant\Database\Schema\Enums\ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int|null $id;

    /**
     * The string-backed enum — an Enum column.
     *
     * @var WhereSource
     */
    #[Column(type: \BlueprintAU\Radiant\Database\Schema\Enums\ColumnType::Enum, values: WhereSource::class)]
    public WhereSource $source;

    /**
     * The int-backed enum — an integer column (the enumCompatibility
     * matrix for int-backed enums).
     *
     * @var WhereLevel
     */
    #[Column(type: \BlueprintAU\Radiant\Database\Schema\Enums\ColumnType::Int)]
    public WhereLevel $level;

    /**
     * The unit enum — a string-family column (a case encodes to its
     * name).
     *
     * @var WhereKind
     */
    #[Column(type: \BlueprintAU\Radiant\Database\Schema\Enums\ColumnType::String, length: 10)]
    public WhereKind $kind;

    /**
     * A millisecond-precision datetime — the where-path precision arm:
     * a second-precision value binds the stored form exactly.
     *
     * @var \Carbon\Carbon|null
     */
    #[Column(type: \BlueprintAU\Radiant\Database\Schema\Enums\ColumnType::DateTime, precision: 3, name: 'logged_at', nullable: true)]
    public \Carbon\Carbon|null $loggedAt;

    /**
     * A date column — a DateTime where value binds the `Y-m-d` cell
     * form, not a full datetime string.
     *
     * @var \Carbon\Carbon|null
     */
    #[Column(type: \BlueprintAU\Radiant\Database\Schema\Enums\ColumnType::Date, name: 'start_day', nullable: true)]
    public \Carbon\Carbon|null $startDay;

    /**
     * A Json column — an array where value binds its encoded form.
     *
     * @var array<string, mixed>|null
     */
    #[Column(type: \BlueprintAU\Radiant\Database\Schema\Enums\ColumnType::Json, nullable: true)]
    public array|null $meta;
}
