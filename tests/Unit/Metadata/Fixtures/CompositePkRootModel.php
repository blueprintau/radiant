<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;

/**
 * A grandparent model with a COMPOSITE primary key — the MTI root for the
 * composite-root throw.
 */
class CompositePkRootModel extends Model
{
    /**
     * The first key part.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true)]
    public int $tenantId;

    /**
     * The second key part.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true)]
    public int $regionId;
}
