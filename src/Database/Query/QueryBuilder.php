<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Query;

use BlueprintAU\Collections\Collection;
use BlueprintAU\Radiant\Database\Connections\ConnectionInterface;
use BlueprintAU\Radiant\Database\Query\Enums\BindingCategory;
use BlueprintAU\Radiant\Database\Query\Enums\ColumnOperator;
use BlueprintAU\Radiant\Database\Query\Enums\JoinType;
use BlueprintAU\Radiant\Database\Query\Enums\LockType;
use BlueprintAU\Radiant\Database\Query\Enums\SortDirection;
use BlueprintAU\Radiant\Database\Query\Enums\WhereBoolean;
use BlueprintAU\Radiant\Database\Query\Enums\WhereOperator;
use BlueprintAU\Radiant\Database\Query\Enums\WhereType;

/**
 * Builds a database query fluently.
 *
 * Chain methods like `where()`, `orderBy()`, and `limit()` to describe what
 * you want, then run it with `get()`. For example:
 *
 *     $users = $db->table('users')
 *         ->where('active', 1)
 *         ->orderBy('name')
 *         ->get();
 *
 * The same builder works against any backend: SQL databases compile it to
 * SQL, while a CSV connection applies the filters directly in PHP. Features
 * that only make sense for SQL (joins, having, transactions) throw an
 * {@see \BlueprintAU\Radiant\Database\Exceptions\UnsupportedFeatureException}
 * on backends that don't support them.
 *
 * **Bindings are stored per category** — {@see BindingCategory} — so a
 * statement root only flattens the categories it actually compiled (an
 * `update` never pulls in `having`/`order`/`union` bindings it doesn't use).
 * The Grammar emits `?` placeholders in the same canonical category order, so
 * the flattened list always matches the compiled SQL.
 *
 * @phpstan-type WhereClause array{type: WhereType::Basic, column: string, operator: WhereOperator, value: mixed, boolean: WhereBoolean} | array{type: WhereType::Between, column: string, operator: WhereOperator, value: array{0: mixed, 1: mixed}, boolean: WhereBoolean} | array{type: WhereType::Null, column: string, operator: WhereOperator, boolean: WhereBoolean} | array{type: WhereType::Raw, sql: string, boolean: WhereBoolean} | array{type: WhereType::Column, first: string, operator: ColumnOperator, second: string, boolean: WhereBoolean} | array{type: WhereType::Nested, query: WhereBuilder, boolean: WhereBoolean}
 * @phpstan-type BindingValue string|int|float|bool|null|\DateTimeInterface|Expression|ToSqlValue
 *
 * @see \BlueprintAU\Radiant\Database\Connections\ConnectionInterface
 */
class QueryBuilder
{
    /**
     * The columns to select.
     *
     * @var list<string|Expression>
     */
    protected array $columns = ['*'];

    /**
     * Whether the select is `DISTINCT`.
     *
     * @var bool
     */
    protected bool $distinct = false;

    /**
     * The from clause — a table name or a subquery builder.
     *
     * @var string|QueryBuilder
     */
    protected string|QueryBuilder $from;

    /**
     * The alias of the from subquery, when `fromSub()` was used.
     *
     * @var string|null
     */
    protected ?string $fromAlias = null;

    /**
     * The joins to apply.
     *
     * @var list<array{type: JoinType, table: string, wheres: list<array{type: WhereType::Column, first: string, operator: ColumnOperator, second: string, boolean: WhereBoolean}>}>
     */
    protected array $joins = [];

    /**
     * The where clauses.
     *
     * @var list<WhereClause>
     */
    protected array $wheres = [];

    /**
     * The group-by columns.
     *
     * @var list<string>
     */
    protected array $groups = [];

    /**
     * The having clauses.
     *
     * @var list<array{type: WhereType::Basic, column: string, operator: WhereOperator, value: mixed}>
     */
    protected array $havings = [];

    /**
     * The order-by clauses.
     *
     * A raw `orderByRaw()` entry has `direction: null` — its expression is
     * spliced verbatim with no direction appended.
     *
     * @var list<array{column: string|Expression, direction: SortDirection|null}>
     */
    protected array $orders = [];

    /**
     * The unions to append.
     *
     * @var list<array{query: QueryBuilder, all: bool}>
     */
    protected array $unions = [];

    /**
     * The row lock to apply, or null for none.
     *
     * @var LockType|null
     */
    protected ?LockType $lock = null;

    /**
     * The maximum number of rows to return.
     *
     * @var int|null
     */
    protected ?int $limit = null;

    /**
     * The number of rows to skip.
     *
     * @var int|null
     */
    protected ?int $offset = null;

    /**
     * The PK column to return on insert, if known (null for raw).
     *
     * @var string|null
     */
    protected ?string $insertIdColumn = null;

    /**
     * Bindings grouped by the clause they belong to.
     *
     * @var array<string, list<BindingValue>>
     */
    protected array $bindings = [
        BindingCategory::Select->value => [],
        BindingCategory::From->value => [],
        BindingCategory::Join->value => [],
        BindingCategory::Where->value => [],
        BindingCategory::GroupBy->value => [],
        BindingCategory::Having->value => [],
        BindingCategory::Order->value => [],
        BindingCategory::Union->value => [],
        BindingCategory::Lock->value => [],
    ];

    /**
     * Create a new query builder bound to a table on a connection.
     *
     * @param ConnectionInterface $connection The backend the query will run on.
     * @param string $table The table (or fully-qualified identifier) to query.
     */
    public function __construct(
        public readonly ConnectionInterface $connection,
        public readonly string $table,
    ) {
        $this->from = $table;
    }

    // ---- Selection ----

    /**
     * Set the columns to select.
     *
     * @param array<int, string|Expression>|string $columns A column list, or a single column.
     * @return $this
     */
    public function select(array|string $columns = ['*']): static
    {
        $this->columns = is_array($columns) ? array_values($columns) : func_get_args();
        return $this;
    }

    /**
     * Add a raw SQL expression to the select list.
     *
     * @param string $expression The raw SQL to select.
     * @return $this
     */
    public function selectRaw(string $expression): static
    {
        if ($this->columns === ['*']) {
            $this->columns = [];
        }
        $this->columns[] = new Expression($expression);
        return $this;
    }

    /**
     * Make the select distinct.
     *
     * @return $this
     */
    public function distinct(): static
    {
        $this->distinct = true;
        return $this;
    }

    // ---- From ----

    /**
     * Set the from clause to a subquery.
     *
     * Set-once like the table: the from cannot be changed after the builder
     * is created, so calling this on a builder that already has a subquery
     * from fails fast.
     *
     * @param QueryBuilder $query The subquery to select from.
     * @param string $alias The alias the subquery is referenced by.
     * @return $this
     */
    public function fromSub(QueryBuilder $query, string $alias): static
    {
        if ($this->from instanceof QueryBuilder) {
            throw new \LogicException('The query from is already set and cannot be changed.');
        }
        $this->from = $query;
        $this->fromAlias = $alias;
        return $this;
    }

    // ---- Joins ----

    /**
     * Add an inner join.
     *
     * @param string $table The table to join.
     * @param string $first The first column of the join condition.
     * @param ColumnOperator|string $operator The comparison operator.
     * @param string $second The second column of the join condition.
     * @return $this
     */
    public function join(string $table, string $first, ColumnOperator|string $operator = '=', string $second = ''): static
    {
        return $this->addJoin(JoinType::Inner, $table, $first, $operator, $second);
    }

    /**
     * Add a left join.
     *
     * @param string $table The table to join.
     * @param string $first The first column of the join condition.
     * @param ColumnOperator|string $operator The comparison operator.
     * @param string $second The second column of the join condition.
     * @return $this
     */
    public function leftJoin(string $table, string $first, ColumnOperator|string $operator = '=', string $second = ''): static
    {
        return $this->addJoin(JoinType::Left, $table, $first, $operator, $second);
    }

    /**
     * Add a right join.
     *
     * @param string $table The table to join.
     * @param string $first The first column of the join condition.
     * @param ColumnOperator|string $operator The comparison operator.
     * @param string $second The second column of the join condition.
     * @return $this
     */
    public function rightJoin(string $table, string $first, ColumnOperator|string $operator = '=', string $second = ''): static
    {
        return $this->addJoin(JoinType::Right, $table, $first, $operator, $second);
    }

    /**
     * Add a cross join.
     *
     * @param string $table The table to join.
     * @return $this
     */
    public function crossJoin(string $table): static
    {
        return $this->addJoin(JoinType::Cross, $table, '', '=', '');
    }

    /**
     * Append an additional ON condition to the most recent join.
     *
     * The first condition comes from the `join()` call itself; each `on()`
     * call adds another, connected by AND: `join('posts', 'posts.user_id', '=', 'users.id')->on('posts.active', '=', 'users.active')`
     * compiles to `ON "posts"."user_id" = "users"."id" AND "posts"."active" = "users"."active"`.
     *
     * Like the join condition itself, `on()` is strictly column-to-column —
     * values belong in `where()` after the join, not in the ON clause.
     *
     * @param string $first The first column of the condition.
     * @param ColumnOperator|string $operator The comparison operator.
     * @param string $second The second column of the condition.
     * @return $this
     * @throws \LogicException When no join has been added yet.
     * @throws \InvalidArgumentException When a string operator is not a valid column comparison.
     */
    public function on(string $first, ColumnOperator|string $operator = '=', string $second = ''): static
    {
        return $this->addOn(WhereBoolean::And, $first, $operator, $second);
    }

    /**
     * Append an additional OR-connected ON condition to the most recent join.
     *
     * @param string $first The first column of the condition.
     * @param ColumnOperator|string $operator The comparison operator.
     * @param string $second The second column of the condition.
     * @return $this
     * @throws \LogicException When no join has been added yet.
     * @throws \InvalidArgumentException When a string operator is not a valid column comparison.
     */
    public function orOn(string $first, ColumnOperator|string $operator = '=', string $second = ''): static
    {
        return $this->addOn(WhereBoolean::Or, $first, $operator, $second);
    }

    /**
     * Append an ON condition to the last added join.
     *
     * Conditions are strictly column-to-column ({@see WhereType::Column}) —
     * the join clause never binds values. The last join is targeted because
     * an ON condition always belongs to the join it follows.
     *
     * @param WhereBoolean $boolean The connector to the join's previous condition.
     * @param string $first The first column of the condition.
     * @param ColumnOperator|string $operator The comparison operator.
     * @param string $second The second column of the condition.
     * @return $this
     * @throws \LogicException When no join has been added yet.
     * @throws \InvalidArgumentException When a string operator is not a valid column comparison.
     */
    protected function addOn(WhereBoolean $boolean, string $first, ColumnOperator|string $operator, string $second): static
    {
        if ($this->joins === []) {
            throw new \LogicException(
                'Cannot call on()/orOn() before a join: an ON condition belongs to the join it follows. Call join()/leftJoin()/rightJoin()/crossJoin() first.'
            );
        }

        $resolved = $operator instanceof ColumnOperator ? $operator : ColumnOperator::fromChecked($operator);
        $last = count($this->joins) - 1;
        $this->joins[$last]['wheres'][] = [
            'type' => WhereType::Column,
            'first' => $first,
            'operator' => $resolved,
            'second' => $second,
            'boolean' => $boolean,
        ];
        return $this;
    }

    /**
     * Append a join clause to the query.
     *
     * The operator is resolved to a {@see ColumnOperator} — either passed as
     * the enum directly, or validated from a string — and stored as the
     * enum. The compiled SQL renders `->value`, so no raw string ever
     * reaches the statement.
     *
     * @param JoinType $type The join type.
     * @param string $table The table to join.
     * @param string $first The first column of the join condition.
     * @param ColumnOperator|string $operator The comparison operator.
     * @param string $second The second column of the join condition.
     * @return $this
     * @throws \InvalidArgumentException When a string operator is not a valid column comparison.
     */
    protected function addJoin(JoinType $type, string $table, string $first, ColumnOperator|string $operator, string $second): static
    {
        $resolved = $operator instanceof ColumnOperator ? $operator : ColumnOperator::fromChecked($operator);
        $this->joins[] = [
            'type' => $type,
            'table' => $table,
            'wheres' => $second === ''
                ? []
                : [[
                    'type' => WhereType::Column,
                    'first' => $first,
                    'operator' => $resolved,
                    'second' => $second,
                    'boolean' => WhereBoolean::And,
                ]],
        ];
        return $this;
    }

    // ---- Wheres ----

    /**
     * Add a where clause.
     *
     * @param string $column The column to compare.
     * @param WhereOperator|string $operator The comparison operator.
     * @param mixed $value The value to compare against.
     * @param WhereBoolean $boolean The boolean connector to the previous clause.
     * @return $this
     */
    public function where(string $column, WhereOperator|string $operator, mixed $value, WhereBoolean $boolean = WhereBoolean::And): static
    {
        $operator = $operator instanceof WhereOperator ? $operator : WhereOperator::from(strtoupper($operator));

        if ($operator === WhereOperator::In || $operator === WhereOperator::NotIn) {
            if (!is_array($value)) {
                throw new \InvalidArgumentException(
                    'whereIn()/whereNotIn() require an array of values; got ' . get_debug_type($value) . '.'
                );
            }
            if ($value === []) {
                throw new \InvalidArgumentException(
                    'whereIn()/whereNotIn() require a non-empty array of values; '
                    . 'an empty list compiles to invalid SQL. Filter in PHP or skip the clause instead.'
                );
            }
            $this->wheres[] = ['type' => WhereType::Basic, 'column' => $column, 'operator' => $operator, 'value' => $value, 'boolean' => $boolean];
            array_push($this->bindings[BindingCategory::Where->value], ...array_filter(
                array_values($value),
                fn ($item) => !$item instanceof Expression && !$item instanceof ToSqlValue,
            ));
            return $this;
        }

        if ($operator === WhereOperator::Between || $operator === WhereOperator::NotBetween) {
            $this->wheres[] = ['type' => WhereType::Between, 'column' => $column, 'operator' => $operator, 'value' => $value, 'boolean' => $boolean];
            array_push($this->bindings[BindingCategory::Where->value], ...array_filter(
                array_values($value),
                fn ($item) => !$item instanceof Expression && !$item instanceof ToSqlValue,
            ));
            return $this;
        }

        if ($operator === WhereOperator::Null || $operator === WhereOperator::NotNull) {
            $this->wheres[] = ['type' => WhereType::Null, 'column' => $column, 'operator' => $operator, 'boolean' => $boolean];
            return $this;
        }

        $this->wheres[] = ['type' => WhereType::Basic, 'column' => $column, 'operator' => $operator, 'value' => $value, 'boolean' => $boolean];
        if ($value !== null && !$value instanceof Expression && !$value instanceof ToSqlValue) {
            $this->bindings[BindingCategory::Where->value][] = $value;
        }
        return $this;
    }

    /**
     * Add an `or where` clause.
     *
     * @param string $column The column to compare.
     * @param WhereOperator|string $operator The comparison operator.
     * @param mixed $value The value to compare against.
     * @return $this
     */
    public function orWhere(string $column, WhereOperator|string $operator, mixed $value): static
    {
        return $this->where($column, $operator, $value, WhereBoolean::Or);
    }

    /**
     * Add a `where in` clause.
     *
     * @param string $column The column to test.
     * @param array<int, mixed> $values The list of values.
     * @param WhereBoolean $boolean The boolean connector.
     * @return $this
     */
    public function whereIn(string $column, array $values, WhereBoolean $boolean = WhereBoolean::And): static
    {
        return $this->where($column, WhereOperator::In, $values, $boolean);
    }

    /**
     * Add a `where not in` clause.
     *
     * @param string $column The column to test.
     * @param array<int, mixed> $values The list of values.
     * @param WhereBoolean $boolean The boolean connector.
     * @return $this
     */
    public function whereNotIn(string $column, array $values, WhereBoolean $boolean = WhereBoolean::And): static
    {
        return $this->where($column, WhereOperator::NotIn, $values, $boolean);
    }

    /**
     * Add a `where null` clause.
     *
     * @param string $column The column to test.
     * @param WhereBoolean $boolean The boolean connector.
     * @return $this
     */
    public function whereNull(string $column, WhereBoolean $boolean = WhereBoolean::And): static
    {
        return $this->where($column, WhereOperator::Null, null, $boolean);
    }

    /**
     * Add a `where not null` clause.
     *
     * @param string $column The column to test.
     * @param WhereBoolean $boolean The boolean connector.
     * @return $this
     */
    public function whereNotNull(string $column, WhereBoolean $boolean = WhereBoolean::And): static
    {
        return $this->where($column, WhereOperator::NotNull, null, $boolean);
    }

    /**
     * Add a `where between` clause.
     *
     * @param string $column The column to test.
     * @param array{0: mixed, 1: mixed} $range The two-value range `[min, max]`.
     * @param WhereBoolean $boolean The boolean connector.
     * @return $this
     */
    public function whereBetween(string $column, array $range, WhereBoolean $boolean = WhereBoolean::And): static
    {
        return $this->where($column, WhereOperator::Between, $range, $boolean);
    }

    /**
     * Add a `where not between` clause.
     *
     * @param string $column The column to test.
     * @param array{0: mixed, 1: mixed} $range The two-value range `[min, max]`.
     * @param WhereBoolean $boolean The boolean connector.
     * @return $this
     */
    public function whereNotBetween(string $column, array $range, WhereBoolean $boolean = WhereBoolean::And): static
    {
        return $this->where($column, WhereOperator::NotBetween, $range, $boolean);
    }

    /**
     * Add a raw SQL where clause.
     *
     * @param string $sql The raw SQL condition (e.g. `lower(email) = ?`).
     * @param array<int, mixed> $bindings The values to bind into the condition.
     * @param WhereBoolean $boolean The boolean connector.
     * @return $this
     */
    public function whereRaw(string $sql, array $bindings = [], WhereBoolean $boolean = WhereBoolean::And): static
    {
        $this->wheres[] = ['type' => WhereType::Raw, 'sql' => $sql, 'boolean' => $boolean];
        array_push($this->bindings[BindingCategory::Where->value], ...$bindings);
        return $this;
    }

    /**
     * Add a column-to-column comparison.
     *
     * The operator is interpolated verbatim between two identifiers in the
     * compiled SQL, so it is resolved to a {@see ColumnOperator} — either
     * passed as the enum directly, or validated from a string. The enum is
     * stored, not a string: nothing raw ever reaches the SQL.
     *
     * @param string $first The first column.
     * @param ColumnOperator|string $operator The comparison operator (=, !=, <, <=, >, >=).
     * @param string $second The second column.
     * @param WhereBoolean $boolean The boolean connector.
     * @return $this
     * @throws \InvalidArgumentException When a string operator is not a valid column comparison.
     */
    public function whereColumn(string $first, ColumnOperator|string $operator = '=', string $second = '', WhereBoolean $boolean = WhereBoolean::And): static
    {
        $resolved = $operator instanceof ColumnOperator ? $operator : ColumnOperator::fromChecked($operator);
        $this->wheres[] = ['type' => WhereType::Column, 'first' => $first, 'operator' => $resolved, 'second' => $second, 'boolean' => $boolean];
        return $this;
    }

    /**
     * Add a nested group of where clauses.
     *
     * The callback receives a {@see WhereBuilder} — the where-family ONLY:
     * a parenthesized group is a filter, not a query, so it cannot JOIN,
     * select, order, or page. The clauses land on THIS builder and are
     * wrapped in parentheses at compile time.
     *
     * @param callable(WhereBuilder): void $callback Receives the group's
     *        where-family facade to constrain.
     * @param WhereBoolean $boolean The boolean connector.
     * @return $this
     */
    public function whereNested(callable $callback, WhereBoolean $boolean = WhereBoolean::And): static
    {
        $nested = $this->newNestedBuilder();
        $group = new WhereBuilder($nested);
        $callback($group);

        // An empty group is a declaration bug, not a neutral filter: on SQL
        // it compiles to degenerate `()` SQL, and evaluators that walk the
        // clause list would read past its end. Fail fast at declaration.
        if ($nested->getWheres() === []) {
            throw new \InvalidArgumentException(
                'A nested where group must contain at least one clause; the callback added none.'
            );
        }

        $this->wheres[] = ['type' => WhereType::Nested, 'query' => $group, 'boolean' => $boolean];
        array_push($this->bindings[BindingCategory::Where->value], ...$nested->getBindings([BindingCategory::Where]));
        return $this;
    }

    /**
     * Add an OR-connected nested group of where clauses.
     *
     * The sugar for composing tuple matches: each call appends one parenthesized
     * group connected by OR —
     * `whereNested(fn ($q) => ...)->orWhereNested(fn ($q) => ...)` compiles to
     * `WHERE (... AND ...) OR (... AND ...)`.
     *
     * @param callable(WhereBuilder): void $callback Receives the group's
     *        where-family facade to constrain.
     * @return $this
     */
    public function orWhereNested(callable $callback): static
    {
        return $this->whereNested($callback, WhereBoolean::Or);
    }

    /**
     * The builder a nested where group stores its clauses on.
     *
     * The ONE construction point `whereNested()` owns the whole group
     * algorithm through (build → callback → empty guard → store), so a
     * subclass only swaps WHAT the group is: {@see ModelQueryBuilder}
     * constructs a MODEL builder, which makes every callback clause funnel
     * through column validation — no per-subclass duplication of the
     * merge/guard steps, and the callback and the stored group share the
     * ONE `WhereBuilder` instance.
     *
     * @return self The group's backing builder.
     */
    protected function newNestedBuilder(): self
    {
        return new self($this->connection, $this->table);
    }

    // ---- Grouping / Having ----

    /**
     * Group rows by one or more columns (for aggregate + select combos).
     *
     * @param string|array<int, string> $columns The column(s) to group by.
     * @return $this
     */
    public function groupBy(string|array $columns): static
    {
        $this->groups = array_merge($this->groups, is_array($columns) ? $columns : func_get_args());
        return $this;
    }

    /**
     * Filter groups after aggregation (HAVING).
     *
     * @param string $column The column (or aggregate expression) to compare.
     * @param WhereOperator|string $operator The comparison operator.
     * @param mixed $value The value to compare against.
     * @return $this
     */
    public function having(string $column, WhereOperator|string $operator, mixed $value): static
    {
        $operator = $operator instanceof WhereOperator ? $operator : WhereOperator::from(strtoupper($operator));
        $this->havings[] = ['type' => WhereType::Basic, 'column' => $column, 'operator' => $operator, 'value' => $value];
        if ($value !== null && !$value instanceof Expression && !$value instanceof ToSqlValue) {
            $this->bindings[BindingCategory::Having->value][] = $value;
        }
        return $this;
    }

    // ---- Ordering / Limit / Offset ----

    /**
     * Add an order-by clause.
     *
     * The direction is validated against {@see SortDirection} — a
     * non-`ASC`/`DESC` direction is a caller bug or injection attempt and
     * fails fast, so the direction is always safe to interpolate into the
     * compiled SQL. Pass a {@see SortDirection} case for static-analysis
     * safety, or a string for convenience.
     *
     * @param string $column The column to order by.
     * @param SortDirection|string $direction `ASC` or `DESC` (case-insensitive string).
     * @return $this
     * @throws \InvalidArgumentException When the direction is not `ASC` or `DESC`.
     */
    public function orderBy(string $column, SortDirection|string $direction = SortDirection::Asc): static
    {
        $normalized = $direction instanceof SortDirection
            ? $direction
            : SortDirection::fromChecked($direction);
        $this->orders[] = ['column' => $column, 'direction' => $normalized];
        return $this;
    }

    /**
     * Add a raw SQL order-by expression.
     *
     * @param string $sql The raw SQL (e.g. `FIELD(status, 'new', 'done')`).
     * @return $this
     */
    public function orderByRaw(string $sql): static
    {
        $this->orders[] = ['column' => new Expression($sql), 'direction' => null];
        return $this;
    }

    /**
     * Set the maximum number of rows to return.
     *
     * @param int $limit The row limit.
     * @return $this
     */
    public function limit(int $limit): static
    {
        $this->limit = $limit;
        return $this;
    }

    /**
     * Set the number of rows to skip.
     *
     * @param int $offset The row offset.
     * @return $this
     */
    public function offset(int $offset): static
    {
        $this->offset = $offset;
        return $this;
    }

    // ---- Unions ----

    /**
     * Append a union to the query.
     *
     * The sub-builder's bindings are NOT copied here — the grammar compiles
     * each union's SQL at compile time and pulls the sub-builder's bindings
     * then (see {@see \BlueprintAU\Radiant\Database\Grammars\Grammar::compileUnions()}).
     * Snapshotting at call time desynchronized the `?` order from the
     * flattened binding list whenever the sub-builder gained clauses after
     * the union() call; deferring to compile time keeps them in lockstep.
     *
     * @param QueryBuilder $query The query to union with.
     * @param bool $all Whether to use `UNION ALL`.
     * @return $this
     */
    public function union(QueryBuilder $query, bool $all = false): static
    {
        $this->unions[] = ['query' => $query, 'all' => $all];
        return $this;
    }

    // ---- Locks ----

    /**
     * Lock the selected rows for update.
     *
     * @return $this
     */
    public function lockForUpdate(): static
    {
        $this->lock = LockType::Update;
        return $this;
    }

    /**
     * Lock the selected rows in shared mode.
     *
     * @return $this
     */
    public function sharedLock(): static
    {
        $this->lock = LockType::Shared;
        return $this;
    }

    // ---- Execution ----

    /**
     * Run the query and return the matching rows.
     *
     * @return Collection<int, \stdClass> The matching rows, each as an object.
     */
    public function get(): Collection
    {
        return $this->connection->select($this);
    }

    /**
     * Run the query and yield each matching row as it arrives.
     *
     * The streaming counterpart of {@see get()}: the connection hands over
     * rows one at a time, so the caller never holds the full result set as
     * PHP objects — use it when the query may match more rows than fit in
     * memory comfortably. Each backend materializes rows however its
     * transport allows (see {@see ConnectionInterface::cursor()}); the
     * builder itself stays backend-agnostic.
     *
     * Consume the generator fully (or let it be garbage collected) before
     * running another query on the connection.
     *
     * @return \Generator<int, \stdClass> The matching rows, one at a time.
     */
    public function cursor(): \Generator
    {
        return $this->connection->cursor($this);
    }

    /**
     * Run the query and return the first matching row.
     *
     * @return \stdClass|null The first row, or null when none match.
     */
    public function first(): ?object
    {
        return $this->limit(1)->get()->first();
    }

    /**
     * The scalar method — the value of a single column from the first row.
     *
     * The column is selected under a stable alias so the result can be read
     * back by name regardless of how the dialect names the raw expression
     * (SQLite keeps `sum("price")`, Postgres strips to `sum`, MySQL keeps
     * the backticks). Only the aliased header is guaranteed portable. When
     * the caller already provides an `as` alias on the column, that alias
     * is used instead.
     *
     * @param string $column The column to read.
     * @return mixed The column value, or null when no row matches.
     */
    public function value(string $column): mixed
    {
        [$sql, $alias] = $this->scalarColumn($column);
        return $this->select($sql)->first()->{$alias} ?? null;
    }

    /**
     * A collection of a single column's values from all rows.
     *
     * The column is selected under a stable alias so the value can be read
     * back by name regardless of how the dialect names the raw expression
     * (see {@see value()}). When the caller already provides an `as` alias
     * on the column, that alias is used instead.
     *
     * @param string $column The column to pluck.
     * @return Collection<int, mixed> The column values.
     */
    public function pluck(string $column): Collection
    {
        [$sql, $alias] = $this->scalarColumn($column);
        return $this->select($sql)->get()->pluck($alias);
    }

    /**
     * Resolve a column into the SQL to select and the alias to read back.
     *
     * Returns the column unchanged with its own alias when one is given
     * (`sum(price) as total` → read `total`); otherwise the column is
     * selected under the stable `radiant_scalar` alias so scalar reads
     * are portable across dialects.
     *
     * @param string $column The column expression.
     * @return array{0: string, 1: string} The select SQL and result alias.
     */
    protected function scalarColumn(string $column): array
    {
        if (preg_match('/\s+as\s+[`"]?([a-z_][a-z0-9_]*)[`"]?$/i', $column, $matches)) {
            return [$column, $matches[1]];
        }
        return [$column . ' as radiant_scalar', 'radiant_scalar'];
    }

    // ---- Aggregates are just select fields (built on select()) ----

    /**
     * Count the matching rows.
     *
     * @return int The row count.
     */
    public function count(): int
    {
        return (int) $this->value('count(*)');
    }

    /**
     * Whether any matching rows exist.
     *
     * @return bool True when at least one row matches.
     */
    public function exists(): bool
    {
        return $this->count() > 0;
    }

    /**
     * The maximum value of a column.
     *
     * @param string $column The column to aggregate.
     * @return mixed The maximum value.
     */
    public function max(string $column): mixed
    {
        return $this->value("max({$column})");
    }

    /**
     * The minimum value of a column.
     *
     * @param string $column The column to aggregate.
     * @return mixed The minimum value.
     */
    public function min(string $column): mixed
    {
        return $this->value("min({$column})");
    }

    /**
     * The sum of a column's values.
     *
     * @param string $column The column to aggregate.
     * @return mixed The sum.
     */
    public function sum(string $column): mixed
    {
        return $this->value("sum({$column})");
    }

    /**
     * The average of a column's values.
     *
     * @param string $column The column to aggregate.
     * @return mixed The average.
     */
    public function avg(string $column): mixed
    {
        return $this->value("avg({$column})");
    }

    /**
     * Multiple aggregates in one query.
     *
     * @param array<string, array{0: string, 1: string}> $aggregates
     *        `['total' => ['count', '*'], 'max_price' => ['max', 'price']]`.
     * @return array<string, mixed> The aggregate values keyed by alias.
     */
    public function aggregates(array $aggregates): array
    {
        $columns = [];
        foreach ($aggregates as $alias => [$function, $column]) {
            $columns[] = "{$function}({$column}) as {$alias}";
        }
        return (array) $this->select($columns)->first();
    }

    // ---- Writes ----

    /**
     * Insert one or more rows.
     *
     * @param array<string, mixed>|list<array<string, mixed>> $values A single
     *        row or a list of rows.
     * @return int The number of rows inserted.
     */
    public function insert(array $values): int
    {
        return $this->connection->insert($this, $values);
    }

    /**
     * Insert a single row and return the generated id.
     *
     * @param array<string, mixed> $values The row to insert.
     * @return string|int|null The generated id, or null when there is none.
     */
    public function insertGetId(array $values): string|int|null
    {
        return $this->connection->insertGetId($this, $values);
    }

    /**
     * Update the rows matching the query's conditions.
     *
     * @param array<string, mixed> $values The columns to change and their new values.
     * @return int How many rows were updated.
     */
    public function update(array $values): int
    {
        return $this->connection->update($this, $values);
    }

    /**
     * Delete the rows matching the query's conditions.
     *
     * @return int How many rows were deleted.
     */
    public function delete(): int
    {
        return $this->connection->delete($this);
    }

    // ---- Bindings ----

    /**
     * Flatten the bindings for the given categories, in canonical order.
     *
     * @param list<BindingCategory>|null $categories The categories to flatten;
     *        null flattens every category in canonical order.
     * @return list<BindingValue> The flattened bindings.
     */
    public function getBindings(?array $categories = null): array
    {
        $categories ??= array_keys($this->bindings);
        $bindings = [];
        foreach ($categories as $category) {
            $key = $category instanceof BindingCategory ? $category->value : $category;
            array_push($bindings, ...$this->bindings[$key]);
        }
        return $bindings;
    }

    /**
     * Append bindings to a category at compile time.
     *
     * Internal: the Grammar calls this while compiling unions, so a
     * sub-builder's bindings land in the Union category in exactly the
     * order its SQL was compiled — placeholders and flattened bindings stay
     * in lockstep even when clauses were added after union() was called.
     *
     * @param BindingCategory $category The category to append to.
     * @param list<mixed> $bindings The values to append.
     * @return void
     */
    public function pushBindings(BindingCategory $category, array $bindings): void
    {
        array_push($this->bindings[$category->value], ...$bindings);
    }

    /**
     * Declare the PK column so insertGetId() can return it (RETURNING / lastInsertId).
     *
     * @param string $column The primary key column.
     * @return $this
     */
    public function insertIdColumn(string $column): static
    {
        $this->insertIdColumn = $column;
        return $this;
    }

    // ---- Accessors: the query state contract ----

    /*
     * These getters ARE the public contract for every consumer of a built
     * query: the SQL {@see Grammar} compiles them to text, and custom
     * `ConnectionInterface` implementations (e.g. CSV) execute them directly
     * in PHP. Every returned shape is fully typed — discriminated unions
     * pinned by enum literals, never bare strings for anything an author
     * must branch on — so a custom connection can exhaustively match the
     * state without reading this class's source. Shapes are defined here and
     * imported elsewhere via `@phpstan-import-type`; they must not drift
     * between consumers.
     */

    /**
     * The columns to select.
     *
     * @return list<string|Expression>
     */
    public function getColumns(): array
    {
        return $this->columns;
    }

    /**
     * Whether the select is distinct.
     *
     * @return bool True when distinct.
     */
    public function isDistinct(): bool
    {
        return $this->distinct;
    }

    /**
     * The from clause — a table name or a subquery builder.
     *
     * @return string|QueryBuilder
     */
    public function getFrom(): string|QueryBuilder
    {
        return $this->from;
    }

    /**
     * The alias of the from subquery, when `fromSub()` was used.
     *
     * @return string|null The alias, or null when there is none.
     */
    public function getFromAlias(): ?string
    {
        return $this->fromAlias;
    }

    /**
     * The joins to apply.
     *
     * @return list<array{type: JoinType, table: string, wheres: list<array{type: WhereType::Column, first: string, operator: ColumnOperator, second: string, boolean: WhereBoolean}>}>
     */
    public function getJoins(): array
    {
        return $this->joins;
    }

    /**
     * The where clauses.
     *
     * A discriminated union keyed by {@see WhereType} — exhaustively match on
     * `type` to handle every shape. Nested queries carry their own builder;
     * recurse via `->getWheres()`.
     *
     * @return list<WhereClause>
     */
    public function getWheres(): array
    {
        return $this->wheres;
    }

    /**
     * The group-by columns.
     *
     * @return list<string>
     */
    public function getGroups(): array
    {
        return $this->groups;
    }

    /**
     * The having clauses.
     *
     * @return list<array{type: WhereType::Basic, column: string, operator: WhereOperator, value: mixed}>
     */
    public function getHavings(): array
    {
        return $this->havings;
    }

    /**
     * The order-by clauses.
     *
     * Part of the contract consumed by both {@see \BlueprintAU\Radiant\Database\Grammars\Grammar}
     * (SQL compilation) and custom `ConnectionInterface` implementations
     * (non-SQL execution). `direction` is the {@see SortDirection} enum —
     * never a bare string — or `null` for a raw `orderByRaw()` expression.
     *
     * @return list<array{column: string|Expression, direction: SortDirection|null}>
     */
    public function getOrders(): array
    {
        return $this->orders;
    }

    /**
     * The unions to append.
     *
     * @return list<array{query: QueryBuilder, all: bool}>
     */
    public function getUnions(): array
    {
        return $this->unions;
    }

    /**
     * The row lock to apply, or null for none.
     *
     * @return LockType|null The lock, or null when there is none.
     */
    public function getLock(): ?LockType
    {
        return $this->lock;
    }

    /**
     * The maximum number of rows to return.
     *
     * @return int|null The limit, or null when there is none.
     */
    public function getLimit(): ?int
    {
        return $this->limit;
    }

    /**
     * The number of rows to skip.
     *
     * @return int|null The offset, or null when there is none.
     */
    public function getOffset(): ?int
    {
        return $this->offset;
    }

    /**
     * The PK column to return on insert, if known.
     *
     * @return string|null The PK column, or null when unknown.
     */
    public function getInsertIdColumn(): ?string
    {
        return $this->insertIdColumn;
    }
}
