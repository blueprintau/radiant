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
 * non-static #[RowHook] — the static contract must cover body
 * declarations.
 */
class BodyRowHookNonStaticModel extends Model
{
    /**
     * A column so the model resolves as a table owner.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 32)]
    public string $status;

    /**
     * A non-static body hook — triggers the build error.
     *
     * @param  array<string, mixed>  $rows  Unused.
     * @return void
     */
    #[RowHook(Hook::Insert)]
    protected function onInsertNotStatic(array &$rows): void
    {
        unset($rows);
    }
}
