<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Model\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;

/**
 * Fixture: the encode/decode cast matrix — one property per
 * (ColumnType × property-type) combo with a real round-trip story.
 */
class CastProbe extends Model
{
    /**
     * The auto-increment primary key.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * A string prop over a Timestamp column — the property's string IS
     * the cell (the property type drives the cast).
     *
     * @var string
     */
    #[Column(type: ColumnType::Timestamp, name: 'string_ts')]
    public string $stringTs;

    /**
     * The int Unix-timestamp cast — SQLite stores and returns the cell
     * as an integer.
     *
     * @var int
     */
    #[Column(type: ColumnType::Timestamp, name: 'int_ts')]
    public int $intTs;

    /**
     * The Carbon Unix-timestamp cast — must reconstitute from epoch
     * seconds, not Carbon::parse the digits.
     *
     * @var \Carbon\Carbon
     */
    #[Column(type: ColumnType::Timestamp, name: 'carbon_ts')]
    public \Carbon\Carbon $carbonTs;

    /**
     * The DateTime Unix-timestamp cast — the interface branch handles
     * every DateTimeInterface implementation.
     *
     * @var \DateTime
     */
    #[Column(type: ColumnType::Timestamp, name: 'dt_ts')]
    public \DateTime $dtTs;

    /**
     * The string-prop date cast — the property keeps the Y-m-d form.
     *
     * @var string
     */
    #[Column(type: ColumnType::Date, name: 'string_date')]
    public string $stringDate;

    /**
     * The Carbon-prop date cast — decode lands on start of day.
     *
     * @var \Carbon\Carbon
     */
    #[Column(type: ColumnType::Date, name: 'carbon_date')]
    public \Carbon\Carbon $carbonDate;

    /**
     * A nullable variant of the int timestamp cast.
     *
     * @var int|null
     */
    #[Column(type: ColumnType::Timestamp, name: 'nullable_int_ts', nullable: true)]
    public int|null $nullableIntTs;

    /**
     * A nullable variant of the Carbon timestamp cast.
     *
     * @var \Carbon\Carbon|null
     */
    #[Column(type: ColumnType::Timestamp, name: 'nullable_carbon_ts', nullable: true)]
    public \Carbon\Carbon|null $nullableCarbonTs;

    /**
     * The bool cast — SQLite stores the cell as an integer.
     *
     * @var bool
     */
    #[Column(type: ColumnType::Boolean, name: 'flag')]
    public bool $flag;

    /**
     * The float cast — the cell arrives as a string on CSV/emulated
     * prepares.
     *
     * @var float
     */
    #[Column(type: ColumnType::Float, name: 'ratio')]
    public float $ratio;

    /**
     * The array-over-Json cast.
     *
     * @var array<string, mixed>
     */
    #[Column(type: ColumnType::Json, name: 'meta', nullable: true)]
    public array|null $meta;

    /**
     * The string-backed enum cast.
     *
     * @var CastStatus|null
     */
    #[Column(type: ColumnType::Enum, values: CastStatus::class, name: 'status', nullable: true)]
    public CastStatus|null $status;

    /**
     * The uuid cast.
     *
     * @var string
     */
    #[Column(type: ColumnType::Uuid, name: 'token', nullable: true)]
    public string|null $token;
}
