<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Model\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\Timestamps;

/**
 * Fixture: a plain Timestamps model without any RowHook traits — proves
 * the Timestamps bulk stampers work standalone.
 */
class PlainTimestamped extends Model
{
    use Timestamps;

    /**
     * The auto-increment primary key.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * The row's name.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 64)]
    public string $name;

    /**
     * The frozen clock — advanced manually by tests.
     *
     * @var \Carbon\Carbon
     */
    public static \Carbon\Carbon $clock;

    /**
     * The frozen-clock override.
     *
     * @return \Carbon\Carbon
     */
    protected static function freshTimestamp(): \Carbon\Carbon
    {
        return self::$clock ?? \Carbon\Carbon::parse('2026-06-01 12:00:00');
    }
}
