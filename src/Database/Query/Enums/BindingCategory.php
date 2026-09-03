<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Query\Enums;

/**
 * The binding categories a query builder can hold.
 *
 * Bindings are stored per category so a statement root only flattens the
 * categories it actually compiled. Using an enum (rather than a bare string)
 * makes an invalid category a compile-time error instead of a silently
 * missing binding.
 */
enum BindingCategory: string
{
    /** Bindings for the select column list. */
    case Select = 'select';

    /** Bindings for the from clause. */
    case From = 'from';

    /** Bindings for join clauses. */
    case Join = 'join';

    /** Bindings for where clauses. */
    case Where = 'where';

    /** Bindings for group-by clauses. */
    case GroupBy = 'groupBy';

    /** Bindings for having clauses. */
    case Having = 'having';

    /** Bindings for order-by clauses. */
    case Order = 'order';

    /** Bindings for union clauses. */
    case Union = 'union';

    /** Bindings for lock clauses. */
    case Lock = 'lock';
}