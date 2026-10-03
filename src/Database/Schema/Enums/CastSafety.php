<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Schema\Enums;

/**
 * How safely a live column's values convert to a desired type.
 */
enum CastSafety
{
    /** Every value converts losslessly (or near enough) — no gate. */
    case Safe;

    /** The cast is legal but may lose data or fail on some values — destructive. */
    case Risky;

    /** No value of the live type can become the desired type — fail fast. */
    case Uncastable;
}
