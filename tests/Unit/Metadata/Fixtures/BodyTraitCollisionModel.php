<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Attributes\Hook;
use BlueprintAU\Radiant\Attributes\RowHook;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\Tests\Unit\Model\Fixtures\VetoingTraitHook;

/**
 * Metadata error fixture: a model whose trait AND class body both declare
 * a bulk insert hook — one path, one declaration per level.
 */
class BodyTraitCollisionModel extends Model
{
    use VetoingTraitHook;

    /**
     * A column so the model resolves as a table owner.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 32)]
    public string $status;

    /**
     * The body's bulk insert hook — collides with the trait's.
     *
     * @param  array<string, mixed>  $rows  Unused.
     * @return void
     */
    #[RowHook(Hook::Insert)]
    protected static function recordBodyInsertRows(array &$rows): void
    {
        unset($rows);
    }
}
