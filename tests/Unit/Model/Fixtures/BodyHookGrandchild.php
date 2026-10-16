<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Model\Fixtures;

/**
 * Fixture: a grandchild of {@see OrderParentPost} declaring NO hooks —
 * isolates the inherited parent hook for a two-level inheritance check.
 */
class BodyHookGrandchild extends BodyHookChild
{
}
