<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Attributes\Hook;
use BlueprintAU\Radiant\Attributes\RowHook;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;

/**
 * Metadata error fixture: a model whose CLASS BODY declares a
 * #[RowHook(Hook::Delete)] — bulk hooks support Insert and Update only,
 * regardless of where they are declared.
 */
class BodyRowHookDeleteHookModel extends Model
{
    /**
     * A column so the model resolves as a table owner.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 32)]
    public string $status;

    /**
     * A body hook on the delete path — triggers the build error.
     *
     * @param  array<string, mixed>  $rows  Unused.
     * @return void
     */
    #[RowHook(Hook::Delete)]
    protected static function onDeleteRows(array &$rows): void
    {
        unset($rows);
    }
}
