<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Concerns;

/**
 * Normalizes an insert payload into a list of rows.
 *
 * Shared by `SqlConnection`, `CsvConnection`, and `Grammar` — the three
 * classes that accept either a single insert row or a list of rows. A trait
 * (not a base class) because it must reach across two unrelated hierarchies:
 * the `Connections\` tree and the `Grammars\` tree.
 */
trait NormalizesInsertRows
{
    /**
     * Normalize a single row or a list of rows into a list of rows.
     *
     * A single associative row (`['name' => 'Alice']`) becomes a one-element
     * list; a list of rows (`[['name' => 'Alice'], ['name' => 'Bob']]`) is
     * returned unchanged.
     *
     * @param array<string,mixed>|list<array<string,mixed>> $values A single
     *        row or a list of rows.
     * @return list<array<string,mixed>> The rows, always as a list.
     */
    protected function normalizeInsertRows(array $values): array
    {
        // A list of rows: [[...], [...]] — each element is an associative row.
        if (array_is_list($values) && isset($values[0]) && is_array($values[0])) {
            /** @var list<array<string, mixed>> $values */
            return $values;
        }

        /** @var array<string, mixed> $values */
        return [$values];
    }
}
