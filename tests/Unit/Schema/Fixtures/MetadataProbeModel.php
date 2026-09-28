<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Schema\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Attributes\Table;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;

/**
 * A model whose int property declares precision on a Timestamp column —
 * the metadata build must fail fast.
 */
#[Table('tp_bad')]
final class MetadataProbeModel extends Model
{
    /**
     * Precision on an int Unix-timestamp column is meaningless.
     *
     * @var int
     */
    #[Column(ColumnType::Timestamp, precision: 3)]
    public int $occurred_at;

    /**
     * Force a metadata build so the fail-fast fires.
     *
     * @return void
     */
    public static function buildForTest(): void
    {
        \BlueprintAU\Radiant\Metadata\MetadataFactory::for(self::class);
    }
}
