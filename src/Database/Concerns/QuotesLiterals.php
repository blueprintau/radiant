<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Concerns;

/**
 * Quotes scalar values as SQL literals.
 *
 * Shared by the query {@see \BlueprintAU\Radiant\Database\Grammars\Grammar}
 * and the schema {@see \BlueprintAU\Radiant\Database\Schema\SchemaGrammar} —
 * two unrelated hierarchies that both need to render a scalar as a SQL
 * literal (a default value in DDL, an inline value in a query). A trait
 * (not a base class) because it must reach across both trees.
 */
trait QuotesLiterals
{
    /**
     * Quote a scalar as a SQL literal.
     *
     * Strings are single-quoted with embedded quotes doubled; booleans render
     * as `1`/`0`; null as `null`; numbers pass through.
     *
     * @param string|int|float|bool|null $value The scalar to quote.
     * @return string The SQL literal.
     */
    protected function quoteLiteral(string|int|float|bool|null $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        return "'" . str_replace("'", "''", $value) . "'";
    }
}