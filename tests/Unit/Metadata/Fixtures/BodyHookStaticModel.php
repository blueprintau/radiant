<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Attributes\Hook;
use BlueprintAU\Radiant\Attributes\WriteHook;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;

/**
 * Metadata error fixture: a model whose CLASS BODY declares a static
 * #[WriteHook] — instance-method contract must cover body declarations.
 */
class BodyHookStaticModel extends Model
{
    /**
     * A column so the model resolves as a table owner.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 32)]
    public string $status;

    /**
     * A static body hook — triggers the build error.
     *
     * @return void
     */
    #[WriteHook(Hook::Insert)]
    public static function onInsertStatic(): void
    {
    }
}
