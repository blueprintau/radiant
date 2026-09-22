<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Query;

use BlueprintAU\Collections\Collection;
use BlueprintAU\Radiant\Concerns\FiltersWhere;
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
 * **Builders are immutable.** Every filter/select/order/limit call returns
 * a NEW builder — the original is never modified, so a builder can be
 * shared, reused, and chained safely (`$base = ...; $a = $base->where(...)`
 * leaves `$base` untouched). The copies are cheap: all builder state is
 * value-type arrays (wheres, bindings, orders, columns) and PHP's
 * copy-on-write means `clone` does not deep-copy them until a write.
 * The only reference-type state ($connection, union/$from sub-builders,
 * Expression/Aggregate value objects, nested clause snapshots) is shared —
 * safe because every shared object is itself immutable.
 *
 * @phpstan-type WhereClause array{type: WhereType::Basic, column: string|Expression, operator: WhereOperator, value: mixed, boolean: WhereBoolean, softDelete?: true} | array{type: WhereType::Between, column: string|Expression, operator: WhereOperator, value: array{0: mixed, 1: mixed}, boolean: WhereBoolean, softDelete?: true} | array{type: WhereType::Null, column: string|Expression, operator: WhereOperator, boolean: WhereBoolean, softDelete?: true} | array{type: WhereType::Raw, sql: string, boolean: WhereBoolean, softDelete?: true} | array{type: WhereType::Column, first: string, operator: ColumnOperator, second: string, boolean: WhereBoolean, softDelete?: true} | array{type: WhereType::Nested, group: WhereGroup, boolean: WhereBoolean}
 * @phpstan-type BindingValue string|int|float|bool|null|\DateTimeInterface|Expression|ToSqlValue
 *
 * @see \BlueprintAU\Radiant\Database\Connections\ConnectionInterface
 */
class QueryBuilder
{
    use FiltersWhere;

    /**
     * The columns to select.
     *
     * @var list<string|Expression|Aggregate>
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
     * The compared `column` may be a plain column, an {@see Expression}, or
     * an {@see Aggregate} (filtering on a computed value —
     * `HAVING count(*) > ?`).
     *
     * @var list<array{type: WhereType::Basic, column: string|Expression|Aggregate, operator: WhereOperator, value: mixed}>
     */
    protected array $havings = [];

    /**
     * The order-by clauses.
     *
     * An {@see Expression} column is spliced verbatim; its direction is
     * still validated and appended after it.
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
     * Whether the {@see $insertIdColumn} is auto-increment (server-generated).
     *
     * Null when no id column is declared. Distinguishes "the database will
     * generate this key" from "the caller supplies it" — insertGetId()'s
     * lastInsertId() fallback is only meaningful for the former.
     *
     * @var bool|null
     */
    protected ?bool $insertIdAutoIncrement = null;

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
     * A raw SQL fragment is expressed by passing an {@see Expression} — the
     * only raw-select path, and a greppable one: every verbatim splice in
     * an app is a visible `new Expression(...)`.
     *
     * An aggregate is declared as an {@see Aggregate} object (static
     * factories cover the common five; `new Aggregate(...)` covers
     * server-specific functions). The old string form
     * (`'count(*) as total'`) is no longer accepted.
     *
     * VARIADIC: one column per argument. Calling with NO arguments resets
     * to the `['*']` default select — the explicit reset form.
     *
     * @param string|Expression|Aggregate ...$columns Each column as its own argument, or none to reset to `*`.
     * @return static A new builder with the select applied; the original is unchanged.
     */
    public function select(string|Expression|Aggregate ...$columns): static
    {
        $clone = clone $this;
        $clone->columns = $columns === [] ? ['*'] : array_values($columns);
        return $clone;
    }

    /**
     * Make the select distinct.
     *
     * @return static A new builder with the distinct flag set; the original is unchanged.
     */
    final public function distinct(): static
    {
        $clone = clone $this;
        $clone->distinct = true;
        return $clone;
    }

    // ---- From ----

    /**
     * Set the from clause to a subquery.
     *
     * Set-once like the table: the from cannot be changed after the builder
     * is created, so calling this on a builder that already has a subquery
     * from fails fast.
     *
     * The sub-builder's bindings are SNAPSHOT onto the returned clone
     * immediately — a builder is a value, so the sub-builder is expected to
     * be final when fromSub() is called. No compile pass ever writes to a
     * builder: compilation stays a pure snapshot.
     *
     * @param QueryBuilder $query The subquery to select from.
     * @param string $alias The alias the subquery is referenced by.
     * @return static A new builder with the subquery from; the original is unchanged.
     */
    final public function fromSub(QueryBuilder $query, string $alias): static
    {
        if ($this->from instanceof QueryBuilder) {
            throw new \LogicException('The query from is already set and cannot be changed.');
        }
        $clone = clone $this;
        $clone->from = $query;
        $clone->fromAlias = $alias;
        // Eager capture: the sub-builder's full binding list rides the
        // From category of the RETURNED clone (the subquery's `?`s all sit
        // inside the compiled from clause, so their order is exactly the
        // sub-builder's flattened order).
        $clone->bindings[BindingCategory::From->value] = $query->getBindings();
        return $clone;
    }

    // ---- Joins ----

    /**
     * Add an inner join.
     *
     * @param string $table The table to join.
     * @param string $first The first column of the join condition.
     * @param ColumnOperator|string $operator The comparison operator.
     * @param string $second The second column of the join condition.
     * @return static A new builder with the join appended; the original is unchanged.
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
     * @return static A new builder with the join appended; the original is unchanged.
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
     * @return static A new builder with the join appended; the original is unchanged.
     */
    public function rightJoin(string $table, string $first, ColumnOperator|string $operator = '=', string $second = ''): static
    {
        return $this->addJoin(JoinType::Right, $table, $first, $operator, $second);
    }

    /**
     * Add a cross join.
     *
     * @param string $table The table to join.
     * @return static A new builder with the join appended; the original is unchanged.
     */
    final public function crossJoin(string $table): static
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
     * @return static A new builder with the ON condition appended; the original is unchanged.
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
     * @return static A new builder with the ON condition appended; the original is unchanged.
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
     * @return static A new builder with the ON condition appended; the original is unchanged.
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
        $clone = clone $this;
        $clone->joins[$last]['wheres'][] = [
            'type' => WhereType::Column,
            'first' => $first,
            'operator' => $resolved,
            'second' => $second,
            'boolean' => $boolean,
        ];
        return $clone;
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
     * @return static A new builder with the join appended; the original is unchanged.
     * @throws \InvalidArgumentException When a string operator is not a valid column comparison.
     */
    protected function addJoin(JoinType $type, string $table, string $first, ColumnOperator|string $operator, string $second): static
    {
        $resolved = $operator instanceof ColumnOperator ? $operator : ColumnOperator::fromChecked($operator);
        $clone = clone $this;
        $clone->joins[] = [
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
        return $clone;
    }

    /**
     * Assert a list is homogeneous — all plain bindable values, or ALL raw
     * (Expression/ToSqlValue). A mixed list desyncs placeholders from
     * bindings on the IN/BETWEEN compile shapes: see the where() call sites.
     *
     * @param array<int, mixed> $value The candidate list.
     * @param string $method The calling method name for the error message.
     * @return void
     * @throws \InvalidArgumentException When the list mixes kinds.
     */
    private function assertHomogeneousList(array $value, string $method): void
    {
        $hasRaw = false;
        $hasPlain = false;
        foreach ($value as $item) {
            if ($item instanceof Expression || $item instanceof ToSqlValue) {
                $hasRaw = true;
            } else {
                $hasPlain = true;
            }

            if ($hasRaw && $hasPlain) {
                throw new \InvalidArgumentException(
                    "{$method} require a list of ALL plain values or ALL raw SQL expressions "
                    . '(Expression/ToSqlValue) — a mixed list desyncs placeholders from bindings. '
                    . 'Split into separate clauses or normalize the list.'
                );
            }
        }
    }

    // ---- Wheres ----

    /**
     * Add a where clause.
     *
     * The column is a plain string or a raw {@see Expression} — the only
     * verbatim-splice path, greppable by the `new Expression(...)` wrapper.
     * Aggregate left-hand sides are structurally impossible here: they are
     * select/having territory (SQL forbids aggregates in WHERE), so the
     * typed {@see Aggregate} is not part of this signature at all.
     *
     * @param string|Expression $column The column to compare — or a raw
     *        SQL fragment wrapped in an Expression.
     * @param WhereOperator|string $operator The comparison operator.
     * @param mixed $value The value to compare against.
     * @param WhereBoolean $boolean The boolean connector to the previous clause.
     * @return static A new builder with the clause appended; the original is unchanged.
     */
    public function where(string|Expression $column, WhereOperator|string $operator, mixed $value, WhereBoolean $boolean = WhereBoolean::And): static
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
            // Every list element must be the SAME kind — a plain bindable
            // value or a raw Expression/ToSqlValue. A MIXED list desyncs
            // placeholders from bindings: the grammar renders one `?` per
            // element, but the binding filter drops the raw ones, so the
            // driver receives fewer values than placeholders (a hard
            // QueryException on strict drivers, silent mis-binding on lax
            // ones). Fail fast at declaration.
            $this->assertHomogeneousList($value, 'whereIn()/whereNotIn()');
            $clone = clone $this;
            $clone->wheres[] = ['type' => WhereType::Basic, 'column' => $column, 'operator' => $operator, 'value' => $value, 'boolean' => $boolean];
            array_push($clone->bindings[BindingCategory::Where->value], ...array_filter(
                array_values($value),
                fn ($item) => !$item instanceof Expression && !$item instanceof ToSqlValue,
            ));
            return $clone;
        }

        if ($operator === WhereOperator::Between || $operator === WhereOperator::NotBetween) {
            // Same homogeneity contract as the IN lists above — a mixed
            // scalar/Expression pair (e.g. [new Expression('NOW()'), $end])
            // desyncs placeholders from bindings identically.
            $this->assertHomogeneousList($value, 'whereBetween()/whereNotBetween()');
            $clone = clone $this;
            $clone->wheres[] = ['type' => WhereType::Between, 'column' => $column, 'operator' => $operator, 'value' => $value, 'boolean' => $boolean];
            array_push($clone->bindings[BindingCategory::Where->value], ...array_filter(
                array_values($value),
                fn ($item) => !$item instanceof Expression && !$item instanceof ToSqlValue,
            ));
            return $clone;
        }

        if ($operator === WhereOperator::Null || $operator === WhereOperator::NotNull) {
            $clone = clone $this;
            $clone->wheres[] = ['type' => WhereType::Null, 'column' => $column, 'operator' => $operator, 'boolean' => $boolean];
            return $clone;
        }

        // A null value with a comparison operator can never match: SQL
        // `col = NULL` (and every other comparison against NULL) is UNKNOWN,
        // so the clause compiles to an unbound `= ?` and silently filters
        // everything out. The intent is always IS NULL / IS NOT NULL — say
        // so, fail fast, and name the correct method.
        if ($value === null) {
            $label = is_string($column) ? $column : $column->value;
            throw new \InvalidArgumentException(
                "where('{$label}', '{$operator->value}', null) can never match — SQL comparisons against NULL"
                . ' are UNKNOWN. Use whereNull(\'' . $label . '\') or whereNotNull(\'' . $label . '\') instead.'
            );
        }

        $clone = clone $this;
        $clone->wheres[] = ['type' => WhereType::Basic, 'column' => $column, 'operator' => $operator, 'value' => $value, 'boolean' => $boolean];
        if (!$value instanceof Expression && !$value instanceof ToSqlValue) {
            $clone->bindings[BindingCategory::Where->value][] = $value;
        }
        return $clone;
    }

    /**
     * Add a raw SQL where clause — the parameterized raw-condition path.
     *
     * The SQL is spliced verbatim by design (it is an expression, not a
     * column reference, so there is nothing to validate); its bindings are
     * POSITIONAL — the column each belongs to is not knowable, so no
     * per-column cast applies. The SAFETY mechanism is the binding: values
     * ride as parameters, never quoted into the statement — only the
     * scaffolding (e.g. `lower(email) = ?`) is raw. Keep user input out of
     * the `$sql` string itself; put it in `$bindings`.
     *
     * This method is itself the greppable marker: every verbatim SQL
     * fragment in an app is found by searching for `whereRaw`.
     *
     * @param string $sql The raw SQL condition (e.g. `lower(email) = ?`).
     * @param array<int, mixed> $bindings The values to bind into the condition.
     * @param WhereBoolean $boolean The boolean connector.
     * @return static A new builder with the clause appended; the original is unchanged.
     */
    public function whereRaw(string $sql, array $bindings = [], WhereBoolean $boolean = WhereBoolean::And): static
    {
        $clone = clone $this;
        $clone->wheres[] = ['type' => WhereType::Raw, 'sql' => $sql, 'boolean' => $boolean];
        array_push($clone->bindings[BindingCategory::Where->value], ...$bindings);
        return $clone;
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
     * @return static A new builder with the clause appended; the original is unchanged.
     * @throws \InvalidArgumentException When a string operator is not a valid column comparison.
     */
    public function whereColumn(string $first, ColumnOperator|string $operator = '=', string $second = '', WhereBoolean $boolean = WhereBoolean::And): static
    {
        $resolved = $operator instanceof ColumnOperator ? $operator : ColumnOperator::fromChecked($operator);
        $clone = clone $this;
        $clone->wheres[] = ['type' => WhereType::Column, 'first' => $first, 'operator' => $resolved, 'second' => $second, 'boolean' => $boolean];
        return $clone;
    }

    /**
     * Add a nested group of where clauses.
     *
     * The callback receives a {@see WhereBuilder} — the where-family ONLY:
     * a parenthesized group is a filter, not a query, so it cannot JOIN,
     * select, order, or page.
     *
     * The callback MUST RETURN the (possibly modified) WhereBuilder — the
     * returned builder's clauses become the group. A callback that mutates
     * the argument without returning it adds NOTHING (the discarded result
     * is a no-op — builders are immutable, so mutation is impossible by
     * construction):
     *
     *     ->whereNested(fn (WhereBuilder $q) => $q->where('active', '=', 1))
     *
     * The group is stored as an immutable SNAPSHOT of the returned
     * builder's clause list — compiled SQL is fixed at group-close time;
     * an escaped reference can never alter it.
     *
     * @param callable(WhereBuilder): WhereBuilder $callback Receives the
     *        group's where-family facade and RETURNS the constrained group.
     * @param WhereBoolean $boolean The boolean connector.
     * @return static A new builder with the group appended; the original is unchanged.
     */
    final public function whereNested(callable $callback, WhereBoolean $boolean = WhereBoolean::And): static
    {
        $nested = $this->newNestedBuilder();
        $group = $callback(new WhereBuilder($nested));

        // Runtime boundary: the callable signature is PHPDoc-only, so a
        // mutation-style callback (mutates the argument, returns nothing)
        // hands back NULL here. PHPStan cannot see this — it trusts the
        // declared signature and would flag the instanceof as always-true —
        // but at runtime it is the difference between a clear declaration
        // error and a bare "call to a member function on null". The ignore
        // is scoped and justified: the check is redundant FOR TYPED
        // CALLERS, which is exactly who PHPStan analyzes.
        /** @phpstan-ignore instanceof.alwaysTrue (runtime boundary: untyped callbacks may return null — see the project convention on scoped ignores) */
        if (!$group instanceof WhereBuilder) {
            throw new \InvalidArgumentException(
                'whereNested() callback must RETURN the WhereBuilder it received '
                . '(the builder is immutable — mutating the argument without returning it adds nothing).'
            );
        }

        $groupQuery = $group->getNestedQuery();

        // An empty group is a declaration bug, not a neutral filter: on SQL
        // it compiles to degenerate `()` SQL, and evaluators that walk the
        // clause list would read past its end. Fail fast at declaration.
        if ($groupQuery->getWheres() === []) {
            throw new \InvalidArgumentException(
                'A nested where group must contain at least one clause; the callback added none.'
            );
        }

        $clone = clone $this;
        $clone->wheres[] = ['type' => WhereType::Nested, 'group' => new WhereGroup($groupQuery->getWheres()), 'boolean' => $boolean];
        array_push($clone->bindings[BindingCategory::Where->value], ...$groupQuery->getBindings([BindingCategory::Where]));
        return $clone;
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
     * @return static A new builder with the groups appended; the original is unchanged.
     */
    public function groupBy(string|array $columns): static
    {
        $clone = clone $this;
        $clone->groups = array_merge($this->groups, is_array($columns) ? $columns : func_get_args());
        return $clone;
    }

    /**
     * Filter groups after aggregation (HAVING).
     *
     * The compared left-hand side may be a plain column, an
     * {@see Expression}, or an {@see Aggregate} — the typed form of the old
     * `having('count(*)', ...)` string.
     *
     * @param string|Expression|Aggregate $column The column (or aggregate) to compare.
     * @param WhereOperator|string $operator The comparison operator.
     * @param mixed $value The value to compare against.
     * @return static A new builder with the having appended; the original is unchanged.
     */
    public function having(string|Expression|Aggregate $column, WhereOperator|string $operator, mixed $value): static
    {
        $operator = $operator instanceof WhereOperator ? $operator : WhereOperator::from(strtoupper($operator));
        $clone = clone $this;
        $clone->havings[] = ['type' => WhereType::Basic, 'column' => $column, 'operator' => $operator, 'value' => $value];
        if ($value !== null && !$value instanceof Expression && !$value instanceof ToSqlValue) {
            $clone->bindings[BindingCategory::Having->value][] = $value;
        }
        return $clone;
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
     * A raw SQL fragment is expressed by passing an {@see Expression} as the
     * column — the explicit `new Expression(...)` is the only raw-SQL
     * ordering path, so every verbatim splice is greppable and the caller
     * owns its safety (never pass user-supplied content).
     *
     * @param string|Expression $column The column to order by — or a raw
     *        SQL fragment wrapped in an Expression (e.g.
     *        `new Expression('FIELD(status, \'new\', \'done\')')`).
     * @param SortDirection|string $direction `ASC` or `DESC` (case-insensitive string).
     * @return static A new builder with the order appended; the original is unchanged.
     * @throws \InvalidArgumentException When the direction is not `ASC` or `DESC`.
     */
    public function orderBy(string|Expression $column, SortDirection|string $direction = SortDirection::Asc): static
    {
        $normalized = $direction instanceof SortDirection
            ? $direction
            : SortDirection::fromChecked($direction);
        $clone = clone $this;
        $clone->orders[] = ['column' => $column, 'direction' => $normalized];
        return $clone;
    }

    /**
     * Set the maximum number of rows to return.
     *
     * @param int $limit The row limit.
     * @return static A new builder with the limit set; the original is unchanged.
     */
    public function limit(int $limit): static
    {
        $clone = clone $this;
        $clone->limit = $limit;
        return $clone;
    }

    /**
     * Set the number of rows to skip.
     *
     * @param int $offset The row offset.
     * @return static A new builder with the offset set; the original is unchanged.
     */
    public function offset(int $offset): static
    {
        $clone = clone $this;
        $clone->offset = $offset;
        return $clone;
    }

    // ---- Unions ----

    /**
     * Append a union to the query.
     *
     * The sub-builder's bindings are SNAPSHOT onto the returned clone
     * immediately, in union order — a builder is a value, so the
     * sub-builder is expected to be final when union() is called. No
     * compile pass ever writes to a builder: compilation stays a pure
     * snapshot. (The historical late-binding capture — bindings added to
     * the sub-builder AFTER union() — was a mutation-era behavior; under
     * value semantics the captured snapshot IS the builder the union
     * references.)
     *
     * @param QueryBuilder $query The query to union with.
     * @param bool $all Whether to use `UNION ALL`.
     * @return static A new builder with the union appended; the original is unchanged.
     */
    final public function union(QueryBuilder $query, bool $all = false): static
    {
        $clone = clone $this;
        $clone->unions[] = ['query' => $query, 'all' => $all];
        // Eager capture: APPEND, because unions are a SEQUENCE — each
        // union() call adds its sub-builder's bindings after the previous
        // ones, matching the compiled order of the UNION clauses.
        array_push($clone->bindings[BindingCategory::Union->value], ...$query->getBindings());
        return $clone;
    }

    // ---- Locks ----

    /**
     * Lock the selected rows for update.
     *
     * @return static A new builder with the lock set; the original is unchanged.
     */
    final public function lockForUpdate(): static
    {
        $clone = clone $this;
        $clone->lock = LockType::Update;
        return $clone;
    }

    /**
     * Lock the selected rows in shared mode.
     *
     * @return static A new builder with the lock set; the original is unchanged.
     */
    final public function sharedLock(): static
    {
        $clone = clone $this;
        $clone->lock = LockType::Shared;
        return $clone;
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
     * SIDE-EFFECT-FREE: the internal `limit(1)` runs on a new builder
     * (builders are immutable), so this builder's own limit is untouched —
     * safe to share a builder between a first() read and a later full read.
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
     * An {@see Aggregate} argument selects the aggregate under the same
     * stable alias — the typed scalar-aggregate read.
     *
     * @param string|Aggregate $column The column to read — or an aggregate.
     * @return mixed The column value, or null when no row matches.
     */
    public function value(string|Aggregate $column): mixed
    {
        if ($column instanceof Aggregate) {
            // The aggregate rides the stable `radiant_scalar` alias; the
            // row fetch reads it back by name. select() is immutable — it
            // returns a new builder, leaving this one's columns untouched.
            return $this
                ->select(new Aggregate($column->function, $column->column, 'radiant_scalar'))
                ->first()
                ->radiant_scalar ?? null;
        }

        // The columnar fetch: the connection reads the single column
        // directly (no per-row object), positionally — no alias read-back.
        // select() is immutable — the scoped select leaves this builder
        // untouched, so no explicit clone is needed.
        return $this->connection->selectColumn($this->select($this->scalarColumn($column))->limit(1))->first();
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
        // The columnar fetch: values come back positionally, one per row —
        // no per-row object materialized, no alias read per row. select()
        // is immutable — the scoped select leaves this builder untouched.
        return $this->connection->selectColumn($this->select($this->scalarColumn($column)));
    }

    /**
     * Resolve a column into the SQL to select.
     *
     * A trailing `as alias` is STRIPPED — the scalar reads fetch the column
     * positionally ({@see ConnectionInterface::selectColumn()}), so the
     * result header is never read by name. Stripping also keeps the select
     * a bare expression, which backends that project by field name (the CSV
     * connection) can resolve directly. The alias a caller wrote is
     * documentation, not a read-back key.
     *
     * @param string $column The column expression.
     * @return string The bare select expression.
     */
    protected function scalarColumn(string $column): string
    {
        return (string) preg_replace('/\s+as\s+[`"]?[a-z_][a-z0-9_]*[`"]?$/i', '', $column);
    }

    // ---- Aggregates are just select fields (built on select()) ----

    /**
     * Count the matching rows.
     *
     * @return int The row count.
     */
    public function count(): int
    {
        return (int) $this->value(Aggregate::count());
    }

    /**
     * Whether any matching rows exist.
     *
     * A limit-1 probe rather than a COUNT: the backend stops at the first
     * matching row instead of counting every one, and the builder's select
     * is never rewritten to an aggregate. `first()` applies the limit
     * itself, so this stays backend-agnostic (SQL and the CSV evaluator
     * both short-circuit on the first match).
     *
     * @return bool True when at least one row matches.
     */
    final public function exists(): bool
    {
        return $this->first() !== null;
    }

    /**
     * The maximum value of a column.
     *
     * @param string $column The column to aggregate.
     * @return mixed The maximum value.
     */
    public function max(string $column): mixed
    {
        return $this->value(Aggregate::max($column));
    }

    /**
     * The minimum value of a column.
     *
     * @param string $column The column to aggregate.
     * @return mixed The minimum value.
     */
    public function min(string $column): mixed
    {
        return $this->value(Aggregate::min($column));
    }

    /**
     * The sum of a column's values.
     *
     * @param string $column The column to aggregate.
     * @return mixed The sum.
     */
    public function sum(string $column): mixed
    {
        return $this->value(Aggregate::sum($column));
    }

    /**
     * The average of a column's values.
     *
     * @param string $column The column to aggregate.
     * @return mixed The average.
     */
    public function avg(string $column): mixed
    {
        return $this->value(Aggregate::avg($column));
    }

    /**
     * Multiple aggregates in one query.
     *
     * The aggregate's own ALIAS names its result column — one way to name
     * a column, no override layer:
     *
     *     $db->table('orders')->aggregates(
     *         Aggregate::count('*', 'total'),
     *         Aggregate::max('price', 'top'),
     *     ); // ['total' => ..., 'top' => ...]
     *
     * @param Aggregate ...$aggregates The aggregates to compute.
     * @return \stdClass The values as properties, keyed by each aggregate's
     *         result key (the explicit alias when given, else the derived
     *         call text). Property access on an unknown key throws — no
     *         silent null for a typo'd alias.
     */
    public function aggregates(Aggregate ...$aggregates): \stdClass
    {
        // The aggregate select rides select() — immutable, so this builder's
        // own column list is untouched.
        return $this->select(...$aggregates)->first()
            ?? throw new \LogicException(
                'aggregates() cannot run — the query matched no rows to aggregate (this '
                . 'indicates a connection that returned an empty first() without an aggregate row).'
            );
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
    final public function delete(): int
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
    final public function getBindings(?array $categories = null): array
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
     * Declare the PK column so insertGetId() can return it (RETURNING / lastInsertId).
     *
     * @param string $column The primary key column.
     * @param bool $autoIncrement Whether the key is server-generated. A
     *        caller-assigned (non-auto-increment) key declares itself here:
     *        the connection's lastInsertId() fallback is NOT meaningful for
     *        it, and insertGetId() fails fast when one is attempted.
     * @return static A new builder with the id column declared; the original is unchanged.
     */
    final public function insertIdColumn(string $column, bool $autoIncrement = true): static
    {
        $clone = clone $this;
        $clone->insertIdColumn = $column;
        $clone->insertIdAutoIncrement = $autoIncrement;
        return $clone;
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
     * Assert the query uses ONLY the given features, or fail fast.
     *
     * The pre-flight gate for feature-limited backends and callers — the
     * named features are the SUPPORTED set: a query that uses ANYTHING
     * outside it is rejected. One missing case cannot sneak a feature
     * through the way a forgotten entry in a forbidden-set could:
     *
     *     $conn->assertSupports(SqlFeature::Aggregates);
     *     // plain wheres + aggregates pass; a query with a JOIN throws
     *
     * A connection implementation calls this with everything it can
     * execute ({@see \BlueprintAU\Radiant\Database\Connections\CsvConnection::select()}
     * does exactly that), so rejection is the identical check a caller's
     * pre-flight would run.
     *
     * @param SqlFeature ...$features The features the query may use.
     * @return static The builder (chainable).
     * @throws \BlueprintAU\Radiant\Database\Exceptions\UnsupportedFeatureException
     *         When the query uses any feature outside the supported set —
     *         the message lists every violated feature.
     */
    final public function assertSupports(SqlFeature ...$features): static
    {
        $used = SqlFeature::usedBy($this);
        $violated = array_values(array_filter(
            $used,
            fn(SqlFeature $feature) => !in_array($feature, $features, true),
        ));

        if ($violated !== []) {
            $names = implode(', ', array_map(fn(SqlFeature $f) => $f->value, $violated));
            throw new \BlueprintAU\Radiant\Database\Exceptions\UnsupportedFeatureException(
                "This query uses feature(s) [{$names}] outside the supported set."
            );
        }

        return $this;
    }

    /**
     * The columns to select.
     *
     * @return list<string|Expression|Aggregate>
     */
    final public function getColumns(): array
    {
        return $this->columns;
    }

    /**
     * Whether the select is distinct.
     *
     * @return bool True when distinct.
     */
    final public function isDistinct(): bool
    {
        return $this->distinct;
    }

    /**
     * The from clause — a table name or a subquery builder.
     *
     * @return string|QueryBuilder
     */
    final public function getFrom(): string|QueryBuilder
    {
        return $this->from;
    }

    /**
     * The alias of the from subquery, when `fromSub()` was used.
     *
     * @return string|null The alias, or null when there is none.
     */
    final public function getFromAlias(): ?string
    {
        return $this->fromAlias;
    }

    /**
     * The joins to apply.
     *
     * @return list<array{type: JoinType, table: string, wheres: list<array{type: WhereType::Column, first: string, operator: ColumnOperator, second: string, boolean: WhereBoolean}>}>
     */
    final public function getJoins(): array
    {
        return $this->joins;
    }

    /**
     * The where clauses.
     *
     * A discriminated union keyed by {@see WhereType} — exhaustively match on
     * `type` to handle every shape. Nested clauses carry a SNAPSHOT of their
     * group's clause list (fixed at group-close time); recurse via
     * `compileWhereGroup()` on the snapshot.
     *
     * @return list<WhereClause>
     */
    final public function getWheres(): array
    {
        return $this->wheres;
    }

    /**
     * Mark the most recent where clause as the soft-delete scope clause.
     *
     * The marker lets {@see \BlueprintAU\Radiant\ModelQueryBuilder::withTrashed()}
     * find and remove the clause BY MARKER, not positional index —
     * index-independent removal is robust under the builder's immutability.
     * Constructor-time only: the soft-delete scope is applied exactly once,
     * when the builder is built, so the write targets the instance being
     * constructed (no clone semantics apply yet).
     *
     * @return void
     * @throws \LogicException When the builder has no where clauses.
     */
    protected function markLastWhereSoftDelete(): void
    {
        if ($this->wheres === []) {
            throw new \LogicException('Cannot mark the soft-delete scope: the builder has no where clauses.');
        }

        $last = count($this->wheres) - 1;
        $this->wheres[$last]['softDelete'] = true;
    }

    /**
     * The group-by columns.
     *
     * @return list<string>
     */
    final public function getGroups(): array
    {
        return $this->groups;
    }

    /**
     * The having clauses.
     *
     * @return list<array{type: WhereType::Basic, column: string|Expression|Aggregate, operator: WhereOperator, value: mixed}>
     */
    final public function getHavings(): array
    {
        return $this->havings;
    }

    /**
     * The order-by clauses.
     *
     * Part of the contract consumed by both {@see \BlueprintAU\Radiant\Database\Grammars\Grammar}
     * (SQL compilation) and custom `ConnectionInterface` implementations
     * (non-SQL execution). `direction` is the {@see SortDirection} enum —
     * never a bare string. An {@see Expression} column is spliced verbatim
     * with its validated direction appended after it.
     *
     * @return list<array{column: string|Expression, direction: SortDirection|null}>
     */
    final public function getOrders(): array
    {
        return $this->orders;
    }

    /**
     * The unions to append.
     *
     * @return list<array{query: QueryBuilder, all: bool}>
     */
    final public function getUnions(): array
    {
        return $this->unions;
    }

    /**
     * The row lock to apply, or null for none.
     *
     * @return LockType|null The lock, or null when there is none.
     */
    final public function getLock(): ?LockType
    {
        return $this->lock;
    }

    /**
     * The maximum number of rows to return.
     *
     * @return int|null The limit, or null when there is none.
     */
    final public function getLimit(): ?int
    {
        return $this->limit;
    }

    /**
     * The number of rows to skip.
     *
     * @return int|null The offset, or null when there is none.
     */
    final public function getOffset(): ?int
    {
        return $this->offset;
    }

    /**
     * The PK column to return on insert, if known.
     *
     * @return string|null The PK column, or null when unknown.
     */
    final public function getInsertIdColumn(): ?string
    {
        return $this->insertIdColumn;
    }

    /**
     * Whether the declared insert-id column is auto-increment.
     *
     * @return bool True when the key is server-generated; false when a key
     *         is declared but caller-supplied; false when none is declared.
     */
    final public function isInsertIdAutoIncrement(): bool
    {
        return $this->insertIdAutoIncrement ?? false;
    }
}
