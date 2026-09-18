<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures;


/**
 * A behavior-only subclass — inherits User's table (rule 1).
 */
class Admin extends User
{
    /**
     * No new columns — behavior only.
     *
     * @return bool Whether this admin has super powers.
     */
    public function isSuper(): bool
    {
        return true;
    }
}
