<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Query;

/**
 * Value objects that are scalar-representable — `Uuid`, `Money`, enum-backed
 * types, etc. — implement this interface so they can be used anywhere a
 * scalar can.
 */
interface ToSqlValue
{
    /**
     * Convert this value to a SQL scalar (unquoted — the Grammar renders the
     * literal).
     *
     * @return string|int|float|bool|null
     */
    public function toSqlValue(): string|int|float|bool|null;
}
