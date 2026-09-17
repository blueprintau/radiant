<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Connections;

use BlueprintAU\Collections\Collection;
use BlueprintAU\Radiant\Database\Concerns\NormalizesInsertRows;
use BlueprintAU\Radiant\Database\Exceptions\UnsupportedFeatureException;
use BlueprintAU\Radiant\Database\Query\Enums\SortDirection;
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
 *
 * ## Concurrency guarantee boundary
 *
 * The advisory lock guarantees consistency **only between CsvConnection
 * instances of this library** cooperating through the sidecar lock file.
 * Non-participating writers (another process using file_put_contents, an
 * editor save, any code that does not take the lock) can tear or truncate
 * the file a reader is processing — the `readonly` flag gates *this*
 * connection's writes, not the file's. The blocking file I/O
 * (fopen/flock/fputcsv/rename) is also not coroutine-aware: under
 * Swoole/Fiber runtimes it stalls the worker for the I/O duration.
 */
final class CsvConnection implements ConnectionInterface
{
    use NormalizesInsertRows;

    /** Lock mode for {@see acquireLock()}: shared (reads). */
    private const LOCK_SHARED = false;

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

        // SQL orders AFTER grouping: the declared order-by must apply to
        // the AGGREGATED rows (whose keys are group columns and aggregate
        // aliases like `count(*)`), not be inherited from the pre-sort of
        // the raw rows. applyOrders() handles aliases because the order
        // column is looked up on the computed row, where the alias IS a key.
        $out = $this->applyOrders($query, $out);

        return Collection::make(array_map(
            fn (array $row) => (object) $row,
            $this->applyLimit($query, $out),
        ));
    }

    /**
     * Run the query and yield each matching row as it arrives.
     *
     * The dataset already lives fully in memory (the file is read whole for
     * every query), so there is nothing further to stream — this yields
     * exactly the rows {@see select()} would return, one at a time. It keeps
     * the portable contract honest: the same builder code runs against any
     * backend, with per-backend materialization.
     *
     * @param QueryBuilder $query The query to run.
     * @return \Generator<int,\stdClass> The matching rows, one at a time.
     * @throws UnsupportedFeatureException When the query uses a feature CSV
     *         can't support (joins, having, unions, locks).
     */
    #[Override]
    public function cursor(QueryBuilder $query): \Generator
    {
        yield from $this->select($query);
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
        // One exclusive lock spans the whole read-modify-write: no other
        // process can read between our read and our write, so no lost
        // updates. writeRows() reuses (and releases) the lock.
        $lock = $this->acquireLock();
        try {
            $rows = $this->readRowsUnlocked();
            $normalized = $this->normalizeInsertRows($values);
            array_push($rows, ...$normalized);
            $this->writeRows($rows, $lock);
        } catch (\Throwable $e) {
            $this->releaseIfHeld($lock);
            throw $e;
        }
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
        // Same read-modify-write lock discipline as insert().
        $lock = $this->acquireLock();
        try {
            $rows = $this->readRowsUnlocked();
            $affected = 0;
            foreach ($rows as &$row) {
                if ($this->matchesAll($query, $row)) {
                    $row = array_merge($row, $values);
                    $affected++;
                }
            }
            unset($row);
            $this->writeRows($rows, $lock);
        } catch (\Throwable $e) {
            $this->releaseIfHeld($lock);
            throw $e;
        }
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
        // Same read-modify-write lock discipline as insert().
        $lock = $this->acquireLock();
        try {
            $rows = $this->readRowsUnlocked();
            $kept = array_filter($rows, fn (array $row) => !$this->matchesAll($query, $row));
            $affected = count($rows) - count($kept);
            $this->writeRows(array_values($kept), $lock);
        } catch (\Throwable $e) {
            $this->releaseIfHeld($lock);
            throw $e;
        }
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
        // An empty constraint list matches everything (SQL semantics: an
        // UPDATE/DELETE with no WHERE affects every row). The builder
        // rejects empty *nested* groups at declaration time, so this guard
        // only ever fires for the top-level no-clause case.
        if ($wheres === []) {
            return true;
        }
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
     * @param WhereOperator $operator The null operator.
     * @return bool True when the value is (or is not) null.
     */
    private function matchesNull(mixed $value, WhereOperator $operator): bool
    {
        $isNull = $value === null;
        return $operator === WhereOperator::NotNull ? !$isNull : $isNull;
    }

    /**
     * Evaluate a BETWEEN / NOT BETWEEN clause.
     *
     * @param mixed $value The row value.
     * @param WhereOperator $operator The between operator.
     * @param array{0: mixed, 1: mixed} $range The two bounds.
     * @return bool True when the value is (or is not) between the bounds.
     */
    private function matchesBetween(mixed $value, WhereOperator $operator, array $range): bool
    {
        $between = $value >= $range[0] && $value <= $range[1];
        return $operator === WhereOperator::NotBetween ? !$between : $between;
    }

    /**
     * Evaluate a basic comparison (also handles IN / NOT IN, LIKE / NOT LIKE).
     *
     * @param mixed $value The row value.
     * @param WhereOperator $operator The comparison operator.
     * @param mixed $operand The value to compare against.
     * @return bool True when the comparison holds.
     * @throws UnsupportedFeatureException When the operator is not supported.
     */
    private function matchesBasic(mixed $value, WhereOperator $operator, mixed $operand): bool
    {
        return match ($operator) {
            WhereOperator::Eq => $this->valuesEqual($value, $operand),
            WhereOperator::NotEq => !$this->valuesEqual($value, $operand),
            WhereOperator::Lt => $value < $operand,
            WhereOperator::LtEq => $value <= $operand,
            WhereOperator::Gt => $value > $operand,
            WhereOperator::GtEq => $value >= $operand,
            WhereOperator::Like => is_string($value) && $this->like($value, (string) $operand),
            WhereOperator::NotLike => !(is_string($value) && $this->like($value, (string) $operand)),
            WhereOperator::In => $this->valuesIn($value, (array) $operand),
            WhereOperator::NotIn => !$this->valuesIn($value, (array) $operand),
            default => throw new UnsupportedFeatureException(
                'This connection does not support the ' . $operator->value . ' operator.',
            ),
        };
    }

    /**
     * The canonical CSV comparator.
     *
     * CSV cells are strings, but callers bind typed values (`where('id', 5)`,
     * `where('id', 'IN', [5])`), so `'5'` must match `5`. PHP's loose `==`
     * did that — but over-matched: `'0e1' == 0`, `'1e3' == 1000`,
     * `'abc' == 0` are all true under `==`, so a filter against a
     * low-trust file (uploaded CSV, shared export) matched rows it should
     * not. The comparator is now the same strict-after-int-normalization
     * comparison the model layer uses ({@see \BlueprintAU\Radiant\Collection::keyMatches()}):
     * numeric integer strings collapse to int on BOTH sides, everything
     * else compares strictly — `'5'` still matches `5`, `'0e1'` no longer
     * matches `0`. One comparator serves equality and set membership, so
     * `=` and `IN` stay consistent with each other and with the SQL
     * backend's affinity semantics for integer columns.
     *
     * @param mixed $value The row value.
     * @param mixed $operand The bound operand.
     * @return bool True when the values are equal under the normalized
     *         strict comparison.
     */
    private function valuesEqual(mixed $value, mixed $operand): bool
    {
        if ($value === null || $operand === null) {
            return $value === $operand;
        }

        if (is_array($value) || is_array($operand)
            || is_object($value) || is_object($operand)
            || is_bool($value) || is_bool($operand)) {
            // Non-scalar or boolean operands have no CSV-cell meaning; a
            // strict identity check is the honest answer (and never the
            // type-juggling match `==` would produce).
            return $value === $operand;
        }

        return $this->normalizeCell($value) === $this->normalizeCell($operand);
    }

    /**
     * Normalize a scalar for strict comparison — integer numeric strings
     * collapse to int (canonical), everything else passes through.
     *
     * Mirrors {@see \BlueprintAU\Radiant\Collection}'s key normalization:
     * only `/^-?\d+$/` strings normalize, so `'0e1'`, `'1e3'`, and `'0x1A'
     * stay strings and never equal a bound int.
     *
     * @param mixed $value The scalar value.
     * @return mixed The normalized value.
     */
    private function normalizeCell(mixed $value): mixed
    {
        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        return $value;
    }

    /**
     * Set membership through the same canonical comparator as equality —
     * `IN` must never be stricter than `=` on the same backend.
     *
     * @param mixed $value The row value.
     * @param array<mixed> $operands The bound list.
     * @return bool True when the value matches any operand.
     */
    private function valuesIn(mixed $value, array $operands): bool
    {
        foreach ($operands as $operand) {
            if ($this->valuesEqual($value, $operand)) {
                return true;
            }
        }
        return false;
    }

    /**
     * SQL LIKE semantics for the few basic operators that need it.
     *
     * The pattern is translated BEFORE quoting: each `%` becomes `.*` and
     * each `_` becomes `.`, and every other character is preg_quoted — so
     * a pattern like `a%b` compiles to `a.*b` (matching `ab`, `axb`), not
     * the corrupted `a\\..*b` that quoting-first produced. The `s` flag
     * makes `.` match newlines, so `%` spans multi-line cells like SQL's
     * `%`. Matching is case-insensitive (a documented divergence from the
     * case-sensitive LIKE of Postgres/SQLite).
     *
     * @param string $value The subject.
     * @param string $pattern The SQL pattern (% and _ wildcards).
     * @return bool True when the value matches.
     */
    private function like(string $value, string $pattern): bool
    {
        $regex = '';
        foreach (mb_str_split($pattern) as $char) {
            $regex .= match ($char) {
                '%' => '.*',
                '_' => '.',
                default => preg_quote($char, '~'),
            };
        }
        return preg_match("~^{$regex}$~is", $value) === 1;
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
            $direction = $order['direction'] === SortDirection::Desc ? -1 : 1;
            usort(
                $rows,
                fn (array $a, array $b) => $direction * $this->compareCells($a[$column] ?? null, $b[$column] ?? null),
            );
        }
        return $rows;
    }

    /**
     * Compare two CSV cells for ordering.
     *
     * When BOTH cells are numeric, compare as numbers so `'10'` sorts after
     * `'9'` (SQL numeric-column semantics); otherwise compare as strings.
     * This normalizes the all-string storage against the mixed-width
     * numeric columns real files contain.
     *
     * @param mixed $a The first cell.
     * @param mixed $b The second cell.
     * @return int Negative, zero, or positive per spaceship semantics.
     */
    private function compareCells(mixed $a, mixed $b): int
    {
        if (is_numeric($a) && is_numeric($b)) {
            return (+$a) <=> (+$b);
        }
        return ($a ?? '') <=> ($b ?? '');
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
     * A file-backed connection has no transport to lose — it is never stale.
     *
     * The interface method exists so a caching layer can treat every
     * backend uniformly; the CSV backend simply never asks to be evicted.
     *
     * @return bool Always false.
     */
    #[Override]
    public function isStale(): bool
    {
        return false;
    }

    /**
     * A no-op for the CSV backend — see {@see isStale()}.
     */
    #[Override]
    public function markStale(): void
    {
        // Nothing to lose: the file is opened per operation.
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
     * Read the CSV file into an array of associative rows, under a SHARED
     * lock that is released before returning.
     *
     * A shared lock lets concurrent readers proceed in parallel while still
     * excluding writers mid-rename — readers serialize only against writes,
     * not against each other.
     *
     * @return list<array<string,mixed>> The rows.
     * @throws \RuntimeException When the file cannot be opened or read.
     */
    private function readRows(): array
    {
        $lock = $this->acquireLock(self::LOCK_SHARED);
        try {
            return $this->readRowsUnlocked();
        } finally {
            $this->releaseLock($lock);
        }
    }

    /**
     * Read rows from the CSV file without taking any lock.
     *
     * Used by the mutation path, which holds the sidecar lock across the
     * whole read-modify-write cycle and hands the lock to
     * {@see writeRows()}. The data handle is opened and closed here — it
     * must never be confused with the lock handle, because the data file's
     * inode is replaced by rename() on every write while the lock file's
     * is stable.
     *
     * @return list<array<string,mixed>> The rows.
     */
    private function readRowsUnlocked(): array
    {
        $handle = fopen($this->filePath, 'r');
        if ($handle === false) {
            throw new \RuntimeException("Could not open CSV file [{$this->filePath}].");
        }
        try {
            $rows = [];
            $header = fgetcsv($handle, escape: '');
            if ($header === false) {
                return [];
            }
            $header = array_map(strval(...), $header);
            while (($line = fgetcsv($handle, escape: '')) !== false) {
                $rows[] = array_combine($header, array_map(strval(...), $line));
            }
            return $rows;
        } finally {
            fclose($handle);
        }
    }

    /**
     * Best-effort lock release on a failure path.
     *
     * After writeRows() succeeded it has already released the lock; this
     * guard makes double-release harmless so the mutation methods can use
     * one catch block for every failure point.
     *
     * @param resource|null $lock The lock to release, if still held.
     */
    private function releaseIfHeld($lock): void
    {
        if (is_resource($lock)) {
            $this->releaseLock($lock);
        }
    }

    /**
     * Acquire an advisory lock on the CSV file's **sidecar lock file**.
     *
     * The lock must NOT be taken on the CSV file itself: writes go through
     * temp-file + rename(), which replaces the CSV's inode. A writer holding
     * flock on the old inode would not exclude a second writer whose flock
     * succeeds on the new inode — the lost-update race this sidecar exists
     * to close. The sidecar's inode never changes, so every cooperating
     * process serializes on the same object for the file's whole lifetime.
     *
     * The sidecar is created on demand and deliberately never deleted: a
     * delete-then-recreate window would reintroduce the same inode race.
     * It contains no data and is safe to leave in place.
     *
     * @param bool $exclusive True for LOCK_EX (writes — the read-modify-write
     *        cycle), false for LOCK_SH (reads — concurrent readers proceed).
     * @return resource The locked sidecar handle. Keep it; pass to
     *         {@see releaseLock()} (or {@see writeRows()}, which takes
     *         ownership of the lock).
     * @throws \RuntimeException When the lock file cannot be opened or locked.
     */
    private function acquireLock(bool $exclusive = true)
    {
        $lockPath = $this->lockPath();
        $lock = fopen($lockPath, 'c');
        if ($lock === false) {
            throw new \RuntimeException("Could not open CSV lock file [{$lockPath}].");
        }
        if (!flock($lock, $exclusive ? LOCK_EX : LOCK_SH)) {
            fclose($lock);
            throw new \RuntimeException("Could not lock CSV file [{$this->filePath}].");
        }
        return $lock;
    }

    /**
     * The sidecar lock file path for the CSV file.
     *
     * @return string The lock file path.
     */
    private function lockPath(): string
    {
        return $this->filePath . '.lock';
    }

    /**
     * Release the sidecar lock and close its handle.
     *
     * @param resource $lock The lock from {@see acquireLock()}.
     */
    private function releaseLock($lock): void
    {
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    /**
     * Write rows back to the file atomically, replacing the write path's
     * old truncate-then-write behavior.
     *
     * The data goes to a sibling temp file which then `rename()`s over the
     * original — rename is atomic on POSIX, so a crash, OOM, or kill at any
     * point leaves either the complete old file or the complete new one,
     * never a truncated half-dataset. Call with the lock from
     * {@see acquireLock()} to keep the exclusive lock across the whole
     * read-modify-write; call with null to take the lock for a write-only
     * cycle.
     *
     * The rename() replaces the CSV file's inode — harmless now that the
     * lock lives on the stable sidecar file, which is exactly why the two
     * handles must never be conflated.
     *
     * Every value is passed through {@see neutralizeFormula()} so a value
     * that begins with `=`, `+`, `-`, `@`, tab, or CR cannot execute as a
     * spreadsheet formula when the file is opened in Excel/Sheets.
     *
     * Rows are aligned to the canonical column order — the header row —
     * so a row whose keys were reordered (e.g. by an update adding a new
     * column) cannot drift out of alignment with its neighbors.
     *
     * @param list<array<string,mixed>> $rows The rows to write.
     * @param resource|null $lock An existing locked sidecar handle to reuse,
     *        or null to acquire the lock for this write.
     * @throws \RuntimeException When the file cannot be opened or written.
     */
    private function writeRows(array $rows, $lock = null): void
    {
        $ownsLock = $lock === null;
        if ($ownsLock) {
            $lock = $this->acquireLock();
        }

        // A UNIQUE temp path per write: a shared fixed temp name lets a
        // second writer's fopen('w') truncate the first writer's in-flight
        // temp (silent lost updates across processes). Process id + random
        // suffix; same directory so rename() stays same-filesystem atomic.
        $tempPath = sprintf(
            '%s.radiant-%s-%s.tmp',
            $this->filePath,
            (string) (getmypid() ?: 'unknown'),
            bin2hex(random_bytes(6)),
        );
        $temp = fopen($tempPath, 'w');
        if ($temp === false) {
            if ($ownsLock) {
                $this->releaseLock($lock);
            }
            throw new \RuntimeException("Could not write CSV file [{$tempPath}].");
        }

        // try/finally guarantees the temp file cannot outlive this call —
        // a TypeError from fputcsv (or any other unwinding failure) would
        // otherwise orphan a partial, data-bearing temp file per failure.
        try {
            $columns = $this->canonicalColumns($rows);
            // The header row is neutralized too — a hostile column name is
            // just as able to execute as a spreadsheet formula as a cell.
            $written = fputcsv($temp, array_map(
                fn (string $column) => $this->neutralizeFormula($column),
                $columns,
            ), escape: '') !== false;
            foreach ($rows as $row) {
                $aligned = [];
                foreach ($columns as $column) {
                    $value = $row[$column] ?? null;
                    $aligned[] = is_string($value) ? $this->neutralizeFormula($value) : $value;
                }
                if (fputcsv($temp, $aligned, escape: '') === false) {
                    $written = false;
                    break;
                }
            }

            if (!fclose($temp) || !$written) {
                throw new \RuntimeException("Could not write CSV file [{$tempPath}].");
            }

            // rename() replaces the original — carry its permissions over so
            // a 0600 file is not demoted to umask defaults on every write.
            $originalPerms = @fileperms($this->filePath);
            if ($originalPerms !== false) {
                @chmod($tempPath, $originalPerms & 0o777);
            }

            if (!rename($tempPath, $this->filePath)) {
                throw new \RuntimeException("Could not replace CSV file [{$this->filePath}].");
            }
        } finally {
            // After a successful rename the temp no longer exists; after any
            // failure it does — unlink it best-effort so no partial copy of
            // the data is ever left behind.
            if (is_resource($temp)) {
                fclose($temp);
            }
            if (file_exists($tempPath)) {
                @unlink($tempPath);
            }
            if ($ownsLock) {
                $this->releaseLock($lock);
            }
        }
    }

    /**
     * The canonical column order for a write: the union of the header row's
     * keys and every row's keys, in first-seen order.
     *
     * Every row is then aligned to this order in {@see writeRows()}, so a
     * row that gained or reordered columns cannot shift its values under
     * the wrong header — the silent-corruption mode of the old
     * `array_keys($rows[0])` header.
     *
     * @param list<array<string,mixed>> $rows The rows.
     * @return list<string> The column names.
     */
    private function canonicalColumns(array $rows): array
    {
        $columns = [];
        foreach ($rows as $row) {
            foreach (array_keys($row) as $column) {
                if (!in_array($column, $columns, true)) {
                    $columns[] = $column;
                }
            }
        }
        return $columns;
    }

    /**
     * Neutralize a value that a spreadsheet would evaluate as a formula.
     *
     * Leading whitespace is stripped and the TRIMMED value is written with
     * the quote prefix — spreadsheets trim before evaluating, so writing
     * `'` + the original (space-prefixed) value would leave a cell that
     * Excel/Sheets trims straight into a live formula. The prefix set
     * covers `=`, `@`, `|` (LibreOffice DDE), tab, CR, and non-numeric
     * `+`/`-`.
     *
     * @param string $value The raw value.
     * @return string The neutralized value.
     */
    private function neutralizeFormula(string $value): string
    {
        $trimmed = ltrim($value, " \t\r\n\0\v\f\xC2\xA0\xE2\x80\x8B\xEF\xBB\xBF");
        $first = $trimmed === '' ? '' : $trimmed[0];
        if (in_array($first, ['=', '@', '|', "\t", "\r"], true)) {
            return "'" . $trimmed;
        }
        if (in_array($first, ['+', '-'], true) && !is_numeric($trimmed)) {
            return "'" . $trimmed;
        }
        return $value;
    }
}