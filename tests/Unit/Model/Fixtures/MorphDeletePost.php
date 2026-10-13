<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Model\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Attributes\MorphDelete;
use BlueprintAU\Radiant\Attributes\MorphDeleteAction;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;

/**
 * A morph-delete PARENT — media children detach, comments restrict.
 */
#[MorphDelete(MorphPairMedia::class, 'attachable', MorphDeleteAction::Detach)]
#[MorphDelete(MorphPairRestrictChild::class, 'attachable', MorphDeleteAction::Restrict)]
#[MorphDelete(MorphPairDeleteChild::class, 'attachable', MorphDeleteAction::Delete)]
class MorphDeletePost extends Model
{
    /**
     * The primary key.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * A plain column.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 64)]
    public string $title;
}
