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
     * @param  array<string,mixed>|list<array<string,mixed>>  $values
     * @return list<array<string,mixed>>
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

    /**
     * Assert every row in a bulk insert carries the SAME column set.
     *
     * A multi-row INSERT compiles ONE column list and ONE placeholder
     * group per row — rows of differing arity produce a malformed
     * statement or a placeholder/binding mismatch. Padding a missing
     * column with NULL would silently write NULL into a nullable column
     * the caller never named, so ragged rows fail fast instead.
     *
     * @param  list<array<string,mixed>>  $rows
     * @return void
     *
     * @throws \InvalidArgumentException
     */
    protected function assertUniformInsertRows(array $rows): void
    {
        if (count($rows) < 2) {
            return;
        }

        $expected = array_keys($rows[0]);

        foreach (array_slice($rows, 1) as $i => $row) {
            if (array_keys($row) !== $expected) {
                throw new \InvalidArgumentException(
                    'A bulk insert requires every row to carry the same columns; row '
                        . ($i + 1) . ' differs from row 0. Split the call or give every '
                        . 'row the same column set (an absent column would otherwise '
                        . 'silently write NULL).'
                );
            }
        }
    }
}
