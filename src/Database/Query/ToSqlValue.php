<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Query;

/**
 * Value objects that are scalar-representable — `Uuid`, `Money`, enum-backed
 * types, etc. — implement this interface so they can be used anywhere a
 * scalar can.
 *
 * It is the value-side counterpart to the codec: the codec produces driver
 * bytes for the bind path; `ToSqlValue` produces a SQL scalar for the inline
 * path (ORDER BY, GROUP BY, DDL, some dialect LIMIT/OFFSET — positions where
 * binding isn't possible).
 *
 * A `ToSqlValue` is **not** raw SQL: `toSqlValue()` returns an unquoted
 * scalar, never spliced text. Quoting/formatting is owned by the Grammar, so
 * value objects stay dialect-agnostic.
 */
interface ToSqlValue
{
    /**
     * Convert this value to a SQL scalar (unquoted — the Grammar renders the
     * literal).
     *
     * @return string|int|float|bool|null The scalar this value represents.
     */
    public function toSqlValue(): string|int|float|bool|null;
}
