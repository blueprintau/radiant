<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Database\Schema\Enums\ForeignKeyAction;
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\Attributes\Table;

/**
 * The canonical user model — full column vocabulary, explicit table.
 */
#[Table(name: 'users')]
class User extends Model
{
    /**
     * The auto-increment primary key.
      *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * A unique string column.
      *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 255, unique: true)]
    public string $email;

    /**
     * A plain string column.
     * @var string
     */
    #[Column(type: ColumnType::String, length: 255)]
    public string $password;

    /**
     * A nullable datetime column, cast to Carbon.
      *
     * @var \Carbon\Carbon|null
     */
    #[Column(type: ColumnType::DateTime, nullable: true)]
    public ?\Carbon\Carbon $emailVerifiedAt;

    /**
     * An indexed FK column.
      *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, foreign: 'roles.id', index: true, onDelete: ForeignKeyAction::Cascade)]
    public int $roleId;

    /**
     * A JSON column, cast to array.
     *
     * @var array<string, mixed>|null
     */
    #[Column(type: ColumnType::Json, nullable: true)]
    public ?array $meta;
}
