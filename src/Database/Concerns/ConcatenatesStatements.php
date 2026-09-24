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
     * Do NOT use this to join list items (columns, orders, bindings) — those
     * are non-optional and joined with plain `implode`.
     *
     * @param  list<string>  $segments
     * @return string
     */
    protected function concatenate(array $segments): string
    {
        return implode(' ', array_filter($segments, fn ($segment) => $segment !== ''));
    }
}
