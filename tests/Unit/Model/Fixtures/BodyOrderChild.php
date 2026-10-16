<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Model\Fixtures;

use BlueprintAU\Radiant\Attributes\Hook;
use BlueprintAU\Radiant\Attributes\RowHook;

/**
 * Fixture: a CHILD of {@see OrderParentPost} whose own class body also
 * declares a bulk insert hook — body-vs-body across levels is
 * precedence, and the child's (leaf) hook runs first.
 */
class BodyOrderChild extends OrderParentPost
{
    /**
     * The child's class-body bulk insert hook — appends first.
     *
     * @param  array<string, mixed>  $rows  Unused — the hook only records the call.
     * @return void
     */
    #[RowHook(Hook::Insert)]
    protected static function recordChildInsertRows(array &$rows): void
    {
        unset($rows);

        self::$hookOrder[] = 'child';
    }
}
