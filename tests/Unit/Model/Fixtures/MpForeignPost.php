<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Model\Fixtures;

use BlueprintAU\Radiant\ModelQueryBuilder;
use BlueprintAU\Radiant\MassPrunable;

/**
 * Fixture: a MassPrunable model whose prunable() returns ANOTHER model's
 * builder — the builder-identity guard's failure shape on the mass path.
 */
class MpForeignPost extends MpPost
{
    /**
     * Return MpPost's builder — the identity guard's failure shape.
     * PHPStan flags the return-type mismatch; the RUNTIME guard is the
     * subject, so the mismatch is deliberate.
     *
     * @return ModelQueryBuilder<static>
     */
    public static function prunable(): ModelQueryBuilder
    {
        /** @phpstan-ignore return.type (deliberate contract violation — the runtime guard is the test subject) */
        return MpPost::newQuery();
    }
}
