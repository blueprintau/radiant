<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Model\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Attributes\Hook;
use BlueprintAU\Radiant\Attributes\RowHook;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;

/**
 * Fixture: a PARENT model declaring a bulk insert hook in its own class
 * body — the hook must inherit into child models via the ancestor walk.
 */
class BodyHookParent extends Model
{
    /**
     * The auto-increment primary key.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * The row's name.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 64)]
    public string $name;

    /**
     * How many times the parent's body hook ran (shared across children).
     *
     * @var int
     */
    public static int $hookRuns = 0;

    /**
     * The parent's class-body bulk insert hook.
     *
     * @param  array<string, mixed>  $rows  Unused — the hook only counts the call.
     * @return void
     */
    #[RowHook(Hook::Insert)]
    protected static function countInsertRows(array &$rows): void
    {
        unset($rows);

        self::$hookRuns++;
    }
}
