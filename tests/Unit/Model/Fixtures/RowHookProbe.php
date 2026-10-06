<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Model\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\Timestamps;
use Carbon\Carbon;

/**
 * Fixture: bulk-hook probe model — Timestamps stamping plus a recording
 * insert hook, with a static clock counter shared by both paths.
 */
class RowHookProbe extends Model
{
    use Timestamps;

    use VetoInsertRowsTrait;

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
     * How many times the overridden clock was read (instance + static
     * calls both route here).
     *
     * @var int
     */
    public static int $clockReads = 0;

    /**
     * The frozen clock — advanced manually by tests.
     *
     * @var Carbon
     */
    public static Carbon $clock;

    /**
     * The countable clock override.
     *
     * @return Carbon
     */
    protected static function freshTimestamp(): Carbon
    {
        self::$clockReads++;

        return self::$clock ?? Carbon::parse('2026-06-01 12:00:00');
    }

    /**
     * Reset the static probes between tests.
     *
     * @return void
     */
    public static function resetProbes(): void
    {
        self::$clockReads = 0;
        self::$clock = Carbon::parse('2026-06-01 12:00:00');
        self::$seenInsertRows = [];
    }
}
