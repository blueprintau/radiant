<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Connections;

use BlueprintAU\Collections\Collection;
use BlueprintAU\Radiant\Database\Concerns\DetectsConnectionLoss;
use BlueprintAU\Radiant\Database\Concerns\NormalizesInsertRows;
use BlueprintAU\Radiant\Database\Exceptions\QueryException;
use BlueprintAU\Radiant\Database\Grammars\Grammar;
use BlueprintAU\Radiant\Database\Query\Enums\BindingCategory;
use BlueprintAU\Radiant\Database\Query\QueryBuilder;
use BlueprintAU\Radiant\Database\Schema\Blueprint;
use BlueprintAU\Radiant\Database\Schema\SchemaGrammar;
use BlueprintAU\Radiant\Database\Schema\SchemaOperation;
use BlueprintAU\Radiant\Database\ValueCodecs\DefaultValueCodec;
use BlueprintAU\Radiant\Database\ValueCodecs\ValueCodecInterface;
use Override;

/**
 * A database connection backed by SQL (MySQL, SQLite, Postgres, …).
 *
 * Use it to run queries, raw SQL, transactions and schema changes. You
 * normally get one from a {@see \BlueprintAU\Radiant\Database\DatabaseManager}
 * rather than constructing it yourself. Subclasses provide the dialect
 * specifics (e.g. how savepoints work), so you don't have to think about
 * them.
 *
 * @see ConnectionInterface
 */
abstract class SqlConnection implements ConnectionInterface
{
    use NormalizesInsertRows;
    use DetectsConnectionLoss;
    /**
     * Converts values between PHP types and what the database driver expects.
     *
     * Handles dialect specifics (e.g. Postgres' microsecond datetime format)
     * so you don't have to think about them when reading or writing values.
     *
     * @var ValueCodecInterface
     */
    public readonly ValueCodecInterface $codec;

    /**
     * Compiles query-builder state into dialect SQL.
     *
     * @var Grammar
     */
    public readonly Grammar $grammar;

    /**
     * Compiles schema definitions into dialect DDL.
     *
     * @var SchemaGrammar
     */
    public readonly SchemaGrammar $schemaGrammar;

    /**
     * Create a new SQL connection wrapping a PDO instance.
     *
     * Exception mode is forced here because the entire error contract of
     * this class depends on it: every path (`run()`, transactions,
     * savepoints) assumes failures surface as \PDOException rather than
     * silent `false` returns. Setting it at this single construction choke
     * point is un-overridable and covers PDO instances built directly and
     * handed in, independent of the connector's own forced-options layer.
     * Setting (rather than validating and throwing) keeps construction
     * idempotent and never fails — the class owns its PDO configuration.
     *
     * The codec is initialised from {@see getDefaultValueCodec()}, the
     * grammar from {@see getDefaultQueryGrammar()}, and the schema grammar
     * from {@see getDefaultSchemaGrammar()} so subclasses can provide
     * dialect-specific value adaptation, SQL compilation, and DDL
     * compilation.
     *
     * @param \PDO $pdo The underlying PDO connection.
     */
    public function __construct(protected \Pdo $pdo)
    {
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->codec = $this->getDefaultValueCodec();
        $this->grammar = $this->getDefaultQueryGrammar();
        $this->schemaGrammar = $this->getDefaultSchemaGrammar();
    }

    /**
     * Start a fluent query against a table, bound to this connection.
     *
     * @param string $identifier The table name (or fully-qualified identifier).
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
     * @param QueryBuilder $query The query to run, built via {@see table()}.
     * @return Collection<int,\stdClass> The matching rows, each as an object.
     */
    #[Override]
    public function select(QueryBuilder $query): Collection
    {
        $sql = $this->grammar->compileSelect($query);
        return $this->selectSql($sql, $query->getBindings());
    }

    /**
     * Insert one or more rows into the table.
     *
     * @param QueryBuilder $query The query for the table to insert into.
     * @param array<string,mixed>|list<array<string,mixed>> $values A single
     *        row or a list of rows.
     * @return int The number of rows inserted.
     */
    #[Override]
    public function insert(QueryBuilder $query, array $values): int
    {
        $sql = $this->grammar->compileInsert($query, $values);
        return $this->affectingStatement($sql, $this->flattenInsertValues($values));
    }

    /**
     * Insert a single row and return its generated id.
     *
     * @param QueryBuilder $query The query for the table to insert into.
     * @param array<string,mixed> $values The row to insert.
     * @return string|int|null The generated id, or null when there is none.
     */
    #[Override]
    public function insertGetId(QueryBuilder $query, array $values): string|int|null
    {
        $pk = $query->getInsertIdColumn();
        $sql = $this->grammar->compileInsert($query, $values, $pk);
        $bindings = $this->flattenInsertValues($values);

        if ($pk !== null && $this->grammar->usesReturning()) {
            $row = $this->selectSql($sql, $bindings)->first();
            if ($row === null) {
                return null;
            }
            $id = $this->codec->decode($row->{$pk});
            return is_int($id) || is_string($id) ? $id : null;
        }

        $this->statement($sql, $bindings);
        if ($pk === null) {
            return null;
        }
        // PDO::lastInsertId() always returns a string (or false when there is
        // no generated id); the codec passes strings through untouched, so no
        // decode is needed here.
        $id = $this->pdo->lastInsertId();
        return $id === false ? null : $id;
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
        $sql = $this->grammar->compileUpdate($query, $values);
        return $this->affectingStatement($sql, array_merge(array_values($values), $query->getBindings([BindingCategory::Join, BindingCategory::Where])));
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
        $sql = $this->grammar->compileDelete($query);
        return $this->affectingStatement($sql, $query->getBindings([BindingCategory::Join, BindingCategory::Where]));
    }

    /**
     * Flatten a single row or a list of rows into one binding list (row-major).
     *
     * @param array<string,mixed>|list<array<string,mixed>> $values A single
     *        row or a list of rows.
     * @return list<mixed> The flattened values.
     */
    protected function flattenInsertValues(array $values): array
    {
        $rows = $this->normalizeInsertRows($values);
        $bindings = [];
        foreach ($rows as $row) {
            array_push($bindings, ...array_values($row));
        }
        return $bindings;
    }

    // ---- Running and Binding ----


    /**
     * Run a raw SQL query and return every matching row as an object.
     *
     * Use this for ad-hoc queries that don't fit the fluent builder. Values
     * are bound through the codec, so datetimes and other types are adapted
     * to the dialect automatically.
     *
     * @param string $sql The raw SQL to run.
     * @param array<string|int, mixed> $bindings The values to bind, keyed by
     *        column (named) or position (unnamed).
     * @return Collection<int,\stdClass> The matching rows, each as an object.
     */
    final public function selectSql(string $sql, array $bindings = []): Collection
    {
        return Collection::make($this->run($sql, $bindings, fn(\PDOStatement $stmt) => $stmt->fetchAll(\PDO::FETCH_OBJ)));
    }

    /**
     * Run a raw SQL query and yield each matching row as it arrives.
     *
     * Unlike {@see selectSql()}, which buffers the whole result set in
     * memory, this streams: memory stays O(1) in the result size regardless
     * of row count. Consume the generator fully (or let it be garbage
     * collected) before running another query on this connection — an
     * unfinished cursor holds the statement, and MySQL's unbuffered mode
     * forbids a second query until the first result set is drained.
     *
     * @param string $sql The SQL to run.
     * @param array<string|int, mixed> $bindings The values to bind.
     * @return \Generator<int, \stdClass> The matching rows, one at a time.
     * @throws QueryException When the statement fails to prepare or execute.
     */
    final public function cursorSql(string $sql, array $bindings = []): \Generator
    {
        $stmt = $this->prepareAndExecute($sql, $bindings);
        try {
            while ($row = $stmt->fetch(\PDO::FETCH_OBJ)) {
                yield $row;
            }
        } finally {
            $stmt->closeCursor();
        }
    }

    /**
     * Run a callback over the query's rows in fixed-size chunks.
     *
     * Memory stays bounded by the chunk size, not the result size — the
     * convenient wrapper over {@see cursorSql()} for batch processing.
     *
     * **Chunk sizing.** Every chunk passed to the callback is exactly
     * `$size` rows, except possibly the last, which holds the remaining
     * rows (1..$size). A chunk is never larger than `$size`: the cursor
     * yields one row at a time and the buffer flushes as soon as it reaches
     * `$size`, so there is no code path that can over-fill it.
     *
     * **Early stop.** The callback returning `false` (strictly) stops the
     * iteration immediately — the cursor is abandoned and closed. Any other
     * return value, including `void`, `null`, and `0`, continues; return
     * `false` deliberately, not as a by-product.
     *
     * @param string $sql The SQL to run.
     * @param array<string|int, mixed> $bindings The values to bind.
     * @param int $size Rows per chunk (must be >= 1).
     * @param callable(list<\stdClass>): mixed $callback Receives each chunk;
     *        return `false` to stop early.
     * @return void
     * @throws \InvalidArgumentException When the chunk size is below 1.
     * @throws QueryException When the statement fails to prepare or execute.
     */
    final public function chunkSql(string $sql, array $bindings, int $size, callable $callback): void
    {
        if ($size < 1) {
            throw new \InvalidArgumentException("Chunk size must be at least 1; got {$size}.");
        }
        $chunk = [];
        foreach ($this->cursorSql($sql, $bindings) as $row) {
            $chunk[] = $row;
            if (count($chunk) === $size) {
                if ($callback($chunk) === false) {
                    return;
                }
                $chunk = [];
            }
        }
        if ($chunk !== []) {
            $callback($chunk);
        }
    }

    /**
     * Prepare, bind, and execute — returning the statement for callers
     * that manage the cursor themselves ({@see cursorSql()}).
     *
     * @param string $sql The SQL to run.
     * @param array<string|int, mixed> $bindings The values to bind.
     * @return \PDOStatement The executed statement.
     * @throws QueryException When the statement fails to prepare or execute.
     */
    private function prepareAndExecute(string $sql, array $bindings): \PDOStatement
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $this->bindValues($stmt, $bindings);
            $stmt->execute();
            return $stmt;
        } catch (\PDOException $e) {
            if ($this->isConnectionLoss($e)) {
                $this->stale = true;
            }
            throw new QueryException($sql, $bindings, $e);
        }
    }

    /**
     * Run a raw SQL statement that returns no result set.
     *
     * Use this for schema changes and other statements where you don't care
     * about the outcome beyond whether it succeeded.
     *
     * @param string $sql The raw SQL to run.
     * @param array<string|int, mixed> $bindings The values to bind, keyed by
     *        column (named) or position (unnamed).
     */
    final public function statement(string $sql, array $bindings = []): void
    {
        $this->run($sql, $bindings, fn() => null);
    }

    /**
     * Run a raw SQL statement and return how many rows it affected.
     *
     * Use this for INSERT, UPDATE, DELETE and similar statements where the
     * affected-row count matters.
     *
     * @param string $sql The raw SQL to run.
     * @param array<string|int, mixed> $bindings The values to bind, keyed by
     *        column (named) or position (unnamed).
     * @return int How many rows the statement affected.
     */
    final public function affectingStatement(string $sql, array $bindings = []): int
    {
        return $this->run($sql, $bindings, fn(\PDOStatement $stmt) => $stmt->rowCount());
    }

    /**
     * Bind values to the statement, adapting them through the codec.
     *
     * The bind guard — the last line of defence before PDO. By the time a
     * value reaches here it must already be part of the bindable union
     * (scalars + \DateTimeInterface): the column cast has run on the model
     * path, ToSqlValue objects have been extracted and inlined by the
     * QueryBuilder/Grammar, and the codec has formatted datetimes to strings.
     * Anything else is a bug upstream — fail fast rather than let PDO
     * silently stringify an object.
     *
     * @param \PDOStatement $stmt The prepared statement to bind to.
     * @param array<string|int, mixed> $bindings The values to bind, keyed by
     *        column (named) or position (unnamed).
     * @throws \InvalidArgumentException If a binding is not a scalar, null,
     *         or \DateTimeInterface.
     */
    protected function bindValues(\PDOStatement $stmt, array $bindings): void
    {
        foreach ($bindings as $key => $value) {
            // The write path: bindable value → codec → driver value → bind.
            if (!is_scalar($value) && !$value instanceof \DateTimeInterface && $value !== null) {
                throw new \InvalidArgumentException(
                    'Binding must be a scalar, null, or DateTimeInterface; got ' . get_debug_type($value) . '.'
                );
            }
            $value = $this->codec->encode($value);
            $stmt->bindValue(is_int($key) ? $key + 1 : $key, $value);
        }
    }

    /**
     * Prepare, bind, execute, and run the callback — the single run path.
     *
     * @template T
     * 
     * @param string $sql The SQL to run.
     * @param array<string|int, mixed> $bindings The values to bind.
     * @param callable(\PDOStatement): T $callback Receives the executed
     *        statement and returns the operation's result.
     * @return T The callback's result.
     * @throws QueryException When the statement fails to prepare or execute.
     */
    final protected function run(string $sql, array $bindings, callable $callback): mixed
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $this->bindValues($stmt, $bindings);
            $stmt->execute();
            return $callback($stmt);
        } catch (\PDOException $e) {
            if ($this->isConnectionLoss($e)) {
                $this->stale = true;
            }
            throw new QueryException($sql, $bindings, $e);
        }
    }

    // ---- Encoding and Grammar ----

    /**
     * The default codec for this connection — subclasses override to provide
     * dialect-specific value adaptation (e.g. Postgres' microsecond
     * datetimes).
     *
     * @return ValueCodecInterface The codec used for encode/decode.
     */
    protected function getDefaultValueCodec(): ValueCodecInterface
    {
        return new DefaultValueCodec();
    }

    /**
     * The default query grammar for this connection — subclasses override to
     * provide the dialect's SQL compilation (identifier quoting, RETURNING,
     * locks, limit/offset).
     *
     * @return Grammar The grammar used to compile queries.
     */
    abstract protected function getDefaultQueryGrammar(): Grammar;

    /**
     * The default schema grammar for this connection — subclasses override
     * to provide the dialect's DDL compilation (identifier quoting, type
     * mapping, auto-increment clause).
     *
     * @return SchemaGrammar The grammar used to compile schema changes.
     */
    abstract protected function getDefaultSchemaGrammar(): SchemaGrammar;

    // ---- Schema operations (SQL-only) ----

    /**
     * Create a table from a blueprint, plus any indexes declared on it.
     *
     * @param string $table The table name.
     * @param Blueprint $blueprint The columns to create.
     */
    final public function create(string $table, Blueprint $blueprint): void
    {
        $this->statement($this->schemaGrammar->compileCreate($table, $blueprint));

        foreach ($this->schemaGrammar->compileIndexes($table, $blueprint) as $indexSql) {
            $this->statement($indexSql);
        }
    }

    /**
     * Alter a table — add or drop columns.
     *
     * @param string $table The table name.
     * @param SchemaOperation $operation The operation to perform.
     * @param Blueprint $blueprint The columns involved.
     */
    final public function alter(string $table, SchemaOperation $operation, Blueprint $blueprint): void
    {
        $this->statement($this->schemaGrammar->compileAlter($table, $operation, $blueprint));
    }

    /**
     * Drop a table.
     *
     * @param string $table The table name.
     */
    final public function drop(string $table): void
    {
        $this->statement($this->schemaGrammar->compileDrop($table));
    }

    // ---- Transactions (depth-counter + savepoints) ----

    /**
     * The current transaction nesting depth.
     *
     * Zero means no active transaction. Every nested
     * {@see beginTransaction()} increments it; every commit/rollback
     * decrements it. Savepoints are used for depth > 1 when the dialect
     * supports them.
     *
     * @var int
     */
    private int $transactionLevel = 0;

    /**
     * The current transaction nesting depth.
     *
     * @return int Zero when no transaction is active, otherwise the depth.
     */
    final public function transactionLevel(): int
    {
        return $this->transactionLevel;
    }

    /**
     * Whether this dialect supports savepoints for nested transactions.
     *
     * @return bool True when savepoints can be used.
     */
    abstract protected function supportsSavepoints(): bool;

    /**
     * Create a named savepoint.
     *
     * @param string $name The savepoint name.
     */
    abstract protected function createSavepoint(string $name): void;

    /**
     * Release a named savepoint.
     *
     * @param string $name The savepoint name.
     */
    abstract protected function releaseSavepoint(string $name): void;

    /**
     * Roll back to a named savepoint.
     *
     * @param string $name The savepoint name.
     */
    abstract protected function rollbackToSavepoint(string $name): void;

    /**
     * Begin a transaction, nesting via savepoints when supported.
     */
    final public function beginTransaction(): void
    {
        $toLevel = $this->transactionLevel + 1;
        if ($toLevel === 1) {
            $this->pdo->beginTransaction();
        } elseif ($this->supportsSavepoints()) {
            $this->createSavepoint('trans' . $toLevel);
        }
        $this->transactionLevel = $toLevel;
    }

    /**
     * Commit the current transaction (or release the innermost savepoint).
     *
     * The level is decremented *before* the PDO call: if `commit()` throws
     * (e.g. the connection dropped mid-transaction), the counter stays
     * consistent with the database — the transaction is over server-side
     * either way. Without this, one failed commit leaves the connection
     * permanently convinced it is in a transaction.
     */
    final public function commit(): void
    {
        $toLevel = $this->transactionLevel - 1;
        $this->transactionLevel = $toLevel;
        if ($toLevel === 0) {
            $this->pdo->commit();
        } elseif ($this->supportsSavepoints()) {
            $this->releaseSavepoint('trans' . ($toLevel + 1));
        }
    }

    /**
     * Roll back the current transaction (or to the innermost savepoint).
     *
     * As with {@see commit()}, the level is decremented before the PDO call:
     * a failed `rollBack()` must not leave the connection stuck believing a
     * transaction exists that the server has already aborted.
     */
    final public function rollBack(): void
    {
        $toLevel = $this->transactionLevel - 1;
        $this->transactionLevel = $toLevel;
        if ($toLevel === 0) {
            $this->pdo->rollBack();
        } elseif ($this->supportsSavepoints()) {
            $this->rollbackToSavepoint('trans' . ($toLevel + 1));
        }
    }

    /**
     * Run a callback inside a transaction, committing on success and
     * rolling back on any exception.
     *
     * If the rollback itself fails (a frequent companion of whatever threw
     * in the first place — usually the connection died), the *original*
     * exception propagates; the rollback failure is discarded rather than
     * replacing it. The level was already decremented, so the connection is
     * not left believing it is still in a transaction.
     *
     * @param callable(SqlConnection): mixed $callback The work to run inside
     *        the transaction; receives this connection.
     * @return mixed The callback's return value.
     * @throws \Throwable Re-throws whatever the callback threw, after
     *         rolling back.
     */
    final public function transaction(callable $callback): mixed
    {
        $this->beginTransaction();
        try {
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (\Throwable $e) {
            try {
                $this->rollBack();
            } catch (\Throwable) {
                // The original failure is what the caller needs; a failed
                // rollback is secondary (and usually shares its cause).
            }
            throw $e;
        }
    }
}
