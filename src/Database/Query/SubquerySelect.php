<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Query;

use BlueprintAU\Radiant\Database\Query\Enums\BindingCategory;

/**
 * A scalar-subquery select column: `(SELECT …) AS alias`.
 *
 * Unlike an {@see Expression} — verbatim SQL with no structure — this node
 * keeps the sub-builder intact so the {@see Grammar} renders it by
 * recursion (the same way a `fromSub()` FROM compiles), and so the
 * subquery's bindings ride the dedicated Select category ahead of every
 * FROM/JOIN/WHERE binding, mirroring SQL text order.
 *
 * @package BlueprintAU\Radiant\Database\Query
 */
final class SubquerySelect
{
    /**
     * Create a scalar-subquery select column.
     *
     * @param  QueryBuilder  $query  The subquery; it must select exactly one column.
     * @param  string  $alias  The result column name — a bare identifier.
     * @throws \InvalidArgumentException
     */
    public function __construct(
        public readonly QueryBuilder $query,
        public readonly string $alias,
    ) {
        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $alias) !== 1) {
            throw new \InvalidArgumentException(
                "A subquery select alias must be a bare identifier; got [{$alias}]."
            );
        }

        // Bindings cannot be captured here — the QueryBuilder owns its
        // binding store. QueryBuilder::select() derives the Select
        // binding bucket from the column list on every call, so the
        // node's bindings ride the Select category without a separate
        // capture step (the same split as Aggregate, which validates
        // here and renders later).
    }

    /**
     * The subquery's bindings, flattened for eager capture.
     *
     * A helper for the declaring builder — keeps the canonical
     * getBindings() call in one place.
     *
     * @return list<string|int|float|bool|null|\DateTimeInterface|\BlueprintAU\Radiant\Database\Query\Expression|\BlueprintAU\Radiant\Database\Query\ToSqlValue>
     */
    public function bindings(): array
    {
        return $this->query->getBindings();
    }

    /**
     * The Select binding category this node's bindings belong to.
     *
     * @return BindingCategory
     */
    public static function bindingCategory(): BindingCategory
    {
        return BindingCategory::Select;
    }
}
