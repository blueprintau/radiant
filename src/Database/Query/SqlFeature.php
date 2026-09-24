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

    /** The query UNIONs another query. */
    case Unions = 'unions';

    /** The query requests a row lock (lockForUpdate / sharedLock). */
    case RowLocks = 'row-locks';

    /** The query is DISTINCT. */
    case Distinct = 'distinct';

    /**
     * Which features this query uses, as a set.
     *
     * @param  \BlueprintAU\Radiant\Database\Query\QueryBuilder  $query
     * @return list<self>
     */
    public static function usedBy(QueryBuilder $query): array
    {
        $used = [];

        $rawWhere = array_filter(
            $query->getWheres(),
            fn(array $where) => $where['type'] === \BlueprintAU\Radiant\Database\Query\Enums\WhereType::Raw,
        );
        $rawOrderBy = array_filter(
            $query->getOrders(),
            fn(array $order) => $order['column'] instanceof Expression,
        );
        $rawSelect = array_filter(
            $query->getColumns(),
            fn(string|Expression|Aggregate $column) => $column instanceof Expression,
        );

        if ($query->getJoins() !== []) {
            $used[] = self::Joins;
        }
        if ($query->getHavings() !== []) {
            $used[] = self::Having;
        }
        if ($query->getGroups() !== []
            || self::columnsContainAggregate($query->getColumns())
            || self::havingsContainAggregate($query->getHavings())
        ) {
            $used[] = self::Aggregates;
        }
        if ($rawWhere !== [] || $rawOrderBy !== [] || $rawSelect !== []) {
            $used[] = self::RawSql;
        }
        if ($query->getFromAlias() !== null) {
            $used[] = self::SubqueryFrom;
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

    /**
     * Whether any selected column is an aggregate.
     *
     * @param  list<string|Expression|Aggregate>  $columns
     * @return bool
     */
    private static function columnsContainAggregate(array $columns): bool
    {
        foreach ($columns as $column) {
            if ($column instanceof Aggregate) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether any having clause compares an Aggregate.
     *
     * @param  list<array{type: \BlueprintAU\Radiant\Database\Query\Enums\WhereType::Basic, column: string|Expression|Aggregate, operator: \BlueprintAU\Radiant\Database\Query\Enums\WhereOperator, value: mixed}>  $havings
     * @return bool
     */
    private static function havingsContainAggregate(array $havings): bool
    {
        foreach ($havings as $having) {
            if ($having['column'] instanceof Aggregate) {
                return true;
            }
        }
        return false;
    }
}
