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
 * you want, then run it with `get()`. The same builder works against any
 * backend: SQL databases compile it to SQL, while a CSV connection applies
 * the filters directly in PHP.
 *
 * @phpstan-type WhereClause array{type: WhereType::Basic, column: string|Expression, operator: WhereOperator, value: mixed, boolean: WhereBoolean, traitScope?: class-string} | array{type: WhereType::Between, column: string|Expression, operator: WhereOperator, value: array{0: mixed, 1: mixed}, boolean: WhereBoolean, traitScope?: class-string} | array{type: WhereType::Null, column: string|Expression, operator: WhereOperator, boolean: WhereBoolean, traitScope?: class-string} | array{type: WhereType::Raw, sql: string, boolean: WhereBoolean, traitScope?: class-string} | array{type: WhereType::Column, first: string, operator: ColumnOperator, second: string, boolean: WhereBoolean, traitScope?: class-string} | array{type: WhereType::Nested, group: WhereGroup, boolean: WhereBoolean}
 * @phpstan-type BindingValue string|int|float|bool|null|\DateTimeInterface|Expression|ToSqlValue
 *
 * @see \BlueprintAU\Radiant\Database\Connections\ConnectionInterface
 */
class QueryBuilder
{
    use FiltersWhere;

    /**
     * The internal result alias for a grouped aggregate's value column.
     *
     * The same convention as the scalar reads: a stable alias keeps the
     * value readable regardless of how each driver names an unaliased
     * aggregate column.
     */
    protected const AGGREGATE_ALIAS = 'radiant_aggregate';

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
     * @var list<array{type: WhereType::Basic, column: string|Expression|Aggregate, operator: WhereOperator, value: BindingValue}>
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
     * Whether the insert-id column is auto-increment (server-generated).
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
     * @param  ConnectionInterface  $connection
     * @param  string  $table
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
     * Calling with no arguments resets to the `['*']` default select.
     *
     * @param  string|Expression|Aggregate  ...$columns
     * @return static
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
     * @return static
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
     * @param  QueryBuilder  $query
     * @param  string  $alias
     * @return static
     *
     * @throws \LogicException
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
     * Add an inner join to the query.
     *
     * @param  string  $table
     * @param  string  $first
     * @param  ColumnOperator|string  $operator
     * @param  string  $second
     * @return static
     */
    public function join(string $table, string $first, ColumnOperator|string $operator = '=', string $second = ''): static
    {
        return $this->addJoin(JoinType::Inner, $table, $first, $operator, $second);
    }

    /**
     * Add a left join to the query.
     *
     * @param  string  $table
     * @param  string  $first
     * @param  ColumnOperator|string  $operator
     * @param  string  $second
     * @return static
     */
    public function leftJoin(string $table, string $first, ColumnOperator|string $operator = '=', string $second = ''): static
    {
        return $this->addJoin(JoinType::Left, $table, $first, $operator, $second);
    }

    /**
     * Add a right join to the query.
     *
     * @param  string  $table
     * @param  string  $first
     * @param  ColumnOperator|string  $operator
     * @param  string  $second
     * @return static
     */
    public function rightJoin(string $table, string $first, ColumnOperator|string $operator = '=', string $second = ''): static
    {
        return $this->addJoin(JoinType::Right, $table, $first, $operator, $second);
    }

    /**
     * Add a cross join to the query.
     *
     * @param  string  $table
     * @return static
     */
    final public function crossJoin(string $table): static
    {
        return $this->addJoin(JoinType::Cross, $table, '', '=', '');
    }

    /**
     * Add an additional ON condition to the most recent join.
     *
     * Conditions are strictly column-to-column — values belong in where()
     * after the join, not in the ON clause.
     *
     * @param  string  $first
     * @param  ColumnOperator|string  $operator
     * @param  string  $second
     * @return static
     *
     * @throws \LogicException
     * @throws \InvalidArgumentException
     */
    public function on(string $first, ColumnOperator|string $operator = '=', string $second = ''): static
    {
        return $this->addOn(WhereBoolean::And, $first, $operator, $second);
    }

    /**
     * Add an additional OR-connected ON condition to the most recent join.
     *
     * @param  string  $first
     * @param  ColumnOperator|string  $operator
     * @param  string  $second
     * @return static
     *
     * @throws \LogicException
     * @throws \InvalidArgumentException
     */
    public function orOn(string $first, ColumnOperator|string $operator = '=', string $second = ''): static
    {
        return $this->addOn(WhereBoolean::Or, $first, $operator, $second);
    }

    /**
     * Add an ON condition to the last added join.
     *
     * @param  WhereBoolean  $boolean
     * @param  string  $first
     * @param  ColumnOperator|string  $operator
     * @param  string  $second
     * @return static
     *
     * @throws \LogicException
     * @throws \InvalidArgumentException
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
     * Add a join clause to the query.
     *
     * @param  JoinType  $type
     * @param  string  $table
     * @param  string  $first
     * @param  ColumnOperator|string  $operator
     * @param  string  $second
     * @return static
     *
     * @throws \InvalidArgumentException
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
     * (Expression/ToSqlValue).
     *
     * A mixed list desyncs placeholders from bindings on the IN/BETWEEN
     * compile shapes.
     *
     * @param  array<int, mixed>  $value
     * @param  string  $method
     * @return void
     * @throws \InvalidArgumentException
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
     * Add a where clause to the query.
     *
     * The value is polymorphic by design: a bindable scalar, a list (for
     * IN/NOT IN/BETWEEN), or a composite column => value key map. The
     * operator branches below validate each shape at declaration.
     *
     * @param  string|Expression  $column
     * @param  WhereOperator|string  $operator
     * @param  mixed  $value
     * @param  WhereBoolean  $boolean
     * @return static
     *
     * @throws \InvalidArgumentException
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
                $value,
                fn ($item) => !$item instanceof Expression && !$item instanceof ToSqlValue,
            ));
            return $clone;
        }

        if ($operator === WhereOperator::Between || $operator === WhereOperator::NotBetween) {
            // A scalar (or a list of the wrong arity) cannot compile to a
            // BETWEEN — fail fast at declaration with the shape named.
            if (!is_array($value) || count($value) !== 2) {
                throw new \InvalidArgumentException(
                    'whereBetween()/whereNotBetween() require a two-value [min, max] array; got '
                        . get_debug_type($value) . '.'
                );
            }

            // Same homogeneity contract as the IN lists above — a mixed
            // scalar/Expression pair (e.g. [new Expression('NOW()'), $end])
            // desyncs placeholders from bindings identically.
            $this->assertHomogeneousList($value, 'whereBetween()/whereNotBetween()');
            $clone = clone $this;
            $clone->wheres[] = ['type' => WhereType::Between, 'column' => $column, 'operator' => $operator, 'value' => $value, 'boolean' => $boolean];
            array_push($clone->bindings[BindingCategory::Where->value], ...array_filter(
                $value,
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

        // A list with a comparison operator is a declaration error — lists
        // belong to whereIn()/whereNotIn() or whereBetween()/whereNotBetween(),
        // which own the arity and homogeneity validation. Reaching here with
        // a list would bind the array wholesale (the bind guard rejects it
        // with a driver-level error instead of a declaration-level one).
        if (is_array($value)) {
            $label = is_string($column) ? $column : $column->value;
            throw new \InvalidArgumentException(
                "where('{$label}', '{$operator->value}', list) — list values belong to "
                . 'whereIn()/whereNotIn() or whereBetween()/whereNotBetween().'
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
     * Add a raw where clause to the query.
     *
     * Keep user input out of the `$sql` string itself — put it in
     * `$bindings`.
     *
     * @param  string  $sql
     * @param  array<int, mixed>  $bindings
     * @param  WhereBoolean  $boolean
     * @return static
     */
    public function whereRaw(string $sql, array $bindings = [], WhereBoolean $boolean = WhereBoolean::And): static
    {
        $clone = clone $this;
        $clone->wheres[] = ['type' => WhereType::Raw, 'sql' => $sql, 'boolean' => $boolean];
        array_push($clone->bindings[BindingCategory::Where->value], ...$bindings);
        return $clone;
    }

    /**
     * Add a where clause comparing two columns to the query.
     *
     * @param  string  $first
     * @param  ColumnOperator|string  $operator
     * @param  string  $second
     * @param  WhereBoolean  $boolean
     * @return static
     *
     * @throws \InvalidArgumentException
     */
    public function whereColumn(string $first, ColumnOperator|string $operator = '=', string $second = '', WhereBoolean $boolean = WhereBoolean::And): static
    {
        $resolved = $operator instanceof ColumnOperator ? $operator : ColumnOperator::fromChecked($operator);
        $clone = clone $this;
        $clone->wheres[] = ['type' => WhereType::Column, 'first' => $first, 'operator' => $resolved, 'second' => $second, 'boolean' => $boolean];
        return $clone;
    }

    /**
     * Add a nested group of where clauses to the query.
     *
     * The callback receives a {@see WhereBuilder} — the where-family only —
     * and must return it:
     *
     *     ->whereNested(fn (WhereBuilder $q) => $q->where('active', '=', 1))
     *
     * @param  callable(WhereBuilder): WhereBuilder  $callback
     * @param  WhereBoolean  $boolean
     * @return static
     *
     * @throws \InvalidArgumentException
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
     * Get the builder a nested where group stores its clauses on.
     *
     * @return self
     */
    protected function newNestedBuilder(): self
    {
        return new self($this->connection, $this->table);
    }

    // ---- Grouping / Having ----

    /**
     * Add a group by clause to the query.
     *
     * @param  string|array<int, string>  $columns
     * @return static
     */
    public function groupBy(string|array $columns): static
    {
        $clone = clone $this;
        $clone->groups = array_merge($this->groups, is_array($columns) ? $columns : func_get_args());
        return $clone;
    }

    /**
     * Add a having clause to the query.
     *
     * @param  string|Expression|Aggregate  $column
     * @param  WhereOperator|string  $operator
     * @param  mixed  $value
     * @return static
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
     * Add an order by clause to the query.
     *
     * @param  string|Expression  $column
     * @param  SortDirection|string  $direction
     * @return static
     *
     * @throws \InvalidArgumentException
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
     * Set the "limit" value of the query.
     *
     * @param  int  $limit
     * @return static
     */
    public function limit(int $limit): static
    {
        $clone = clone $this;
        $clone->limit = $limit;
        return $clone;
    }

    /**
     * Set the "offset" value of the query.
     *
     * @param  int  $offset
     * @return static
     */
    public function offset(int $offset): static
    {
        $clone = clone $this;
        $clone->offset = $offset;
        return $clone;
    }

    // ---- Unions ----

    /**
     * Add a union to the query.
     *
     * @param  QueryBuilder  $query
     * @param  bool  $all  Whether to use "UNION ALL".
     * @return static
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
     * @return static
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
     * @return static
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
     * @return Collection<int, \stdClass>
     */
    public function get(): Collection
    {
        return $this->connection->select($this);
    }

    /**
     * Run the query and yield each matching row as it arrives.
     *
     * Consume the generator fully (or let it be garbage collected) before
     * running another query on the connection.
     *
     * @return \Generator<int, \stdClass>
     */
    public function cursor(): \Generator
    {
        return $this->connection->cursor($this);
    }

    /**
     * Run the query and return the first matching row.
     *
     * @return \stdClass|null
     */
    public function first(): ?object
    {
        return $this->limit(1)->get()->first();
    }

    /**
     * The value of a single column from the first row.
     *
     * The column is selected under a stable alias so the result can be read
     * back by name regardless of how the dialect names the raw expression.
     *
     * @param  string|Aggregate  $column
     * @return mixed
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
     * @param  string  $column
     * @return Collection<int, mixed>
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
     * A trailing `as alias` is stripped — the scalar reads fetch the column
     * positionally, so the result header is never read by name.
     *
     * @param  string  $column
     * @return string
     */
    protected function scalarColumn(string $column): string
    {
        return (string) preg_replace('/\s+as\s+[`"]?[a-z_][a-z0-9_]*[`"]?$/i', '', $column);
    }

    // ---- Aggregates are just select fields (built on select()) ----

    /**
     * Count the matching rows.
     *
     * @return int
     */
    public function count(): int
    {
        return (int) $this->value(Aggregate::count());
    }

    /**
     * Whether any matching rows exist.
     *
     * A limit-1 probe rather than a COUNT: the backend stops at the first
     * matching row instead of counting every one.
     *
     * @return bool
     */
    final public function exists(): bool
    {
        return $this->first() !== null;
    }

    /**
     * The maximum value of a column.
     *
     * @param  string  $column
     * @return mixed
     */
    public function max(string $column): mixed
    {
        return $this->value(Aggregate::max($column));
    }

    /**
     * The minimum value of a column.
     *
     * @param  string  $column
     * @return mixed
     */
    public function min(string $column): mixed
    {
        return $this->value(Aggregate::min($column));
    }

    /**
     * The sum of a column's values.
     *
     * @param  string  $column
     * @return mixed
     */
    public function sum(string $column): mixed
    {
        return $this->value(Aggregate::sum($column));
    }

    /**
     * The average of a column's values.
     *
     * @param  string  $column
     * @return mixed
     */
    public function avg(string $column): mixed
    {
        return $this->value(Aggregate::avg($column));
    }

    /**
     * Multiple aggregates in one query.
     *
     * The aggregate's own alias names its result column:
     *
     *     $db->table('orders')->aggregates(
     *         Aggregate::count('*', 'total'),
     *         Aggregate::max('price', 'top'),
     *     );
     *
     * @param  Aggregate  ...$aggregates
     * @return \stdClass
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

    /**
     * Run one aggregate per group of the matching rows — a grouped
     * aggregate in a single query.
     *
     * The result is keyed by the group column's value, so the aggregate's
     * own alias is ignored here (it matters only for the multi-aggregate
     * row shape of aggregates()). A raw table has no casts, so values come
     * back as the driver delivered them — except `count`, which is always
     * an int.
     *
     * @param  Aggregate  $aggregate  The aggregate to compute per group.
     * @param  string  $groupBy  The column whose values key the result.
     * @return Collection<string, mixed>
     */
    public function aggregateBy(Aggregate $aggregate, string $groupBy): Collection
    {
        $rows = $this->select($groupBy, new Aggregate($aggregate->function, $aggregate->column, self::AGGREGATE_ALIAS))
            ->groupBy($groupBy)
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $key = (string) $row->{$groupBy};
            $raw = $row->{self::AGGREGATE_ALIAS};

            $out[$key] = $aggregate->function === 'count'
                ? (int) $raw
                : $raw;
        }

        /** @var Collection<string, mixed> */
        return Collection::make($out);
    }

    /**
     * Count the matching rows per group of a column — in a single query.
     *
     * The result is keyed by the group column's value with int counts.
     *
     * The optional seed lists group values that must appear even when the
     * database has no rows for them — each seeded key absent from the
     * result becomes 0. The seed is ADDITIVE: database rows always win,
     * and group values found in the data but missing from the seed still
     * appear. (Only counts can be seeded — an absent group has no honest
     * min, max, or average.)
     *
     * @param  string  $column  The column whose values key the result.
     * @param  list<int|string>|null  $seed  Group values guaranteed to appear (0 when absent).
     * @return Collection<string, int>
     */
    public function countBy(string $column, ?array $seed = null): Collection
    {
        /** @var Collection<string, int> $counts */
        $counts = $this->aggregateBy(Aggregate::count('*'), $column);

        if ($seed !== null) {
            $out = $counts->all();

            foreach ($seed as $value) {
                $key = (string) $value;
                $out[$key] ??= 0;
            }

            /** @var Collection<string, int> */
            return Collection::make($out);
        }

        return $counts;
    }

    // ---- Writes ----

    /**
     * Insert one or more rows.
     *
     * @param  array<string, mixed>|list<array<string, mixed>>  $values
     * @return int
     */
    public function insert(array $values): int
    {
        return $this->connection->insert($this, $values);
    }

    /**
     * Insert a single row and return the generated id.
     *
     * @param  array<string, mixed>  $values
     * @return string|int|null
     */
    public function insertGetId(array $values): string|int|null
    {
        return $this->connection->insertGetId($this, $values);
    }

    /**
     * Update the rows matching the query's conditions.
     *
     * @param  array<string, mixed>  $values
     * @return int
     */
    public function update(array $values): int
    {
        return $this->connection->update($this, $values);
    }

    /**
     * Delete the rows matching the query's conditions.
     *
     * @return int
     */
    final public function delete(): int
    {
        return $this->connection->delete($this);
    }

    // ---- Bindings ----

    /**
     * Get the flattened bindings for the given categories, in canonical order.
     *
     * @param  list<BindingCategory>|null  $categories
     * @return list<BindingValue>
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
     * Declare the primary key column so insertGetId() can return it.
     *
     * @param  string  $column
     * @param  bool  $autoIncrement
     * @return static
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
     * in PHP. Shapes are defined here and imported elsewhere via
     * `@phpstan-import-type`; they must not drift between consumers.
     */

    /**
     * Assert the query uses ONLY the given features, or fail fast.
     *
     * @param  SqlFeature  ...$features
     * @return static
     * @throws \BlueprintAU\Radiant\Database\Exceptions\UnsupportedFeatureException
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
     * @return bool
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
     * @return string|null
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
     * A discriminated union keyed by {@see WhereType} — exhaustively match
     * on `type` to handle every shape.
     *
     * @return list<WhereClause>
     */
    final public function getWheres(): array
    {
        return $this->wheres;
    }

    /**
     * Mark the most recent where clause as a trait-declared scope clause.
     *
     * @param  string  $trait  The declaring trait's class-string.
     * @return void
     * @throws \LogicException
     */
    protected function markLastWhereTraitScope(string $trait): void
    {
        if ($this->wheres === []) {
            throw new \LogicException('Cannot mark the trait scope: the builder has no where clauses.');
        }

        $last = count($this->wheres) - 1;
        /** @phpstan-ignore assign.propertyType (the marker key is only meaningful on the clause arms that carry scopes; the union shape lists it per-arm) */
        $this->wheres[$last]['traitScope'] = $trait;
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
     * @return LockType|null
     */
    final public function getLock(): ?LockType
    {
        return $this->lock;
    }

    /**
     * The maximum number of rows to return.
     *
     * @return int|null
     */
    final public function getLimit(): ?int
    {
        return $this->limit;
    }

    /**
     * The number of rows to skip.
     *
     * @return int|null
     */
    final public function getOffset(): ?int
    {
        return $this->offset;
    }

    /**
     * The PK column to return on insert, if known.
     *
     * @return string|null
     */
    final public function getInsertIdColumn(): ?string
    {
        return $this->insertIdColumn;
    }

    /**
     * Whether the declared insert-id column is auto-increment.
     *
     * @return bool
     */
    final public function isInsertIdAutoIncrement(): bool
    {
        return $this->insertIdAutoIncrement ?? false;
    }
}
