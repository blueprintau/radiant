<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Connections;

use BlueprintAU\Collections\Collection;
use BlueprintAU\Radiant\Database\Concerns\NormalizesInsertRows;
use BlueprintAU\Radiant\Database\Exceptions\UnsupportedFeatureException;
use BlueprintAU\Radiant\Database\Query\Aggregate;
use BlueprintAU\Radiant\Database\Query\Enums\SortDirection;
use BlueprintAU\Radiant\Database\Query\Enums\WhereBoolean;
use BlueprintAU\Radiant\Database\Query\Enums\WhereOperator;
use BlueprintAU\Radiant\Database\Query\Enums\WhereType;
use BlueprintAU\Radiant\Database\Query\Expression;
use BlueprintAU\Radiant\Database\Query\QueryBuilder;
use BlueprintAU\Radiant\Database\Query\SqlFeature;
use Override;

/**
 * A database connection backed by a CSV file.
 *
 * Implements the generic {@see ConnectionInterface} entirely in PHP — the
 * portable subset (select/insert/update/delete) is supported; SQL-only
 * features (joins, transactions, raw SQL) throw
 * {@see UnsupportedFeatureException}.
 *
 * The advisory lock guarantees consistency only between CsvConnection
 * instances of this library cooperating through the sidecar lock file.
 */
final class CsvConnection implements ConnectionInterface
{
    use NormalizesInsertRows;

    /** Lock mode for {@see acquireLock()}: shared (reads). */
    private const LOCK_SHARED = false;

    /**
     * @param  string  $filePath  The CSV file to read from and write to.
     * @param  bool  $readOnly  When true, write operations throw instead of modifying the file.
     */
    public function __construct(
        protected string $filePath,
        protected bool $readOnly = false,
    ) {}

    /**
     * Start a fluent query against a table, bound to this connection.
     *
     * @param  string  $identifier
     * @return QueryBuilder
     */
    #[Override]
    public function table(string $identifier): QueryBuilder
    {
        return new QueryBuilder($this, $identifier);
    }

    /**
     * Run the query and return the matching rows.
     *
     * @param  QueryBuilder  $query
     * @return Collection<int,\stdClass>
     * @throws UnsupportedFeatureException
     */
    #[Override]
    public function select(QueryBuilder $query): Collection
    {
        $query->assertSupports(
            SqlFeature::Aggregates,
        );

        $rows = $this->applyWheres($query, $this->readRows());
        $rows = $this->applyOrders($query, $rows);

        // Split the requested columns into plain fields and typed
        // Aggregate declarations.
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
        $out = $this->aggregateRows($query, $rows, $aggregates);

        return Collection::make(array_map(
            fn (array $row) => (object) $row,
            $this->applyLimit($query, $out),
        ));
    }

    /**
     * Run the query and return the first selected column's values.
     *
     * @param  QueryBuilder  $query
     * @return Collection<int, mixed>
     * @throws UnsupportedFeatureException
     */
    #[Override]
    public function selectColumn(QueryBuilder $query): Collection
    {
        $query->assertSupports(
            SqlFeature::Aggregates,
        );

        $rows = $this->applyWheres($query, $this->readRows());
        $rows = $this->applyOrders($query, $rows);

        [$fields, $aggregates] = $this->splitColumns($query->getColumns());

        if ($aggregates === []) {
            $rows = $this->applyLimit($query, $rows);
            $first = $fields[0] ?? null;

            if ($first === null || $first === '*') {
                throw new \InvalidArgumentException(
                    'selectColumn() requires a single named column; got '
                    . ($first === null ? 'an empty select list.' : "a wildcard select [{$first}]."),
                );
            }

            $out = [];
            foreach ($rows as $row) {
                if (!array_key_exists($first, $row)) {
                    throw new \InvalidArgumentException("Unknown column [{$first}] on CSV connection.");
                }
                $out[] = $row[$first];
            }
            return Collection::make($out);
        }

        // Aggregates: the first selected column IS the aggregate (the
        // builders' scalar reads select exactly one). Run the same
        // group → compute → order pipeline as select(), then read the
        // aggregate's alias positionally.
        $out = $this->aggregateRows($query, $rows, $aggregates);
        $out = $this->applyLimit($query, $out);

        $first = array_key_first($out[0] ?? []);
        if ($first === null) {
            throw new \InvalidArgumentException(
                'selectColumn() requires a single named column; got an empty select list.',
            );
        }

        $values = [];
        foreach ($out as $row) {
            $values[] = $row[$first];
        }
        return Collection::make($values);
    }

    /**
     * Group the filtered rows and compute the aggregates — the shared
     * aggregate pipeline behind {@see select()} and {@see selectColumn()}.
     *
     * @param  QueryBuilder  $query
     * @param  list<array<string,mixed>>  $rows
     * @param  array<string, array{0: string, string|Expression}>  $aggregates  Alias → [function, column].
     * @return list<array<string,mixed>>
     */
    private function aggregateRows(QueryBuilder $query, array $rows, array $aggregates): array
    {
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

        if ($out === [] && $groups === []) {
            $out[] = $this->computeAggregates([], $aggregates);
        }

        return $this->applyOrders($query, $out);
    }

    /**
     * Run the query and yield each matching row as it arrives.
     *
     * @param  QueryBuilder  $query
     * @return \Generator<int,\stdClass>
     * @throws UnsupportedFeatureException
     */
    #[Override]
    public function cursor(QueryBuilder $query): \Generator
    {
        yield from $this->select($query);
    }

    /**
     * Insert one or more rows into the file.
     *
     * @param  QueryBuilder  $query
     * @param  array<string,mixed>|list<array<string,mixed>>  $values
     * @return int
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

            // Fail fast on ragged rows BEFORE the read-modify-write — a
            // throw here releases the lock without touching the file.
            $this->assertUniformInsertRows($normalized);

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
     * @param  QueryBuilder  $query
     * @param  array<string,mixed>  $values
     * @return string|int|null
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
     * @param  QueryBuilder  $query
     * @param  array<string,mixed>  $values
     * @return int
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
     * @param  QueryBuilder  $query
     * @return int
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
     * @param  QueryBuilder  $query
     * @param  list<array<string,mixed>>  $rows
     * @return list<array<string,mixed>>
     * @throws UnsupportedFeatureException
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
     * @param  list<array<string,mixed>>  $wheres
     * @param  array<string,mixed>  $row
     * @return bool
     * @throws UnsupportedFeatureException
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
     * @param  array<string,mixed>  $where
     * @param  array<string,mixed>  $row
     * @return bool
     * @throws UnsupportedFeatureException
     */
    private function matchesWhere(array $where, array $row): bool
    {
        return match ($where['type']) {
            WhereType::Nested => $this->matchesWheres($where['group']->wheres, $row),
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
     * An empty cell counts as null — CSV has no null representation, so
     * treating `''` as null is the only way IS NULL semantics survive a
     * write/read round trip.
     *
     * @param  mixed  $value
     * @param  WhereOperator  $operator
     * @return bool
     */
    private function matchesNull(mixed $value, WhereOperator $operator): bool
    {
        $isNull = $value === null || $value === '';
        return $operator === WhereOperator::NotNull ? !$isNull : $isNull;
    }

    /**
     * Evaluate a BETWEEN / NOT BETWEEN clause.
     *
     * @param  mixed  $value
     * @param  WhereOperator  $operator
     * @param  array{0: mixed, 1: mixed}  $range
     * @return bool
     */
    private function matchesBetween(mixed $value, WhereOperator $operator, array $range): bool
    {
        $between = $value >= $range[0] && $value <= $range[1];
        return $operator === WhereOperator::NotBetween ? !$between : $between;
    }

    /**
     * Evaluate a basic comparison (also handles IN / NOT IN, LIKE / NOT LIKE).
     *
     * @param  mixed  $value
     * @param  WhereOperator  $operator
     * @param  mixed  $operand
     * @return bool
     * @throws UnsupportedFeatureException
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
     * Numeric integer strings collapse to int on both sides, everything
     * else compares strictly — `'5'` matches `5`, `'0e1'` does not match
     * `0`.
     *
     * @param  mixed  $value
     * @param  mixed  $operand
     * @return bool
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
     * collapse to int, everything else passes through.
     *
     * @param  mixed  $value
     * @return mixed
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
     * @param  mixed  $value
     * @param  array<mixed>  $operands
     * @return bool
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
     * Matching is case-insensitive (a documented divergence from the
     * case-sensitive LIKE of Postgres/SQLite).
     *
     * @param  string  $value
     * @param  string  $pattern
     * @return bool
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
     * @param  QueryBuilder  $query
     * @param  list<array<string,mixed>>  $rows
     * @return list<array<string,mixed>>
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
     * When both cells are numeric, compare as numbers; otherwise compare
     * as strings.
     *
     * @param  mixed  $a
     * @param  mixed  $b
     * @return int
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
     * @param  QueryBuilder  $query
     * @param  list<array<string,mixed>>  $rows
     * @return list<array<string,mixed>>
     */
    private function applyLimit(QueryBuilder $query, array $rows): array
    {
        $limit = $query->getLimit();
        $offset = $query->getOffset() ?? 0;
        return $limit === null ? $rows : array_slice($rows, $offset, $limit);
    }

    /**
     * Split the requested columns into plain fields and aggregates.
     *
     * @param  list<string|Expression|Aggregate>  $columns
     * @return array{0: list<string>, 1: array<string, array{0: string, string|Expression}>}
     * @throws UnsupportedFeatureException
     */
    private function splitColumns(array $columns): array
    {
        $fields = [];
        $aggregates = [];
        foreach ($columns as $column) {
            if ($column instanceof Expression) {
                throw new UnsupportedFeatureException('This connection does not support raw select expressions.');
            }
            if ($column instanceof Aggregate) {
                // The read-back key: the alias when given, else the derived
                // call text (`count(*)`, `sum(age)`) — the same value SQL
                // returns for an aliased aggregate and the same shape the
                // ordering path matches against.
                $columnKey = $column->column instanceof Expression
                    ? $column->column->value
                    : $column->column;
                $aggregates[$column->alias ?? "{$column->function}({$columnKey})"] = [
                    $column->function,
                    $column->column,
                ];
            } else {
                $fields[] = $column;
            }
        }
        return [$fields, $aggregates];
    }

    /**
     * Project a row to only the requested plain fields.
     *
     * @param  array<string,mixed>  $row
     * @param  list<string>  $fields
     * @return array<string,mixed>
     * @throws \InvalidArgumentException
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
     * @param  list<array<string,mixed>>  $rows
     * @param  array<string, array{0: string, string|Expression}>  $aggregates  Alias → [function, column].
     * @return array<string, mixed>
     * @throws \InvalidArgumentException
     * @throws UnsupportedFeatureException
     */
    private function computeAggregates(array $rows, array $aggregates): array
    {
        $result = [];
        foreach ($aggregates as $alias => [$function, $column]) {
            if ($column instanceof Expression) {
                throw new UnsupportedFeatureException(
                    'This connection cannot compute an aggregate over a raw Expression argument.',
                );
            }
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
     * @throws UnsupportedFeatureException
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
     * @return bool
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
     * @param  QueryBuilder  $query
     * @param  array<string,mixed>  $row
     * @return bool
     */
    private function matchesAll(QueryBuilder $query, array $row): bool
    {
        return $this->matchesWheres($query->getWheres(), $row);
    }

    /**
     * Read the CSV file into an array of associative rows, under a shared
     * lock that is released before returning.
     *
     * @return list<array<string,mixed>>
     * @throws \RuntimeException
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
     * The data handle is opened and closed here — it must never be confused
     * with the lock handle, because the data file's inode is replaced by
     * rename() on every write while the lock file's is stable.
     *
     * @return list<array<string,mixed>>
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
     * @param  resource|null  $lock
     */
    private function releaseIfHeld($lock): void
    {
        if (is_resource($lock)) {
            $this->releaseLock($lock);
        }
    }

    /**
     * Acquire an advisory lock on the CSV file's sidecar lock file.
     *
     * The lock must not be taken on the CSV file itself: writes go through
     * temp-file + rename(), which replaces the CSV's inode. The sidecar's
     * inode never changes, so every cooperating process serializes on the
     * same object for the file's whole lifetime.
     *
     * @param  bool  $exclusive  True for LOCK_EX (writes), false for LOCK_SH (reads).
     * @return resource
     * @throws \RuntimeException
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
     * @return string
     */
    private function lockPath(): string
    {
        return $this->filePath . '.lock';
    }

    /**
     * Release the sidecar lock and close its handle.
     *
     * @param  resource  $lock
     */
    private function releaseLock($lock): void
    {
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    /**
     * Write rows back to the file atomically via temp-file + rename().
     *
     * Every value is passed through {@see neutralizeFormula()} so a value
     * that begins with `=`, `+`, `-`, `@`, tab, or CR cannot execute as a
     * spreadsheet formula when the file is opened in Excel/Sheets. Rows are
     * aligned to the canonical column order — the header row.
     *
     * @param  list<array<string,mixed>>  $rows
     * @param  resource|null  $lock  An existing locked sidecar handle to reuse, or null to acquire the lock for this write.
     * @throws \RuntimeException
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
     * @param  list<array<string,mixed>>  $rows
     * @return list<string>
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
     * @param  string  $value
     * @return string
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