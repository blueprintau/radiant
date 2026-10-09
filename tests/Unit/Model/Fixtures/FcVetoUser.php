<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Model\Fixtures;

use BlueprintAU\Radiant\Model;

/**
 * Fixture: a model whose saving() lifecycle listener always vetoes — the
 * vetoed-create probe.
 */
class FcVetoUser extends FcUser
{
    /**
     * Register the always-veto saving listener.
     *
     * @return void
     */
    public function registerVeto(): void
    {
        $this->saving(fn (): bool => false);
    }
}
