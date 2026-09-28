<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Model\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;

/**
 * Fixture: builder-parity parent model with a datetime + JSON column.
 */
class BpUser extends Model
{
    /**
     * The auto-increment primary key.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * The user's name.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 64)]
    public string $name;

    /**
     * The user's signup timestamp — the decode-path probe column.
     *
     * @var \Carbon\Carbon
     */
    #[Column(type: ColumnType::DateTime, nullable: true, name: 'signed_up_at')]
    public \Carbon\Carbon|null $signedUpAt;

    /**
     * The user's preferences — the JSON encode/decode probe column.
     *
     * @var array<string, mixed>
     */
    #[Column(type: ColumnType::Json, nullable: true)]
    public array|null $meta;
}
