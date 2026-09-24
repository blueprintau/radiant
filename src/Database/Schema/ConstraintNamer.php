<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Schema;

/**
 * The one constraint-name derivation — the shared naming convention.
 */
final class ConstraintNamer
{
    /**
     * Derive a constraint name: `{table}_{columns}_{suffix}`.
     *
     * @param  string  $table
     * @param  list<string>  $columns
     * @param  string  $suffix
     * @return string
     * @throws \InvalidArgumentException
     */
    public static function derive(string $table, array $columns, string $suffix): string
    {
        if ($table === '' || $suffix === '' || $columns === [] || in_array('', $columns, true)) {
            throw new \InvalidArgumentException(
                'A derived constraint name requires a non-empty table, suffix, and columns; got '
                . "table [{$table}], suffix [{$suffix}], columns [" . implode(', ', $columns) . '].'
            );
        }

        return implode('_', [$table, ...$columns, $suffix]);
    }
}
