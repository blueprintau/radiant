<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Attributes;

/**
 * Declares a table-level CHECK constraint (class-level).
 *
 * CHECK is portable across all supported dialects (MySQL, Postgres,
 * SQLite). The expression is spliced verbatim — the raw escape hatch,
 * same trust model as an Expression column default. Column names inside
 * the expression are NOT validated (the expression may legitimately mix
 * columns with dialect functions); a typo surfaces as a DDL error at
 * apply time.
 *
 * An explicitly named constraint keeps the given name verbatim; unnamed
 * constraints render the derived `{table}_{columns}_check` name. MySQL
 * 8.0.16+ enforces CHECK constraints; older MySQL parses-and-ignores
 * them.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final class Check
{
    /**
     * Create a CHECK constraint declaration.
     *
     * @param  string  $expression
     * @param  string|null  $name
     */
    public function __construct(
        public string $expression,
        public ?string $name = null,
    ) {
    }
}
