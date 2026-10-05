<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Integration\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Attributes\Table;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;

/**
 * Fixture: the encode/decode cast matrix for the live-server suites —
 * one property per (ColumnType × property-type) combo that behaves
 * differently per driver. Built into real DDL via fromMetadata(), so
 * every suite exercises its own dialect's storage.
 */
#[Table(name: 'cast_probes')]
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
     * The int Unix-timestamp cast — MySQL and Postgres deliver the
     * native temporal column as a datetime string; SQLite delivers the
     * bound string back as a string.
     *
     * @var int
     */
    #[Column(type: ColumnType::Timestamp, name: 'int_ts')]
    public int $intTs;

    /**
     * The Carbon Unix-timestamp cast.
     *
     * @var \Carbon\Carbon
     */
    #[Column(type: ColumnType::Timestamp, name: 'carbon_ts')]
    public \Carbon\Carbon $carbonTs;

    /**
     * The Carbon datetime cast — the canonical temporal round-trip.
     *
     * @var \Carbon\Carbon
     */
    #[Column(type: ColumnType::DateTime, name: 'carbon_dt')]
    public \Carbon\Carbon $carbonDt;

    /**
     * The Carbon date cast — decode lands on start of day.
     *
     * @var \Carbon\Carbon
     */
    #[Column(type: ColumnType::Date, name: 'carbon_date')]
    public \Carbon\Carbon $carbonDate;

    /**
     * The string-prop date cast — the property keeps the Y-m-d form.
     *
     * @var string
     */
    #[Column(type: ColumnType::Date, name: 'string_date')]
    public string $stringDate;

    /**
     * The bool cast — MySQL binds int 1/0, Postgres 't'/'f', SQLite
     * whatever was bound.
     *
     * @var bool
     */
    #[Column(type: ColumnType::Boolean, name: 'flag')]
    public bool $flag;

    /**
     * The float cast — MySQL returns decimals as strings.
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
    #[Column(type: ColumnType::Json, name: 'meta')]
    public array $meta;

    /**
     * The string-backed enum cast.
     *
     * @var CastStatus
     */
    #[Column(type: ColumnType::Enum, values: CastStatus::class, name: 'status')]
    public CastStatus $status;

    /**
     * The uuid cast.
     *
     * @var string
     */
    #[Column(type: ColumnType::Uuid, name: 'token')]
    public string $token;

    /**
     * A nullable variant of the int timestamp cast.
     *
     * @var int|null
     */
    #[Column(type: ColumnType::Timestamp, name: 'nullable_int_ts', nullable: true)]
    public int|null $nullableIntTs;
}
