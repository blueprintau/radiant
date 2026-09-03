<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Query;

/**
 * A raw SQL fragment that is spliced into a compiled query verbatim.
 *
 * The rare dialect-escape hatch: when a value or identifier genuinely cannot
 * be expressed as a plain scalar or identifier (geometry, JSONB, arrays→JSON,
 * a dialect-specific function call), wrap it in an `Expression` to pass it
 * through untouched. It is the raw counterpart to {@see ToSqlValue}: a
 * `ToSqlValue` returns an unquoted scalar that the Grammar quotes safely,
 * while an `Expression` is already SQL and is never quoted or escaped.
 *
 * An `Expression` is **not** user input to be trusted blindly — it is raw
 * SQL by design, so callers are responsible for its safety. It never reaches
 * the bind guard or the value codec; the Grammar inlines it directly.
 */
final class Expression
{
    /**
     * Create a raw SQL expression.
     *
     * @param string $value The raw SQL to splice in verbatim.
     */
    public function __construct(public readonly string $value) {}
}
