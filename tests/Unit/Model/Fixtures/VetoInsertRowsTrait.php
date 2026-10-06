<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Model\Fixtures;

use BlueprintAU\Radiant\Attributes\Hook;
use BlueprintAU\Radiant\Attributes\RowHook;

/**
 * Fixture: a veto-capable bulk-insert hook recording its rows and
 * rejecting the write when a row matches the veto sentinel.
 */
trait VetoInsertRowsTrait
{
    /** @var list<array<string, mixed>> Rows seen by the hook, in dispatch order. */
    public static array $seenInsertRows = [];

    /**
     * Record the rows; veto when a row carries the sentinel name.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return bool False vetoes the write.
     */
    #[RowHook(Hook::Insert)]
    public static function observeInsertRows(array &$rows): bool
    {
        self::$seenInsertRows = $rows;

        foreach ($rows as $row) {
            if (($row['name'] ?? null) === 'veto') {
                return false;
            }
        }

        return true;
    }
}
