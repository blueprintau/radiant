<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Model\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Attributes\Hook;
use BlueprintAU\Radiant\Attributes\RowHook;
use BlueprintAU\Radiant\Attributes\WriteHook;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;

/**
 * Fixture: a model declaring both hook kinds in its own CLASS BODY (no
 * traits) — class-body hooks collect and dispatch like trait-declared
 * ones, attributed to the model class itself.
 */
class BodyHookPost extends Model
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
     * The rows the bulk insert hook observed (reset per test).
     *
     * @var list<array<string, mixed>>|array<string, mixed>
     */
    public static array $seenInsertRows = [];

    /**
     * Whether the instance update hook fired.
     *
     * @var bool
     */
    public static bool $updateHookFired = false;

    /**
     * Reset the recording state.
     *
     * @return void
     */
    public static function resetProbes(): void
    {
        self::$seenInsertRows = [];
        self::$updateHookFired = false;
    }

    /**
     * The class-body bulk insert hook — records the pre-encode rows.
     *
     * @param  array<string, mixed>  $rows  Ignored beyond recording — the hook receives the whole list by reference.
     * @return void
     */
    #[RowHook(Hook::Insert)]
    protected static function observeInsertRows(array &$rows): void
    {
        self::$seenInsertRows = $rows;
    }

    /**
     * The class-body instance update hook — flags that it fired.
     *
     * @return null
     */
    #[WriteHook(Hook::Update)]
    protected function stampOnUpdate(): null
    {
        self::$updateHookFired = true;

        return null;
    }
}
