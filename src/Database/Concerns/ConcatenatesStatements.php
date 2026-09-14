<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Concerns;

/**
 * Statement-assembly helpers shared by the query and schema grammars.
 *
 * The same rule both grammars follow: a statement is OPTIONAL segments
 * (clauses that may not apply), joined by {@see ConcatenatesStatements::concatenate()};
 * a LIST is non-optional items (columns, orders), joined by plain `implode`.
 * Silently filtering list items hides a bug; silently filtering absent
 * clauses is the job.
 */
trait ConcatenatesStatements
{
    /**
     * Join statement segments with a single space, dropping empty ones.
     *
     * This is the STATEMENT ASSEMBLY join: each argument is an optional part
     * of one statement (a clause that may not apply, e.g. an empty WHERE),
     * and an empty segment means "not present", not "zero items".
     *
     * Do NOT use this to join list items (columns, orders, bindings) — those
     * are non-optional and joined with plain `implode(', ' ...)` or
     * `implode(' ' ...)`; silently filtering a genuinely empty list item
     * there would hide a bug instead of surfacing it.
     *
     * @param list<string> $segments The SQL segments.
     * @return string The joined SQL.
     */
    protected function concatenate(array $segments): string
    {
        return implode(' ', array_filter($segments, fn ($segment) => $segment !== ''));
    }
}
