<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Query;

/**
 * A raw SQL fragment that is spliced into a compiled query verbatim.
 *
 * Callers are responsible for its safety — it never reaches the bind guard
 * or the value codec; the Grammar inlines it directly.
 */
final class Expression
{
    /**
     * Create a raw SQL expression.
     *
     * @param  string  $value
     */
    public function __construct(public readonly string $value) {}
}
