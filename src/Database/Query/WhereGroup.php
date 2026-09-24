<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Query;

/**
 * An immutable snapshot of a nested where group's clause list.
 *
 * @phpstan-import-type WhereClause from \BlueprintAU\Radiant\Database\Query\QueryBuilder
 */
final class WhereGroup
{
    /**
     * @param  list<WhereClause>  $wheres
     */
    public function __construct(
        public readonly array $wheres,
    ) {
    }
}
