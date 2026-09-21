<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Schema;

/**
 * The ONE constraint-name derivation — the shared naming convention.
 *
 * Every derived constraint name in the package has the same shape:
 * `{table}_{columns}_{suffix}` — the table prefix scopes the name to its
 * table, the covered columns say what it constrains, and the suffix says
 * WHAT it is (`index`, `unique`, `foreign`, `check`). One helper owns the
 * shape so the write side (the blueprint deriving names at declaration,
 * the connection deriving a drop handle) and the read side (the schema
 * inspector reading live constraints back) can never drift apart — a
 * convention split across implementations is two conventions waiting to
 * disagree.
 *
 * The suffix is a plain string rather than an enum: the callers are few
 * and fixed (the blueprint's index/check derivations, the FK add path,
 * the SQLite inspector's FK read-back), and the suffix is documentation
 * as much as data. Callers pass their own suffix; this class owns only
 * the SHAPE — and its structural preconditions (non-empty inputs). The
 * DIALECT limits (MySQL's 64 chars, Postgres' 63 bytes) are deliberately
 * NOT checked here: this class is dialect-agnostic, and the grammars'
 * assertValidIdentifier() owns those at compile time — the dialect
 * validates, never truncates.
 *
 * @see Blueprint For the "names are final before render" doctrine.
 */
final class ConstraintNamer
{
    /**
     * Derive a constraint name: `{table}_{columns}_{suffix}`.
     *
     * Validates the shape's preconditions — a malformed input would
     * silently produce a garbage handle (`_foreign`, `users__index`) that
     * the database only rejects (or worse, accepts) far from the
     * declaration. Dialect LIMITS are not checked here — see the class
     * docblock for the validation split.
     *
     * @param string $table The table the constraint is on.
     * @param list<string> $columns The constraint's covered columns (or
     *        the positional fallback for a column-less CHECK).
     * @param string $suffix The kind suffix — `index`, `unique`,
     *        `foreign`, `check`.
     * @return string The final constraint name.
     * @throws \InvalidArgumentException When the table, the suffix, or
     *         any covered column is empty (or the column list itself is).
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
