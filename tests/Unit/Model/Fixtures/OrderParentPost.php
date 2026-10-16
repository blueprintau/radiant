<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Model\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Attributes\Hook;
use BlueprintAU\Radiant\Attributes\RowHook;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;

/**
 * Fixture: a PARENT model whose class body declares a recording bulk
 * insert hook appending to a shared order log.
 */
class OrderParentPost extends Model
{
    /**
     * The append-order log shared across the ordering fixtures.
     *
     * @var list<string>
     */
    public static array $hookOrder = [];

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
     * The parent's class-body bulk insert hook.
     *
     * @param  array<string, mixed>  $rows  Unused — the hook only records the call.
     * @return void
     */
    #[RowHook(Hook::Insert)]
    protected static function recordParentInsertRows(array &$rows): void
    {
        unset($rows);

        self::$hookOrder[] = 'parent';
    }
}
