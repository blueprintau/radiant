<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Attributes\Fixtures;

use BlueprintAU\Radiant\Model;

/**
 * Fixture: a probe class with typed properties for the direct codec
 * guard calls.
 */
final class CodecProbe extends Model
{
    /**
     * A string property.
     *
     * @var string
     */
    public string $name = 'x';

    /**
     * An int property.
     *
     * @var int
     */
    public int $count = 0;

    /**
     * An untyped property — the untyped-throw trigger.
     *
     * @var mixed
     */
    public $untyped;
}
