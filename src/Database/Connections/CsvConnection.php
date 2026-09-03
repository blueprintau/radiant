<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Connections;

use BlueprintAU\Collections\Collection;
use BlueprintAU\Radiant\Database\Concerns\NormalizesInsertRows;
use BlueprintAU\Radiant\Database\Exceptions\UnsupportedFeatureException;
use BlueprintAU\Radiant\Database\Query\Enums\WhereBoolean;
use BlueprintAU\Radiant\Database\Query\Enums\WhereOperator;
use BlueprintAU\Radiant\Database\Query\Enums\WhereType;
use BlueprintAU\Radiant\Database\Query\Expression;
use BlueprintAU\Radiant\Database\Query\QueryBuilder;
use Override;

/**
 * A database connection backed by a CSV file.
 *
 * Implements the generic {@see ConnectionInterface} entirely in PHP — the
 * portable subset (select/insert/update/delete) is supported; SQL-only
 * features (joins, transactions, raw SQL) throw
 * {@see UnsupportedFeatureException}.
 *
 * The file is read in full on every select and rewritten on every write, so
 * this is for small, simple datasets — it exists to prove the backend
 * contract is portable, not for production workloads.
 */
final class CsvConnection implements ConnectionInterface
{
    use NormalizesInsertRows;

    /**
     * @param string $filePath The CSV file to read from and write to.
     * @param bool $readOnly When true, write operations (insert, update,
     *        delete) throw {@see UnsupportedFeatureException} instead of
     *        modifying the file.
     */
    public function __construct(
        protected string $filePath,
        protected bool $readOnly = false,
    ) {}

    /**
     * Start a fluent query against a table, bound to this connection.
     *
     * @param string $identifier The table name.
     * @return QueryBuilder A new query builder, pre-bound to the table.
     */
    #[Override]
    public function table(string $identifier): QueryBuilder
    {
        return new QueryBuilder($this, $identifier);
    }

    /**
     * Run the query and return the matching rows.
     *
     * Applies the wheres, orders, limit/offset, and aggregates entirely in
     * PHP.
     *
     * @param QueryBuilder $query The query to run.
     * @return Collection<int,\stdClass> The matching rows, each as an object.
     * @throws UnsupportedFeatureException When the query uses a feature CSV
     *         can't support (joins, having, unions, locks).
     */
    #[Override]
    public function select(QueryBuilder $query): Collection
    {
        if ($query->getJoins() !== []) {
            throw new UnsupportedFeatureException('This connection does not support joins.');
        }
        if ($query->getUnions() !== []) {
            throw new UnsupportedFeatureException('This connection does not support unions.');
        }
        if ($query->getLock() !== null) {
            throw new UnsupportedFeatureException('This connection does not support row locks.');
        }
        if ($query->isDistinct()) {
            throw new UnsupportedFeatureException('This connection does not support DISTINCT.');
        }

        $rows = $this->applyWheres($query, $this->readRows());
        $rows = $this->applyOrders($query, $rows);

        // Split the requested columns into plain fields and aggregate
        // expressions (e.g. 'count(*)', 'max(price) as max_price').
        [$fields, $aggregates] = $this->splitColumns($query->getColumns());

        // No aggregates → apply limit/offset to the raw rows, then project.
        if ($aggregates === []) {
            $rows = $this->applyLimit($query, $rows);
            return Collection::make(array_map(
                fn (array $row) => (object) $this->project($row, $fields),
                $rows,
            ));
        }

        // Aggregates present → group by the group columns (if any) and
        // compute one row per group, carrying the group field values
        // alongside the aggregates — the same shape the SQL backend
        // produces. LIMIT/OFFSET apply *after* aggregation, matching SQL.
        $groups = $query->getGroups();
        $buckets = [];
        foreach ($rows as $row) {
            $key = $groups === [] ? '' : implode("\0", array_map(fn (string $c) => $row[$c] ?? null, $groups));
            $buckets[$key][] = $row;
        }

        $out = [];
        foreach ($buckets as $bucket) {
            $computed = $this->computeAggregates($bucket, $aggregates);
            foreach ($groups as $c) {
                $computed[$c] = $bucket[0][$c] ?? null;
            }
            $out[] = $computed;
        }
        return Collection::make(array_map(
            fn (array $row) => (object) $row,
            $this->applyLimit($query, $out),
        ));
    }

    /**
     * Insert one or more rows into the file.
     *
     * @param QueryBuilder $query The query for the table to insert into.
     * @param array<string,mixed>|list<array<string,mixed>> $values A single
     *        row or a list of rows.
     * @return int The number of rows inserted.
     */
    #[Override]
    public function insert(QueryBuilder $query, array $values): int
    {
        $this->assertWritable();
        $rows = $this->readRows();
        $normalized = $this->normalizeInsertRows($values);
        array_push($rows, ...$normalized);
        $this->writeRows($rows);
        return count($normalized);
    }

    /**
     * Insert a single row and return its generated id.
     *
     * CSV has no auto-increment id, so always returns null.
     *
     * @param QueryBuilder $query The query.
     * @param array<string,mixed> $values The row.
     * @return string|int|null Always null.
     */
    #[Override]
    public function insertGetId(QueryBuilder $query, array $values): string|int|null
    {
        $this->insert($query, $values);
        return null;
    }

    /**
     * Update the rows matching the query's conditions.
     *
     * @param QueryBuilder $query The query whose conditions select the rows to update.
     * @param array<string,mixed> $values The columns to change and their new values.
     * @return int How many rows were updated.
     */
    #[Override]
    public function update(QueryBuilder $query, array $values): int
    {
        $this->assertWritable();
        $rows = $this->readRows();
        $affected = 0;
        foreach ($rows as &$row) {
            if ($this->matchesAll($query, $row)) {
                $row = array_merge($row, $values);
                $affected++;
            }
        }
        unset($row);
        $this->writeRows($rows);
        return $affected;
    }

    /**
     * Delete the rows matching the query's conditions.
     *
     * @param QueryBuilder $query The query whose conditions select the rows to delete.
     * @return int How many rows were deleted.
     */
    #[Override]
    public function delete(QueryBuilder $query): int
    {
        $this->assertWritable();
        $rows = $this->readRows();
        $kept = array_filter($rows, fn (array $row) => !$this->matchesAll($query, $row));
        $affected = count($rows) - count($kept);
        $this->writeRows(array_values($kept));
        return $affected;
    }

    // ---- Pipeline helpers ----

    /**
     * Apply the query's where clauses to the rows, honoring boolean
     * connectors and nested groups.
     *
     * @param QueryBuilder $query The query.
     * @param list<array<string,mixed>> $rows The rows.
     * @return list<array<string,mixed>> The filtered rows.
     * @throws UnsupportedFeatureException When a where type CSV can't
     *         evaluate is present.
     */
    private function applyWheres(QueryBuilder $query, array $rows): array
    {
        $wheres = $query->getWheres();
        if ($wheres === []) {
            return $rows;
        }

        return array_values(array_filter(
            $rows,
            fn (array $row) => $this->matchesWheres($wheres, $row),
        ));
    }

    /**
     * Evaluate a list of where clauses against a row, honoring the boolean
     * connectors between them.
     *
     * @param list<array<string,mixed>> $wheres The where clauses.
     * @param array<string,mixed> $row The row to test.
     * @return bool True when the row matches.
     * @throws UnsupportedFeatureException When a where type CSV can't
     *         evaluate is hit.
     */
    private function matchesWheres(array $wheres, array $row): bool
    {
        $result = $this->matchesWhere($wheres[0], $row);
        for ($i = 1, $count = count($wheres); $i < $count; $i++) {
            $matches = $this->matchesWhere($wheres[$i], $row);
            $boolean = $wheres[$i]['boolean'] ?? WhereBoolean::And;
            $result = $boolean === WhereBoolean::Or ? $result || $matches : $result && $matches;
        }
        return $result;
    }

    /**
     * Evaluate a single where clause against a row.
     *
     * @param array<string,mixed> $where The where clause.
     * @param array<string,mixed> $row The row to test.
     * @return bool True when the row matches.
     * @throws UnsupportedFeatureException When the where type is not
     *         supported by the CSV backend.
     */
    private function matchesWhere(array $where, array $row): bool
    {
        return match ($where['type']) {
            WhereType::Nested => $this->matchesWheres($where['query']->getWheres(), $row),
            WhereType::Raw => throw new UnsupportedFeatureException('This connection does not support raw where clauses.'),
            WhereType::Column => throw new UnsupportedFeatureException('This connection does not support column-to-column where clauses.'),
            WhereType::Null => $this->matchesNull($row[$where['column']] ?? null, $where['operator']),
            WhereType::Between => $this->matchesBetween($row[$where['column']] ?? null, $where['operator'], $where['value']),
            WhereType::Basic => $this->matchesBasic($row[$where['column']] ?? null, $where['operator'], $where['value']),
            default => throw new \LogicException('Unknown where type on a CSV connection: ' . get_debug_type($where['type'])),
        };
    }

    /**
     * Evaluate an IS NULL / IS NOT NULL clause.
     *
     * @param mixed $value The row value.
     * @param WhereOperator|string $operator The null operator.
     * @return bool True when the value is (or is not) null.
     */
    private function matchesNull(mixed $value, WhereOperator|string $operator): bool
    {
        $isNull = $value === null;
        $operator = $operator instanceof WhereOperator ? $operator : WhereOperator::from(strtoupper($operator));
        return $operator === WhereOperator::NotNull ? !$isNull : $isNull;
    }

    /**
     * Evaluate a BETWEEN / NOT BETWEEN clause.
     *
     * @param mixed $value The row value.
     * @param WhereOperator|string $operator The between operator.
     * @param array{0: mixed, 1: mixed} $range The two bounds.
     * @return bool True when the value is (or is not) between the bounds.
     */
    private function matchesBetween(mixed $value, WhereOperator|string $operator, array $range): bool
    {
        $between = $value >= $range[0] && $value <= $range[1];
        $operator = $operator instanceof WhereOperator ? $operator : WhereOperator::from(strtoupper($operator));
        return $operator === WhereOperator::NotBetween ? !$between : $between;
    }

    /**
     * Evaluate a basic comparison (also handles IN / NOT IN, LIKE / NOT LIKE).
     *
     * @param mixed $value The row value.
     * @param WhereOperator|string $operator The comparison operator.
     * @param mixed $operand The value to compare against.
     * @return bool True when the comparison holds.
     * @throws UnsupportedFeatureException When the operator is not supported.
     */
    private function matchesBasic(mixed $value, WhereOperator|string $operator, mixed $operand): bool
    {
        $operator = $operator instanceof WhereOperator ? $operator : WhereOperator::from(strtoupper($operator));

        return match ($operator) {
            WhereOperator::Eq => $value == $operand,
            WhereOperator::NotEq => $value != $operand,
            WhereOperator::Lt => $value < $operand,
            WhereOperator::LtEq => $value <= $operand,
            WhereOperator::Gt => $value > $operand,
            WhereOperator::GtEq => $value >= $operand,
            WhereOperator::Like => is_string($value) && $this->like($value, (string) $operand),
            WhereOperator::NotLike => !(is_string($value) && $this->like($value, (string) $operand)),
            WhereOperator::In => in_array($value, (array) $operand, true),
            WhereOperator::NotIn => !in_array($value, (array) $operand, true),
            default => throw new UnsupportedFeatureException(
                'This connection does not support the ' . $operator->value . ' operator.',
            ),
        };
    }

    /**
     * SQL LIKE semantics for the few basic operators that need it.
     *
     * @param string $value The subject.
     * @param string $pattern The SQL pattern (% and _ wildcards).
     * @return bool True when the value matches.
     */
    private function like(string $value, string $pattern): bool
    {
        $regex = preg_quote($pattern, '~');
        $regex = str_replace(['%', '_'], ['.*', '.'], $regex);
        return preg_match("~^{$regex}$~i", $value) === 1;
    }

    /**
     * Apply the query's order-by clauses to the rows.
     *
     * @param QueryBuilder $query The query builder.
     * @param list<array<string,mixed>> $rows The rows.
     * @return list<array<string,mixed>> The sorted rows.
     */
    private function applyOrders(QueryBuilder $query, array $rows): array
    {
        $orders = $query->getOrders();
        // Reverse so the last-listed order wins as the primary sort key.
        foreach (array_reverse($orders) as $order) {
            $column = $order['column'];
            if ($column instanceof Expression) {
                throw new UnsupportedFeatureException('This connection does not support raw order-by expressions.');
            }
            $direction = $order['direction'] === 'DESC' ? -1 : 1;
            usort(
                $rows,
                fn (array $a, array $b) => $direction * ($a[$column] <=> $b[$column]),
            );
        }
        return $rows;
    }

    /**
     * Apply the limit and offset.
     *
     * @param QueryBuilder $query The query.
     * @param list<array<string,mixed>> $rows The rows.
     * @return list<array<string,mixed>> The sliced rows.
     */
    private function applyLimit(QueryBuilder $query, array $rows): array
    {
        $limit = $query->getLimit();
        $offset = $query->getOffset() ?? 0;
        return $limit === null ? $rows : array_slice($rows, $offset, $limit);
    }

    /**
     * Split the requested columns into plain fields and aggregate
     * expressions.
     *
     * An aggregate is anything matching `func(col)` or `func(col) as alias`.
     * The CSV's aggregate functions are count, max, min, sum and avg.
     *
     * @param list<string|Expression> $columns The requested columns.
     * @return array{0: list<string>, 1: array<string, array{0: string, 1: string}>}
     *         The plain fields, and aggregate aliases mapped to
     *         [function, column].
     */
    private function splitColumns(array $columns): array
    {
        $fields = [];
        $aggregates = [];
        foreach ($columns as $column) {
            if ($column instanceof Expression) {
                // An Expression is raw SQL — not something a CSV connection can
                // evaluate as an aggregate, so treat it as unsupported.
                throw new UnsupportedFeatureException('This connection does not support raw select expressions.');
            }
            if (preg_match('/^([a-z_]+)\((.+)\)(?:\s+as\s+(.+))?$/i', $column, $m)) {
                $alias = $m[3] ?? $column;
                $aggregates[$alias] = [$m[1], $m[2]];
            } else {
                $fields[] = $column;
            }
        }
        return [$fields, $aggregates];
    }

    /**
     * Project a row to only the requested plain fields.
     *
     * @param array<string,mixed> $row The row.
     * @param list<string> $fields The fields to keep.
     * @return array<string,mixed> The projected row.
     * @throws \InvalidArgumentException When a requested field is missing.
     */
    private function project(array $row, array $fields): array
    {
        if (in_array('*', $fields, true)) {
            return $row;
        }
        $out = [];
        foreach ($fields as $field) {
            if (!array_key_exists($field, $row)) {
                throw new \InvalidArgumentException("Unknown column [{$field}] on CSV connection.");
            }
            $out[$field] = $row[$field];
        }
        return $out;
    }

    /**
     * Compute aggregate functions over a group of rows.
     *
     * @param list<array<string,mixed>> $rows The group's rows.
     * @param array<string, array{0: string, 1: string}> $aggregates
     *        Alias → [function, column].
     * @return array<string, mixed> Alias → computed value.
     * @throws \InvalidArgumentException When the aggregate function is
     *         unsupported.
     */
    private function computeAggregates(array $rows, array $aggregates): array
    {
        $result = [];
        foreach ($aggregates as $alias => [$function, $column]) {
            $values = $column === '*' ? $rows : array_column($rows, $column);
            $result[$alias] = match ($function) {
                'count' => count($values),
                'max' => $values === [] ? null : max($values),
                'min' => $values === [] ? null : min($values),
                'sum' => array_sum($values),
                'avg' => count($values) ? array_sum($values) / count($values) : null,
                default => throw new \InvalidArgumentException("Unsupported aggregate [{$function}] on a CSV connection."),
            };
        }
        return $result;
    }

    // ---- Row access ----

    /**
     * Fail fast when the connection is read-only.
     *
     * @throws UnsupportedFeatureException When the connection is read-only.
     */
    private function assertWritable(): void
    {
        if ($this->readOnly) {
            throw new UnsupportedFeatureException('This CSV connection is read-only.');
        }
    }

    /**
     * Whether a row matches every where clause of the query.
     *
     * @param QueryBuilder $query The query.
     * @param array<string,mixed> $row The row to test.
     * @return bool True when the row matches.
     */
    private function matchesAll(QueryBuilder $query, array $row): bool
    {
        return $this->matchesWheres($query->getWheres(), $row);
    }

    /**
     * Read the CSV file into an array of associative rows.
     *
     * @return list<array<string,mixed>> The rows.
     * @throws \RuntimeException When the file cannot be opened or read.
     */
    private function readRows(): array
    {
        $handle = fopen($this->filePath, 'r');
        if ($handle === false) {
            throw new \RuntimeException("Could not open CSV file [{$this->filePath}].");
        }
        $rows = [];
        $header = fgetcsv($handle, escape: '');
        if ($header === false) {
            fclose($handle);
            return [];
        }
        $header = array_map(strval(...), $header);
        while (($line = fgetcsv($handle, escape: '')) !== false) {
            $rows[] = array_combine($header, array_map(strval(...), $line));
        }
        fclose($handle);
        return $rows;
    }

    /**
     * Write the rows back to the file, rewriting the header.
     *
     * @param list<array<string,mixed>> $rows The rows to write.
     * @throws \RuntimeException When the file cannot be opened or written.
     */
    private function writeRows(array $rows): void
    {
        $handle = fopen($this->filePath, 'w');
        if ($handle === false) {
            throw new \RuntimeException("Could not write CSV file [{$this->filePath}].");
        }
        if ($rows !== []) {
            fputcsv($handle, array_keys($rows[0]), escape: '');
            foreach ($rows as $row) {
                fputcsv($handle, $row, escape: '');
            }
        }
        fclose($handle);
    }
}