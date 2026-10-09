<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Query;

/**
 * The SQL features a query builder can carry — the introspection surface
 * for feature-gating before execution.
 *
 * @package BlueprintAU\Radiant\Database\Query
 */
enum SqlFeature: string
{
    /** The query carries JOIN clauses (including MTI ancestor joins). */
    case Joins = 'joins';

    /** The query carries HAVING clauses. */
    case Having = 'having';

    /** The query filters on an {@see Aggregate} anywhere. */
    case Aggregates = 'aggregates';

    /** The query carries raw SQL fragments (whereRaw, Expression). */
    case RawSql = 'raw-sql';

    /** The query selects from a subquery (fromSub). */
    case SubqueryFrom = 'subquery-from';

    /** The query filters on an EXISTS (or NOT EXISTS) subquery (whereExists). */
    case SubqueryWhere = 'subquery-where';

    /** The query selects a scalar subquery column (selectSub). */
    case SubquerySelect = 'subquery-select';

    /** The query UNIONs another query. */
    case Unions = 'unions';

    /** The query requests a row lock (lockForUpdate / sharedLock). */
    case RowLocks = 'row-locks';

    /** The query is DISTINCT. */
    case Distinct = 'distinct';

    /**
     * Which features this query uses, as a set.
     *
     * Single pass: one foreach per state list classifies every entry — no
     * per-feature array_filter materializing throwaway arrays.
     *
     * @param  \BlueprintAU\Radiant\Database\Query\QueryBuilder  $query
     * @return list<self>
     */
    public static function usedBy(QueryBuilder $query): array
    {
        $rawWhere = false;
        $subqueryWhere = false;
        $rawOrderBy = false;
        $rawSelect = false;
        $subquerySelect = false;
        $aggregateSelect = false;
        $rawHaving = false;
        $aggregateHaving = false;

        foreach ($query->getWheres() as $where) {
            match ($where['type']) {
                \BlueprintAU\Radiant\Database\Query\Enums\WhereType::Raw => $rawWhere = true,
                \BlueprintAU\Radiant\Database\Query\Enums\WhereType::Exists,
                \BlueprintAU\Radiant\Database\Query\Enums\WhereType::InSub => $subqueryWhere = true,
                default => null,
            };
        }

        foreach ($query->getColumns() as $column) {
            if ($column instanceof Expression) {
                $rawSelect = true;
            } elseif ($column instanceof SubquerySelect) {
                $subquerySelect = true;
            } elseif ($column instanceof Aggregate) {
                $aggregateSelect = true;
            }
        }

        foreach ($query->getHavings() as $having) {
            if ($having['column'] instanceof Expression) {
                $rawHaving = true;
            } elseif ($having['column'] instanceof Aggregate) {
                $aggregateHaving = true;
            }
        }

        foreach ($query->getOrders() as $order) {
            if ($order['column'] instanceof Expression) {
                $rawOrderBy = true;
            }
        }

        $used = [];

        if ($query->getJoins() !== []) {
            $used[] = self::Joins;
        }
        if ($query->getHavings() !== [] || $aggregateHaving) {
            $used[] = self::Having;
        }
        if ($query->getGroups() !== [] || $aggregateSelect || $aggregateHaving) {
            $used[] = self::Aggregates;
        }
        if ($rawWhere || $rawOrderBy || $rawSelect || $rawHaving) {
            $used[] = self::RawSql;
        }
        if ($query->getFromAlias() !== null) {
            $used[] = self::SubqueryFrom;
        }
        if ($subqueryWhere) {
            $used[] = self::SubqueryWhere;
        }
        if ($subquerySelect) {
            $used[] = self::SubquerySelect;
        }
        if ($query->getUnions() !== []) {
            $used[] = self::Unions;
        }
        if ($query->getLock() !== null) {
            $used[] = self::RowLocks;
        }
        if ($query->isDistinct()) {
            $used[] = self::Distinct;
        }

        return $used;
    }
}
