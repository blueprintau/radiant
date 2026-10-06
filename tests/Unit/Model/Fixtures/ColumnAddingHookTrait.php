<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Model\Fixtures;

use BlueprintAU\Radiant\Attributes\Hook;
use BlueprintAU\Radiant\Attributes\RowHook;

/**
 * Fixture: a bulk-insert hook that ADDS a column key to every row —
 * hooks run in the pre-encode space and may introduce new columns.
 */
trait ColumnAddingHookTrait
{
    /**
     * Add the rank column with a per-row computed value.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    #[RowHook(Hook::Insert)]
    public static function stampInsertRows(array &$rows): void
    {
        foreach ($rows as $i => &$row) {
            $row['rank'] = ($i + 1) * 10;
        }
    }
}
