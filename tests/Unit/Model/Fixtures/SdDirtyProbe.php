<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Model\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Attributes\Table;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\SoftDeletes;
use BlueprintAU\Radiant\Tests\Support\ProbesDirty;

/**
 * Fixture: probe exposing the protected dirty read — the established
 * pattern for asserting snapshot-space internals (see the DirtyProbe /
 * DefaultedModelProbe fixtures in the Metadata tests).
 */
#[Table(name: 'sd_posts')]
class SdDirtyProbe extends Model
{
    use ProbesDirty;
    use SoftDeletes;

    /**
     * The post's id.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * The post title.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 255)]
    public string $title;
}
