<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Grammars;

use BlueprintAU\Radiant\Database\Concerns\ConcatenatesStatements;
use BlueprintAU\Radiant\Database\Concerns\NormalizesInsertRows;
use BlueprintAU\Radiant\Database\Concerns\QuotesLiterals;
use BlueprintAU\Radiant\Database\Exceptions\UnsupportedFeatureException;
use BlueprintAU\Radiant\Database\Query\Expression;
use BlueprintAU\Radiant\Database\Query\Enums\BindingCategory;
use BlueprintAU\Radiant\Database\Query\Enums\ColumnOperator;
use BlueprintAU\Radiant\Database\Query\Enums\JoinType;
use BlueprintAU\Radiant\Database\Query\QueryBuilder;
use BlueprintAU\Radiant\Database\Query\ToSqlValue;
use BlueprintAU\Radiant\Database\Query\Enums\WhereBoolean;
use BlueprintAU\Radiant\Database\Query\Enums\WhereOperator;
use BlueprintAU\Radiant\Database\Query\Enums\WhereType;

/**
 * Compiles query-builder state into SQL for a specific dialect.
 *
 * The base Grammar is dialect-agnostic: it owns the decomposition of a query
 * into its smallest SQL fragments (identifiers, placeholders, clauses) and
 * the composition of those fragments back up into the four statement roots —
 * {@see compileSelect()}, {@see compileInsert()}, {@see compileUpdate()}, and
 * {@see compileDelete()}. Subclasses provide the dialect specifics: identifier
 * quoting via {@see wrap()}, `RETURNING` support via {@see usesReturning()},
 * and the limit/offset/lock rendering that differs per dialect.
 *
 * **Only the four roots are public.** Every leaf and compound method is
 * protected — the Grammar's public surface is exactly the statements a
 * connection can run.
 *
 * **Bindings contract.** The Grammar emits `?` placeholders (and inline
 * literals for `Expression`/`ToSqlValue` values) but never owns the values:
 * the builder stores them per category, and the connection flattens only the
 * categories the compiled root used. Clauses are compiled in the same
 * canonical category order, so the compiled `?` order always matches the
 * flattened binding list.
 *
 * **Fail-fast dialect gating.** A feature the active dialect cannot express
 * throws {@see UnsupportedFeatureException} — never silently ignored. The
 * base Grammar throws for dialect-gated features (e.g. row locks); dialects
 * override only what they support, and dialects that don't support a feature
 * inherit the throw.
 *
 * @phpstan-import-type WhereClause from \BlueprintAU\Radiant\Database\Query\QueryBuilder
 */
abstract class Grammar
{
    use NormalizesInsertRows;
    use QuotesLiterals;
    use ConcatenatesStatements;

    /**
     * Wrap an identifier in the dialect's quote character.
     *
     * @param string $value The identifier to quote.
     * @return string The quoted identifier.
     */
    abstract protected function wrap(string $value): string;

    // ---- Identifier helpers ----

    /**
     * Wrap a possibly-qualified identifier, quoting each `.`-separated segment.
     *
     * `schema.table.column` becomes `"schema"."table"."column"` (or the
     * dialect's equivalent). A `*` segment (bare or qualified, e.g.
     * `schema.*`) passes through unquoted. An `Expression` is passed through
     * untouched.
     *
     * @param string|Expression $value The identifier to wrap.
     * @return string The wrapped identifier.
     */
    protected function wrapSegments(string|Expression $value): string
    {
        if ($value instanceof Expression) {
            return $value->value;
        }
        return implode('.', array_map(
            fn ($segment) => $segment === '*' ? '*' : $this->wrap($segment),
            explode('.', $value)
        ));
    }

    /**
     * Wrap a table reference, respecting an `as` alias.
     *
     * `users as u` becomes `"users" as "u"`.
     *
     * @param string|Expression $table The table reference.
     * @return string The wrapped table reference.
     */
    protected function wrapTable(string|Expression $table): string
    {
        if ($table instanceof Expression) {
            return $table->value;
        }
        if (preg_match('/^(.+?)(?:\s+as\s+)(.+)$/i', $table, $m)) {
            return $this->wrapSegments($m[1]) . ' AS ' . $this->wrapSegments($m[2]);
        }
        return $this->wrapSegments($table);
    }

    /**
     * Wrap a column reference, respecting an `as` alias.
     *
     * `count(*) as total` is passed through as an aggregate expression; a
     * plain column is wrapped. The aggregate's inner content is wrapped
     * segment-wise, so `sum(price)`, `count(distinct user_id)`, and
     * `count(*)` all compile correctly.
     *
     * @param string|Expression $column The column reference.
     * @return string The wrapped column reference.
     */
    protected function wrapColumn(string|Expression $column): string
    {
        if ($column instanceof Expression) {
            return $column->value;
        }
        if (preg_match('/^([a-z_]+)\((.+)\)(?:\s+as\s+(.+))?$/i', $column, $m)) {
            $inner = $m[2] === '*' ? '*' : $this->wrapAggregateInner($m[2]);
            $alias = isset($m[3]) ? ' AS ' . $this->wrapSegments($m[3]) : '';
            return "{$m[1]}({$inner}){$alias}";
        }
        if (preg_match('/^(.+?)(?:\s+as\s+)(.+)$/i', $column, $m)) {
            return $this->wrapSegments($m[1]) . ' AS ' . $this->wrapSegments($m[2]);
        }
        return $this->wrapSegments($column);
    }

    /**
     * Wrap the inner content of an aggregate expression.
     *
     * `distinct user_id` becomes `distinct "user_id"`; a plain column is
     * wrapped. The accepted inner shapes are STRICT — `*`, `distinct x`,
     * or a single identifier path (optionally `.*`). Anything else
     * (nested expressions like `coalesce(x, 0)`) FAILS CLOSED with
     * {@see UnsupportedFeatureException} instead of passing through
     * un-wrapped: the aggregate regex admits `func(<anything>)` shapes,
     * and an unwrapped inner would splice arbitrary text into the SQL.
     * Today every public caller validates upstream, so this is
     * defense-in-depth for future callers.
     *
     * @param string $inner The aggregate's inner content.
     * @return string The wrapped inner content.
     * @throws UnsupportedFeatureException When the inner content is not a
     *         strict single-identifier shape.
     */
    protected function wrapAggregateInner(string $inner): string
    {
        if (preg_match('/^distinct\s+(.+)$/i', $inner, $m)) {
            return 'DISTINCT ' . $this->wrapSegments($m[1]);
        }
        if ($inner === '*') {
            return '*';
        }

        // Strict single-identifier path: `a`, `a.b`, `a.*` — no spaces,
        // commas, parentheses, or other expression machinery. Anything
        // more complex is not a supported aggregate argument.
        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(\.[a-zA-Z_][a-zA-Z0-9_]*|\.\*)?$/', $inner) !== 1) {
            throw new UnsupportedFeatureException(
                'Aggregate arguments support only a single column (optionally schema-qualified '
                . "or `distinct col`); got [{$inner}]. Use a raw Expression for complex arguments.",
            );
        }

        return $this->wrapSegments($inner);
    }

    /**
     * Wrap a list of columns into a comma-separated list.
     *
     * @param list<string|Expression> $columns The columns to wrap.
     * @return string The comma-separated wrapped columns.
     */
    protected function columnize(array $columns): string
    {
        return implode(', ', array_map(fn ($column) => $this->wrapColumn($column), $columns));
    }

    // ---- Value helpers ----

    /**
     * Render a bindable value as a `?` placeholder, or inline a raw literal.
     *
     * Scalars, null, and `\DateTimeInterface` become `?` placeholders (the
     * value stays in the builder's bindings). An `Expression` is spliced in
     * verbatim; a `ToSqlValue` is extracted to its scalar and quoted as a
     * literal — neither ever reaches the bind guard.
     *
     * @param mixed $value The value to render.
     * @return string The placeholder or inline literal.
     */
    protected function parameter(mixed $value): string
    {
        if ($value instanceof Expression) {
            return $value->value;
        }
        if ($value instanceof ToSqlValue) {
            return $this->quoteLiteral($value->toSqlValue());
        }
        return '?';
    }

    /**
     * Render a list of values as comma-separated placeholders/literals.
     *
     * @param array<int, mixed> $values The values to render.
     * @return string The comma-separated parameters.
     */
    protected function parameterize(array $values): string
    {
        return implode(', ', array_map(fn ($value) => $this->parameter($value), $values));
    }

    // ---- Select root ----

    /**
     * Compile a select statement.
     *
     * @param QueryBuilder $builder The query to compile.
     * @return string The compiled SQL.
     */
    public function compileSelect(QueryBuilder $builder): string
    {
        $sql = $this->concatenate([
            'SELECT',
            $builder->isDistinct() ? 'DISTINCT' : '',
            $this->compileColumns($builder),
            'FROM',
            $this->compileFrom($builder),
            $this->compileJoins($builder),
            $this->compileWheres($builder),
            $this->compileGroups($builder),
            $this->compileHavings($builder),
            $this->compileOrders($builder),
            $this->compileLimit($builder),
            $this->compileOffset($builder),
            $this->compileLock($builder),
        ]);

        return $this->compileUnions($builder, $sql);
    }

    // ---- Insert root ----

    /**
     * Compile an insert statement.
     *
     * @param QueryBuilder $builder The query to compile.
     * @param array<string, mixed>|list<array<string, mixed>> $values A single
     *        row or a list of rows.
     * @param string|null $pk The PK column to return, when known.
     * @return string The compiled SQL.
     */
    public function compileInsert(QueryBuilder $builder, array $values, ?string $pk = null): string
    {
        $rows = $this->normalizeInsertRows($values);

        // An EMPTY row (a model with no set properties, a DEFAULTS-only
        // insert) cannot compile to the degenerate `INSERT INTO t () VALUES
        // ()` — invalid SQL on every dialect. The dialect owns the form via
        // {@see compileEmptyInsert()} (the SQL-standard DEFAULT VALUES by
        // default; MySQL overrides with the one-row `VALUES ()` it
        // accepts).
        if (isset($rows[0]) && $rows[0] === []) {
            return $this->withReturning(
                $this->compileEmptyInsert($builder),
                $pk,
            );
        }

        $columns = implode(', ', array_map(fn ($column) => $this->wrapSegments($column), array_keys($rows[0])));
        $placeholders = implode(', ', array_map(
            fn ($row) => '(' . implode(', ', array_fill(0, count($row), '?')) . ')',
            $rows
        ));
        $sql = "INSERT INTO {$this->wrapFromTable($builder)} ({$columns}) VALUES {$placeholders}";

        return $this->withReturning($sql, $pk);
    }

    /**
     * Compile the empty-row insert — the statement body for a row with no
     * columns.
     *
     * The SQL-standard form is `INSERT INTO t DEFAULT VALUES` (SQLite,
     * Postgres); a dialect without it overrides with the form IT accepts
     * (MySQL's one-row `VALUES ()`). This is the compile-function shape of
     * the old `supportsDefaultValues()` boolean: the dialect does not
     * ANSWER whether it supports the form — it RENDERS the form it
     * supports.
     *
     * @param QueryBuilder $builder The query to compile.
     * @return string The insert statement body (no RETURNING — the caller
     *         appends it).
     */
    protected function compileEmptyInsert(QueryBuilder $builder): string
    {
        return "INSERT INTO {$this->wrapFromTable($builder)} DEFAULT VALUES";
    }

    /**
     * Whether the dialect compiles `INSERT ... RETURNING`.
     *
     * PROTECTED on purpose — the connection never probes capabilities; it
     * calls {@see compileInsertForId()} and gets the compiled statement
     * with a `returnsKey` flag. This predicate exists only for the compile
     * path to consult.
     *
     * @return bool True when the dialect supports RETURNING.
     */
    protected function usesReturning(): bool
    {
        return false;
    }

    /**
     * Append the `RETURNING` clause to a compiled statement when the
     * dialect supports it and a PK was declared.
     *
     * @param string $sql The compiled statement body.
     * @param string|null $pk The PK column to return, when known.
     * @return string The statement, possibly with RETURNING appended.
     */
    protected function withReturning(string $sql, ?string $pk): string
    {
        if ($pk !== null && $this->usesReturning()) {
            return $sql . ' RETURNING ' . $this->wrapSegments($pk);
        }

        return $sql;
    }

    /**
     * Compile an insert whose generated key the caller needs back — the
     * compile-shaped replacement for the old public `usesReturning()`
     * probe.
     *
     * The connection calls THIS instead of asking the grammar whether
     * RETURNING exists: the result carries the compiled SQL plus whether
     * THAT statement yields the key (a `RETURNING` dialect compiles the
     * clause in; MySQL compiles without it and the connection falls back
     * to `lastInsertId()`). Support is expressed AS a compile result, not
     * a capability boolean — and the grammar stays a pure compiler: it
     * never executes what it compiles.
     *
     * @param QueryBuilder $builder The query to compile.
     * @param array<string, mixed> $values The row to insert.
     * @param string $pk The PK column whose generated value the caller needs.
     * @return array{sql: string, returnsKey: bool} The compiled statement
     *         and whether executing it yields the generated key (fetch the
     *         row) or not (read `lastInsertId()` after execution).
     */
    public function compileInsertForId(QueryBuilder $builder, array $values, string $pk): array
    {
        return [
            'sql' => $this->compileInsert($builder, $values, $pk),
            'returnsKey' => $this->usesReturning(),
        ];
    }

    // ---- Update root ----

    /**
     * Compile an update statement.
     *
     * @param QueryBuilder $builder The query to compile.
     * @param array<string, mixed> $values The columns to change and their new values.
     * @return string The compiled SQL.
     */
    public function compileUpdate(QueryBuilder $builder, array $values): string
    {
        $sets = implode(', ', array_map(
            fn ($column) => $this->wrapSegments($column) . ' = ?',
            array_keys($values)
        ));
        $sql = "UPDATE {$this->wrapFromTable($builder)} SET {$sets}";
        $wheres = $this->compileWheres($builder);
        if ($wheres !== '') {
            $sql .= ' ' . $wheres;
        }
        return $sql;
    }

    // ---- Delete root ----

    /**
     * Compile a delete statement.
     *
     * @param QueryBuilder $builder The query to compile.
     * @return string The compiled SQL.
     */
    public function compileDelete(QueryBuilder $builder): string
    {
        $sql = "DELETE FROM {$this->wrapFromTable($builder)}";
        $wheres = $this->compileWheres($builder);
        if ($wheres !== '') {
            $sql .= ' ' . $wheres;
        }
        return $sql;
    }

    // ---- Parts compilers ---

    /**
     * Compile the select column list.
     *
     * @param QueryBuilder $builder The query to compile.
     * @return string The column list.
     */
    protected function compileColumns(QueryBuilder $builder): string
    {
        $columns = $builder->getColumns();
        if ($columns === ['*']) {
            return '*';
        }
        return $this->columnize($columns);
    }

    /**
     * Compile the from clause — a table or a subquery.
     *
     * @param QueryBuilder $builder The query to compile.
     * @return string The from clause.
     */
    protected function compileFrom(QueryBuilder $builder): string
    {
        $from = $builder->getFrom();
        if ($from instanceof QueryBuilder) {
            $alias = $builder->getFromAlias();
            return '(' . $this->compileSelect($from) . ') AS ' . $this->wrapSegments($alias ?? '');
        }
        return $this->wrapTable($from);
    }

    /**
     * Compile the join clauses.
     *
     * @param QueryBuilder $builder The query to compile.
     * @return string The joins, or an empty string when there are none.
     */
    protected function compileJoins(QueryBuilder $builder): string
    {
        $segments = [];
        foreach ($builder->getJoins() as $join) {
            $keyword = match ($join['type']) {
                JoinType::Inner => 'INNER JOIN',
                JoinType::Left => 'LEFT JOIN',
                JoinType::Right => 'RIGHT JOIN',
                JoinType::Cross => 'CROSS JOIN',
            };
            $on = $this->compileJoinWheres($join['wheres']);
            $segments[] = $keyword . ' ' . $this->wrapTable($join['table']) . ($on !== '' ? ' ON ' . $on : '');
        }
        return implode(' ', $segments);
    }

    /**
     * Compile a join's on conditions.
     *
     * @param list<array{type: WhereType::Column, first: string, operator: ColumnOperator, second: string, boolean: WhereBoolean}> $wheres The join's on clauses.
     * @return string The on conditions, or an empty string when there are none.
     */
    protected function compileJoinWheres(array $wheres): string
    {
        $segments = [];
        foreach ($wheres as $i => $where) {
            $boolean = $i === 0 ? '' : strtoupper($where['boolean']->value) . ' ';
            $segments[] = $boolean . $this->wrapSegments($where['first']) . ' ' . $where['operator']->value . ' ' . $this->wrapSegments($where['second']);
        }
        return implode(' ', $segments);
    }

    /**
     * Compile the where clauses.
     *
     * @param QueryBuilder $builder The query to compile.
     * @return string The where clause, or an empty string when there are none.
     */
    protected function compileWheres(QueryBuilder $builder): string
    {
        $wheres = $builder->getWheres();
        if ($wheres === []) {
            return '';
        }
        return 'WHERE ' . $this->compileWhereGroup($wheres);
    }

    /**
     * Compile a list of where clauses into a boolean-connected group.
     *
     * @param list<WhereClause> $wheres The clauses to compile.
     * @return string The compiled group.
     */
    protected function compileWhereGroup(array $wheres): string
    {
        $segments = [];
        foreach ($wheres as $i => $where) {
            $boolean = $i === 0 ? '' : strtoupper($where['boolean']->value) . ' ';
            $segments[] = $boolean . $this->compileWhere($where);
        }
        return implode(' ', $segments);
    }

    /**
     * Compile a single where clause.
     *
     * @param WhereClause $where The clause to compile.
     * @return string The compiled clause.
     */
    protected function compileWhere(array $where): string
    {
        return match ($where['type']) {
            WhereType::Basic => $this->compileBasicWhere($where),
            WhereType::Between => $this->compileBetweenWhere($where),
            WhereType::Null => $this->compileNullWhere($where),
            WhereType::Raw => $where['sql'],
            WhereType::Column => $this->wrapSegments($where['first']) . ' ' . $where['operator']->value . ' ' . $this->wrapSegments($where['second']),
            WhereType::Nested => '(' . $this->compileWhereGroup($where['query']->getWheres()) . ')',
        };
    }

    /**
     * Compile a basic comparison where clause.
     *
     * @param array{type: WhereType::Basic, column: string, operator: WhereOperator, value: mixed, boolean: WhereBoolean} $where The clause to compile.
     * @return string The compiled clause.
     */
    protected function compileBasicWhere(array $where): string
    {
        $column = $this->wrapSegments($where['column']);
        $operator = $where['operator'];
        return match ($operator) {
            WhereOperator::Null => "{$column} IS NULL",
            WhereOperator::NotNull => "{$column} IS NOT NULL",
            WhereOperator::In => "{$column} IN (" . $this->parameterize($where['value']) . ')',
            WhereOperator::NotIn => "{$column} NOT IN (" . $this->parameterize($where['value']) . ')',
            WhereOperator::Between => "{$column} BETWEEN " . $this->parameterize($where['value']),
            WhereOperator::NotBetween => "{$column} NOT BETWEEN " . $this->parameterize($where['value']),
            WhereOperator::Eq, WhereOperator::NotEq, WhereOperator::Lt, WhereOperator::LtEq,
            WhereOperator::Gt, WhereOperator::GtEq, WhereOperator::Like, WhereOperator::NotLike,
            WhereOperator::Is, WhereOperator::IsNot => "{$column} {$operator->value} " . $this->parameter($where['value']),
        };
    }

    /**
     * Compile a between where clause.
     *
     * @param array{type: WhereType::Between, column: string, operator: WhereOperator, value: array{0: mixed, 1: mixed}, boolean: WhereBoolean} $where The clause to compile.
     * @return string The compiled clause.
     */
    protected function compileBetweenWhere(array $where): string
    {
        $column = $this->wrapSegments($where['column']);
        $operator = $where['operator'];
        return "{$column} {$operator->value} " . $this->parameter($where['value'][0]) . ' AND ' . $this->parameter($where['value'][1]);
    }

    /**
     * Compile a null where clause.
     *
     * @param array{type: WhereType::Null, column: string, operator: WhereOperator, boolean: WhereBoolean} $where The clause to compile.
     * @return string The compiled clause.
     */
    protected function compileNullWhere(array $where): string
    {
        $column = $this->wrapSegments($where['column']);
        $operator = $where['operator'];
        return "{$column} IS {$operator->value}";
    }

    /**
     * Compile the group-by clause.
     *
     * @param QueryBuilder $builder The query to compile.
     * @return string The group-by clause, or an empty string when there are none.
     */
    protected function compileGroups(QueryBuilder $builder): string
    {
        $groups = $builder->getGroups();
        if ($groups === []) {
            return '';
        }
        return 'GROUP BY ' . implode(', ', array_map(fn ($group) => $this->wrapSegments($group), $groups));
    }

    /**
     * Compile the having clauses.
     *
     * @param QueryBuilder $builder The query to compile.
     * @return string The having clause, or an empty string when there are none.
     */
    protected function compileHavings(QueryBuilder $builder): string
    {
        $havings = $builder->getHavings();
        if ($havings === []) {
            return '';
        }
        $segments = [];
        foreach ($havings as $i => $having) {
            $boolean = $i === 0 ? '' : 'AND ';
            $column = $this->wrapColumn($having['column']);
            $segments[] = $boolean . $column . ' ' . $having['operator']->value . ' ' . $this->parameter($having['value']);
        }
        return 'HAVING ' . implode(' ', $segments);
    }

    /**
     * Compile the order-by clauses.
     *
     * @param QueryBuilder $builder The query to compile.
     * @return string The order-by clause, or an empty string when there are none.
     */
    protected function compileOrders(QueryBuilder $builder): string
    {
        $orders = $builder->getOrders();
        if ($orders === []) {
            return '';
        }
        $segments = [];
        foreach ($orders as $order) {
            $column = $this->wrapColumn($order['column']);
            $segments[] = $order['direction'] !== null ? $column . ' ' . $order['direction']->value : $column;
        }
        return 'ORDER BY ' . implode(', ', $segments);
    }

    /**
     * Compile the limit clause.
     *
     * @param QueryBuilder $builder The query to compile.
     * @return string The limit clause, or an empty string when there is none.
     */
    protected function compileLimit(QueryBuilder $builder): string
    {
        return $builder->getLimit() !== null ? 'LIMIT ' . $builder->getLimit() : '';
    }

    /**
     * Compile the offset clause.
     *
     * @param QueryBuilder $builder The query to compile.
     * @return string The offset clause, or an empty string when there is none.
     */
    protected function compileOffset(QueryBuilder $builder): string
    {
        return $builder->getOffset() !== null ? 'OFFSET ' . $builder->getOffset() : '';
    }

    /**
     * Compile the row lock.
     *
     * The base Grammar is the fail-fast default: row locks are dialect-gated,
     * so a dialect that does not override this throws rather than silently
     * dropping the lock.
     *
     * @param QueryBuilder $builder The query to compile.
     * @return string The lock clause.
     * @throws UnsupportedFeatureException When the dialect does not support row locks.
     */
    protected function compileLock(QueryBuilder $builder): string
    {
        if ($builder->getLock() === null) {
            return '';
        }
        throw new UnsupportedFeatureException('This dialect does not support row locks.');
    }

    /**
     * Compile the unions, appending them to the compiled select.
     *
     * @param QueryBuilder $builder The query to compile.
     * @param string $sql The already-compiled select.
     * @return string The select with any unions appended.
     */
    protected function compileUnions(QueryBuilder $builder, string $sql): string
    {
        foreach ($builder->getUnions() as $union) {
            $keyword = $union['all'] ? 'UNION ALL' : 'UNION';
            $sub = $union['query'];
            // Compile the sub-builder's SQL and pull its bindings in the
            // same pass, in the same order — the sub-builder's placeholders
            // appear in this SQL, so its bindings must land in the Union
            // category now, in exactly the compiled order. Snapshotting at
            // union() call time desynchronized the two whenever the
            // sub-builder gained clauses afterwards.
            $unionSql = $this->compileSelect($sub);
            $builder->pushBindings(BindingCategory::Union, $sub->getBindings());
            $sql .= ' ' . $keyword . ' (' . $unionSql . ')';
        }
        return $sql;
    }

    /**
     * Wrap the from table for a statement root that requires a plain table.
     *
     * Insert/update/delete target a real table — a subquery from is
     * unsupported and fails fast.
     *
     * @param QueryBuilder $builder The query to compile.
     * @return string The wrapped table.
     */
    protected function wrapFromTable(QueryBuilder $builder): string
    {
        $from = $builder->getFrom();
        if ($from instanceof QueryBuilder) {
            throw new UnsupportedFeatureException('Insert/update/delete cannot target a subquery.');
        }
        return $this->wrapTable($from);
    }
}