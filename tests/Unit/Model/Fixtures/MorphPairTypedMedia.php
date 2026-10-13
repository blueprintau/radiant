<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Model\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Attributes\Morphs;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;

/**
 * A typed-property morph child — the pair halves are user-declared
 * nullable columns shadowing the `#[Morphs]` names, exercising the
 * typed-property side of the pair-integrity guard.
 */
#[Morphs(name: 'attachable', nullable: true)]
class MorphPairTypedMedia extends Model
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
    public string $path;

    /**
     * The morph alias column (user-declared, validated by the factory).
     *
     * @var string|null
     */
    #[Column(type: ColumnType::String, length: 255, nullable: true, name: 'attachable_type')]
    public ?string $attachableType;

    /**
     * The morph key column (user-declared, validated by the factory).
     *
     * @var int|null
     */
    #[Column(type: ColumnType::BigInt, nullable: true, name: 'attachable_id')]
    public ?int $attachableId;
}
