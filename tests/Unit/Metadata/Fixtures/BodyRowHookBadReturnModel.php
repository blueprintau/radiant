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
 * #[RowHook] with a string return — the void-or-bool contract must
 * cover body declarations.
 */
class BodyRowHookBadReturnModel extends Model
{
    /**
     * A column so the model resolves as a table owner.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 32)]
    public string $status;

    /**
     * A body hook with a string return — triggers the build error.
     *
     * @param  array<string, mixed>  $rows  Unused.
     * @return string
     */
    #[RowHook(Hook::Insert)]
    protected static function onInsertStringReturn(array &$rows): string
    {
        unset($rows);

        return 'nope';
    }
}
