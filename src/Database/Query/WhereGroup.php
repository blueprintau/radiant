<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Query;

/**
 * An immutable SNAPSHOT of a nested where group's clause list.
 *
 * Stored in the parent builder's clause list under `WhereType::Nested` —
 * compiled SQL is fixed at group-close time, so an escaped WhereBuilder
 * reference can never alter a group after the fact.
 *
 * The object reference breaks the type recursion a bare
 * `list<WhereClause>` self-reference would create: the alias points at
 * the class, the class points back at the alias — legal, while alias →
 * alias recursion is not.
 *
 * @phpstan-import-type WhereClause from \BlueprintAU\Radiant\Database\Query\QueryBuilder
 */
final class WhereGroup
{
    /**
     * @param list<WhereClause> $wheres The group's clauses (snapshot).
     */
    public function __construct(
        public readonly array $wheres,
    ) {
    }
}
