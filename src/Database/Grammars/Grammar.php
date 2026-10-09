<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Grammars;

use BlueprintAU\Radiant\Database\Concerns\ConcatenatesStatements;
use BlueprintAU\Radiant\Database\Concerns\NormalizesInsertRows;
use BlueprintAU\Radiant\Database\Concerns\QuotesLiterals;
use BlueprintAU\Radiant\Database\Exceptions\UnsupportedFeatureException;
use BlueprintAU\Radiant\Database\Query\Aggregate;
use BlueprintAU\Radiant\Database\Query\Expression;
use BlueprintAU\Radiant\Database\Query\Enums\ColumnOperator;
use BlueprintAU\Radiant\Database\Query\Enums\JoinType;
use BlueprintAU\Radiant\Database\Query\QueryBuilder;
use BlueprintAU\Radiant\Database\Query\SubquerySelect;
use BlueprintAU\Radiant\Database\Query\ToSqlValue;
use BlueprintAU\Radiant\Database\Query\Enums\WhereBoolean;
use BlueprintAU\Radiant\Database\Query\Enums\WhereOperator;
use BlueprintAU\Radiant\Database\Query\Enums\WhereType;

/**
 * Compiles query-builder state into SQL for a specific dialect.
 *
 * The base Grammar is dialect-agnostic: it owns the decomposition of a query
 * into its smallest SQL fragments and the composition of those fragments back
 * up into the four statement roots — {@see compileSelect()}, {@see compileInsert()},
 * {@see compileUpdate()}, and {@see compileDelete()}. Subclasses provide the
 * dialect specifics: identifier quoting via {@see wrap()}, `RETURNING` support
 * via {@see usesReturning()}, and the limit/offset/lock rendering that differs
 * per dialect. Only the four roots are public; a feature the active dialect
 * cannot express throws {@see UnsupportedFeatureException}.
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
     * @param  string  $value
     * @return string
     */
    abstract protected function wrap(string $value): string;

    // ---- Identifier helpers ----

    /**
     * Wrap a possibly-qualified identifier, quoting each `.`-separated segment.
     *
     * A `*` segment (bare or qualified) passes through unquoted; an
     * {@see Expression} is passed through untouched.
     *
     * @param  string|Expression  $value
     * @return string
     */
    protected function wrapSegments(string|Expression $value): string
    {
        if ($value instanceof Expression) {
            return $value->value;
        }
        return implode('.', array_map(
            fn($segment) => $segment === '*' ? '*' : $this->wrap($segment),
            explode('.', $value)
        ));
    }

    /**
     * Wrap a table reference, respecting an `as` alias.
     *
     * @param  string|Expression  $table
     * @return string
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
     * An {@see Aggregate} renders from its structured parts; an {@see Expression}
     * is passed through; a {@see SubquerySelect} renders the parenthesized
     * subquery with its AS alias.
     *
     * @param  string|Expression|Aggregate|SubquerySelect  $column
     * @return string
     */
    protected function wrapColumn(string|Expression|Aggregate|SubquerySelect $column): string
    {
        if ($column instanceof Expression) {
            return $column->value;
        }

        if ($column instanceof SubquerySelect) {
            return '(' . $this->compileSelect($column->query) . ') AS '
                . $this->wrapSegments($column->alias);
        }

        if ($column instanceof Aggregate) {
            $rendered = $column->function . '(' . $this->wrapAggregateInner($column->column) . ')';

            // Only an EXPLICIT alias renders an AS — the derived result key
            // (e.g. `count(*)`) is for reading the row back, not for SQL.
            return $column->alias === null
                ? $rendered
                : $rendered . ' AS ' . $this->wrapSegments($column->alias);
        }

        if (preg_match('/^(.+?)(?:\s+as\s+)(.+)$/i', $column, $m)) {
            return $this->wrapSegments($m[1]) . ' AS ' . $this->wrapSegments($m[2]);
        }
        
        return $this->wrapSegments($column);
    }

    /**
     * Wrap the inner content of an aggregate expression.
     *
     * The accepted string shapes are strict — `*`, `distinct x`, or a single
     * identifier path (optionally `.*`). Anything else fails closed with
     * {@see UnsupportedFeatureException}; complex arguments belong in an
     * Expression.
     *
     * @param  string|Expression  $inner
     * @return string
     * @throws UnsupportedFeatureException
     */
    protected function wrapAggregateInner(string|Expression $inner): string
    {
        if ($inner instanceof Expression) {
            return $inner->value;
        }
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
     * @param  list<string|Expression|Aggregate|SubquerySelect>  $columns
     * @return string
     */
    protected function columnize(array $columns): string
    {
        return implode(', ', array_map(fn($column) => $this->wrapColumn($column), $columns));
    }

    // ---- Value helpers ----

    /**
     * Render a bindable value as a `?` placeholder, or inline a raw literal.
     *
     * An `Expression` is spliced in verbatim; a `ToSqlValue` is extracted to
     * its scalar and quoted as a literal.
     *
     * @param  mixed  $value
     * @return string
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
     * @param  array<int, mixed>  $values
     * @return string
     */
    protected function parameterize(array $values): string
    {
        return implode(', ', array_map(fn($value) => $this->parameter($value), $values));
    }

    // ---- Select root ----

    /**
     * Compile a select statement.
     *
     * Compiling is a pure snapshot: the builder passed in is never modified.
     *
     * @param  QueryBuilder  $builder
     * @return string
     */
    final public function compileSelect(QueryBuilder $builder): string
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
     * @param  QueryBuilder  $builder
     * @param  array<string, mixed>|list<array<string, mixed>>  $values
     * @param  string|null  $pk
     * @return string
     */
    final public function compileInsert(QueryBuilder $builder, array $values, ?string $pk = null): string
    {
        $rows = $this->normalizeInsertRows($values);

        // Ragged rows cannot compile: the column list comes from row 0 and
        // each row's placeholder group is sized by its own arity. Fail fast
        // rather than emit a malformed statement (or silently write NULL).
        $this->assertUniformInsertRows($rows);

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

        $columns = implode(', ', array_map(fn($column) => $this->wrapSegments($column), array_keys($rows[0])));
        $placeholders = implode(', ', array_map(
            fn($row) => '(' . implode(', ', array_fill(0, count($row), '?')) . ')',
            $rows
        ));
        $sql = "INSERT INTO {$this->wrapFromTable($builder)} ({$columns}) VALUES {$placeholders}";

        return $this->withReturning($sql, $pk);
    }

    /**
     * Compile the empty-row insert — the statement body for a row with no
     * columns.
     *
     * The SQL-standard form is `INSERT INTO t DEFAULT VALUES`; a dialect
     * without it overrides with the form it accepts.
     *
     * @param  QueryBuilder  $builder
     * @return string
     */
    protected function compileEmptyInsert(QueryBuilder $builder): string
    {
        return "INSERT INTO {$this->wrapFromTable($builder)} DEFAULT VALUES";
    }

    /**
     * Whether the dialect compiles `INSERT ... RETURNING`.
     *
     * @return bool
     */
    protected function usesReturning(): bool
    {
        return false;
    }

    /**
     * Append the `RETURNING` clause to a compiled statement when the
     * dialect supports it and a PK was declared.
     *
     * @param  string  $sql
     * @param  string|null  $pk
     * @return string
     */
    protected function withReturning(string $sql, ?string $pk): string
    {
        if ($pk !== null && $this->usesReturning()) {
            return $sql . ' RETURNING ' . $this->wrapSegments($pk);
        }

        return $sql;
    }

    /**
     * Compile an insert whose generated key the caller needs back.
     *
     * The result carries the compiled SQL plus whether that statement yields
     * the key (fetch the row) or not (read `lastInsertId()` after execution).
     *
     * @param  QueryBuilder  $builder
     * @param  array<string, mixed>  $values
     * @param  string  $pk
     * @return array{sql: string, returnsKey: bool}
     */
    final public function compileInsertForId(QueryBuilder $builder, array $values, string $pk): array
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
     * @param  QueryBuilder  $builder
     * @param  array<string, mixed>  $values
     * @return string
     */
    final public function compileUpdate(QueryBuilder $builder, array $values): string
    {
        $sets = implode(', ', array_map(
            fn($column) => $this->wrapSegments($column) . ' = ?',
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
     * @param  QueryBuilder  $builder
     * @return string
     */
    final public function compileDelete(QueryBuilder $builder): string
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
     * @param  QueryBuilder  $builder
     * @return string
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
     * @param  QueryBuilder  $builder
     * @return string
     */
    protected function compileFrom(QueryBuilder $builder): string
    {
        $from = $builder->getFrom();
        if ($from instanceof QueryBuilder) {
            $alias = $builder->getFromAlias();
            $subSql = $this->compileSelect($from);
            return '(' . $subSql . ') AS ' . $this->wrapSegments($alias ?? '');
        }
        return $this->wrapTable($from);
    }

    /**
     * Compile the join clauses.
     *
     * @param  QueryBuilder  $builder
     * @return string
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
     * @param  list<array{type: WhereType::Column, first: string, operator: ColumnOperator, second: string, boolean: WhereBoolean}>  $wheres
     * @return string
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
     * @param  QueryBuilder  $builder
     * @return string
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
     * @param  list<WhereClause>  $wheres
     * @return string
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
     * @param  WhereClause  $where
     * @return string
     */
    protected function compileWhere(array $where): string
    {
        return match ($where['type']) {
            WhereType::Basic => $this->compileBasicWhere($where),
            WhereType::Between => $this->compileBetweenWhere($where),
            WhereType::Null => $this->compileNullWhere($where),
            WhereType::Raw => $where['sql'],
            WhereType::Column => $this->wrapSegments($where['first']) . ' ' . $where['operator']->value . ' ' . $this->wrapSegments($where['second']),
            WhereType::Nested => '(' . $this->compileWhereGroup($where['group']->wheres) . ')',
            WhereType::Exists => ($where['negated'] ? 'NOT ' : '')
                . 'EXISTS (' . $this->compileSelect($where['query']) . ')',
            WhereType::InSub => $this->wrapSegments($where['column'])
                . ($where['negated'] ? ' NOT' : '') . ' IN ('
                . $this->compileSelect($where['query']) . ')',
        };
    }

    /**
     * Compile a basic comparison where clause.
     *
     * @param  array{type: WhereType::Basic, column: string|Expression, operator: WhereOperator, value: mixed, boolean: WhereBoolean}  $where
     * @return string
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
     * @param  array{type: WhereType::Between, column: string|Expression, operator: WhereOperator, value: array{0: mixed, 1: mixed}, boolean: WhereBoolean}  $where
     * @return string
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
     * @param  array{type: WhereType::Null, column: string|Expression, operator: WhereOperator, boolean: WhereBoolean}  $where
     * @return string
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
     * @param  QueryBuilder  $builder
     * @return string
     */
    protected function compileGroups(QueryBuilder $builder): string
    {
        $groups = $builder->getGroups();
        if ($groups === []) {
            return '';
        }
        return 'GROUP BY ' . implode(', ', array_map(fn($group) => $this->wrapSegments($group), $groups));
    }

    /**
     * Compile the having clauses.
     *
     * @param  QueryBuilder  $builder
     * @return string
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
     * @param  QueryBuilder  $builder
     * @return string
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
     * @param  QueryBuilder  $builder
     * @return string
     */
    protected function compileLimit(QueryBuilder $builder): string
    {
        return $builder->getLimit() !== null ? 'LIMIT ' . $builder->getLimit() : '';
    }

    /**
     * Compile the offset clause.
     *
     * @param  QueryBuilder  $builder
     * @return string
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
     * @param  QueryBuilder  $builder
     * @return string
     * @throws UnsupportedFeatureException
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
     * @param  QueryBuilder  $builder
     * @param  string  $sql
     * @return string
     */
    protected function compileUnions(QueryBuilder $builder, string $sql): string
    {
        foreach ($builder->getUnions() as $union) {
            $keyword = $union['all'] ? 'UNION ALL' : 'UNION';
            $sub = $union['query'];
            $unionSql = $this->compileSelect($sub);
            $sql .= ' ' . $keyword . ' (' . $unionSql . ')';
        }
        return $sql;
    }

    /**
     * Wrap the from table for a statement root that requires a plain table.
     *
     * @param  QueryBuilder  $builder
     * @return string
     * @throws UnsupportedFeatureException
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
