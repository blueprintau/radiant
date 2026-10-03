<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Schema\Fixtures;

use BlueprintAU\Radiant\Attributes\Backfill;
use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Attributes\Table;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;

/**
 * A sync-able model whose added NOT NULL column carries a #[Backfill] —
 * the model-driven form of Blueprint::backfill().
 */
#[Table(name: 'sync_backfill_users')]
class BackfilledUser extends Model
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
     * A NOT NULL column added to a table that already has rows — the
     * existing rows are backfilled with 'unknown'.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 64, name: 'display_name')]
    #[Backfill('unknown')]
    public string $displayName;
}
