<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Model\Fixtures;

/**
 * Fixture: a child of {@see BodyHookParent} declaring no hooks of its
 * own — the parent's class-body hook must inherit.
 */
class BodyHookChild extends BodyHookParent
{
}
