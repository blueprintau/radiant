<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Connections;

use BlueprintAU\Collections\Collection;
use BlueprintAU\Radiant\Database\Concerns\DetectsConnectionLoss;
use BlueprintAU\Radiant\Database\Concerns\NormalizesInsertRows;
use BlueprintAU\Radiant\Database\Exceptions\QueryException;
use BlueprintAU\Radiant\Database\Exceptions\UnsupportedFeatureException;
use BlueprintAU\Radiant\Database\Grammars\Grammar;
use BlueprintAU\Radiant\Database\Query\Enums\BindingCategory;
use BlueprintAU\Radiant\Database\Query\QueryBuilder;
use BlueprintAU\Radiant\Database\Schema\Blueprint;
use BlueprintAU\Radiant\Database\Schema\Grammars\SchemaGrammar;
use BlueprintAU\Radiant\Database\Schema\Enums\SchemaOperation;
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
 * ## Coroutine contract
 *
 * A connection is **not** safe for concurrent use by multiple coroutines
 * while a transaction is open. The transaction depth counter and savepoint
 * registry are per-connection state, not per-coroutine state: two coroutines
 * interleaving `beginTransaction()`/`commit()` frames on one shared
 * connection cross-commit each other's work. The rule:
 *
 * - **Use one connection per coroutine when a transaction is open.**
 * - As a backstop, the connection records the owning coroutine (fiber,
 *   Swoole coroutine, or process) when a transaction opens and throws a
 *   \LogicException if a *different* coroutine touches the transaction
 *   while it is open — the failure becomes loud instead of silently
 *   crossing commits. Queries on the same coroutine remain unrestricted.
 *
 * @see ConnectionInterface
 *
 * @template TGrammar of Grammar = Grammar
 * @template TSchemaGrammar of SchemaGrammar = SchemaGrammar
 * @template TSchemaInspector of \BlueprintAU\Radiant\Database\Schema\Inspectors\SchemaInspector = \BlueprintAU\Radiant\Database\Schema\Inspectors\SchemaInspector
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
     * @var TGrammar
     */
    public readonly Grammar $grammar;

    /**
     * Compiles schema definitions into dialect DDL.
     *
     * @var TSchemaGrammar
     */
    public readonly SchemaGrammar $schemaGrammar;

    /**
     * Reads the live schema — the read-side twin of {@see $schemaGrammar},
     * owned by the connection the same way (initialized in the constructor
     * from {@see getDefaultSchemaInspector()}). The schema differ consumes
     * `$db->schemaInspector`; the host never constructs one and never
     * touches a PDO to do it.
     *
     * @var TSchemaInspector
     */
    public readonly \BlueprintAU\Radiant\Database\Schema\Inspectors\SchemaInspector $schemaInspector;

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
        $this->schemaInspector = $this->getDefaultSchemaInspector();
    }

    /**
     * Assert the connection speaks SQL — throw when it does not.
     *
     * The narrowing assert: at runtime a non-SQL connection is a
     * feature-contract violation (SQL-only work on a backend that can't
     * do it), so it throws {@see UnsupportedFeatureException} rather than
     * letting the caller explode later on the first raw SQL / transaction
     * / schema call. For the type system, the {@see \phpstan-assert}
     * annotation narrows the argument to `SqlConnection` after the call —
     * no `@var` docblock needed at the call site.
     *
     * @param ConnectionInterface $connection The connection to check.
     * @phpstan-assert SqlConnection<Grammar, SchemaGrammar, \BlueprintAU\Radiant\Database\Schema\Inspectors\SchemaInspector> $connection
     */
    final public static function assertSql(ConnectionInterface $connection): void
    {
        if (!$connection instanceof self) {
            throw new UnsupportedFeatureException('The connection is not a SQL connection.');
        }
    }

    /**
     * Narrow a connection to SQL AND to this dialect — return it typed, or
     * throw.
     *
     * The return-value twin of {@see assertSql()}: the fail-fast contract
     * is stricter here, because `static` promises the CONCRETE dialect —
     * `SqliteConnection::from(...)` guarantees a SqliteConnection, not
     * merely some SQL connection. Three failure modes, three precise
     * errors: a non-SQL backend delegates to {@see assertSql()} (one place
     * owns the generic message); a SQL-but-wrong-dialect connection throws
     * its own message naming the expected class.
     *
     * @param ConnectionInterface $connection The connection to narrow.
     * @return static The same connection, typed as the calling dialect.
     * @throws UnsupportedFeatureException When the connection is not a
     *         {@see SqlConnection} at all, or is SQL but not the calling
     *         dialect.
     */
    final public static function from(ConnectionInterface $connection): static
    {
        if (!$connection instanceof static) {
            // A non-SQL backend gets the generic message; a SQL one gets
            // the dialect-specific one.
            self::assertSql($connection);
            throw new UnsupportedFeatureException(
                'The connection is SQL, but not a ' . static::class . '.'
            );
        }

        return $connection;
    }

    /**
     * Start a fluent query against a table, bound to this connection.
     *
     * @param string $identifier The table name (or fully-qualified identifier).
     * @return QueryBuilder A new query builder, pre-bound to the table.
     */
    #[Override]
    final public function table(string $identifier): QueryBuilder
    {
        return new QueryBuilder($this, $identifier);
    }

    /**
     * Run the query and return the matching rows.
     *
     * A query carrying a row lock (`lockForUpdate()` / `sharedLock()`) is
     * rejected outside a transaction: row locks are released at transaction
     * end, and in autocommit mode that is the END OF THE STATEMENT — the
     * lock is acquired and immediately released, silently degrading the
     * "lock the row, then update" pattern to an unlocked read-modify-write.
     * The library's fail-fast contract (no silent fallbacks) applies: the
     * caller must open the transaction the lock needs.
     *
     * @param QueryBuilder $query The query to run, built via {@see table()}.
     * @return Collection<int,\stdClass> The matching rows, each as an object.
     * @throws \LogicException When the query locks rows with no transaction open.
     */
    #[Override]
    final public function select(QueryBuilder $query): Collection
    {
        if ($query->getLock() !== null && $this->transactionLevel === 0) {
            throw new \LogicException(
                'Row locks (lockForUpdate/sharedLock) require an open transaction — '
                . 'outside one, the lock is released at statement end and protects nothing. '
                . 'Wrap the query in beginTransaction()/transaction().'
            );
        }

        $sql = $this->grammar->compileSelect($query);
        return $this->selectSql($sql, $query->getBindings());
    }

    /**
     * Run the query and return the FIRST selected column's values.
     *
     * The columnar scalar-read path: compiles exactly as {@see select()}
     * does, but the driver fetches the single column directly
     * (`PDO::FETCH_COLUMN`) instead of materializing one `\stdClass` per
     * row — the allocation the builders' `value()`/`pluck()` reads used to
     * pay per row. Values are bound through the codec, identical to
     * {@see select()}.
     *
     * @param QueryBuilder $query The query to run.
     * @return Collection<int, mixed> The first selected column's values, one per row.
     * @throws QueryException When the statement fails to prepare or execute.
     */
    #[Override]
    final public function selectColumn(QueryBuilder $query): Collection
    {
        $sql = $this->grammar->compileSelect($query);
        return $this->selectColumnSql($sql, $query->getBindings());
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
    final public function insert(QueryBuilder $query, array $values): int
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
    final public function insertGetId(QueryBuilder $query, array $values): string|int|null
    {
        $pk = $query->getInsertIdColumn();

        if ($pk === null) {
            // No key declared — compile the plain insert and report success.
            $this->affectingStatement($this->grammar->compileInsert($query, $values), $this->flattenInsertValues($values));
            return null;
        }

        // The compile-shaped capability contract: the grammar returns the
        // statement PLUS whether that statement yields the key (a RETURNING
        // dialect compiles the clause in; MySQL compiles without it). The
        // connection never probes a boolean — it reads the compile result.
        $compiled = $this->grammar->compileInsertForId($query, $values, $pk);
        $bindings = $this->flattenInsertValues($values);

        if ($compiled['returnsKey']) {
            $row = $this->selectSql($compiled['sql'], $bindings)->first();
            if ($row === null) {
                return null;
            }
            $id = $this->codec->decode($row->{$pk});
            return is_int($id) || is_string($id) ? $id : null;
        }

        $this->statement($compiled['sql'], $bindings);
        // The lastInsertId() fallback is reachable ONLY on dialects without
        // RETURNING (MySQL, and old SQLite) — Postgres' grammar always uses
        // RETURNING, so its sequence-based lastval() hazards never apply
        // here. On MySQL lastInsertId() is connection-scoped and unaffected
        // by concurrent inserts on other connections. PDO always returns a
        // string (or false when there is no generated id); the codec passes
        // strings through untouched, so no decode is needed.
        $id = $this->pdo->lastInsertId();
        if ($id === false) {
            return null;
        }

        // lastInsertId() is only meaningful for an AUTO_INCREMENT/SERIAL
        // column: a caller-declared non-auto-increment PK (UUID, char, or a
        // PK the row value supplies) generates nothing server-side, so the
        // value here is a stale id from an EARLIER insert on this connection
        // (or '0'). Returning it would hand the caller a key that does not
        // identify the row just written — fail fast instead.
        if (!$query->isInsertIdAutoIncrement()) {
            throw new \LogicException(
                "insertGetId() declared key column [{$pk}] is not auto-increment — no id is generated"
                . ' server-side, so lastInsertId() would return a stale value from an earlier insert.'
                . ' Assign the key before inserting and use insert().'
            );
        }

        return $id;
    }

    /**
     * Update the rows matching the query's conditions.
     *
     * @param QueryBuilder $query The query whose conditions select the rows to update.
     * @param array<string,mixed> $values The columns to change and their new values.
     * @return int How many rows were updated.
     */
    #[Override]
    final public function update(QueryBuilder $query, array $values): int
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
    final public function delete(QueryBuilder $query): int
    {
        $sql = $this->grammar->compileDelete($query);
        return $this->affectingStatement($sql, $query->getBindings([BindingCategory::Join, BindingCategory::Where]));
    }

    /**
     * Run a compiled builder query and yield each matching row as it
     * arrives — the streaming counterpart of {@see select()}.
     *
     * Compiles the builder exactly as {@see select()} does, but fetches row
     * by row so PHP-side memory stays bounded by one row, not the result
     * size. Use it when a fluent query may match more rows than fit in
     * memory comfortably. Consuming rules are the same as
     * {@see cursorSql()} — finish the generator (or let it be collected)
     * before the next query on this connection.
     *
     * @param QueryBuilder $query The query to stream.
     * @return \Generator<int, \stdClass> The matching rows, one at a time.
     * @throws QueryException When the statement fails to prepare or execute.
     */
    #[Override]
    final public function cursor(QueryBuilder $query): \Generator
    {
        $sql = $this->grammar->compileSelect($query);
        return $this->cursorSql($sql, $query->getBindings());
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
     * Run a raw SQL query and return the FIRST selected column's values.
     *
     * The columnar counterpart of {@see selectSql()}: the driver fetches the
     * single column directly (`PDO::FETCH_COLUMN`), so no per-row object is
     * materialized and no property lookup runs — the same result shape
     * {@see selectColumn()} produces for a builder query, for ad-hoc SQL.
     *
     * @param string $sql The raw SQL to run.
     * @param array<string|int, mixed> $bindings The values to bind, keyed by
     *        column (named) or position (unnamed).
     * @return Collection<int, mixed> The first selected column's values, one per row.
     */
    final public function selectColumnSql(string $sql, array $bindings = []): Collection
    {
        /** @var list<mixed> $column */
        $column = $this->run($sql, $bindings, fn(\PDOStatement $stmt) => $stmt->fetchAll(\PDO::FETCH_COLUMN, 0));
        return Collection::make($column);
    }

    /**
     * Run a raw SQL query and yield each matching row as it arrives.
     *
     * Unlike {@see selectSql()}, which materializes the whole result set as
     * PHP objects, this fetches row by row: PHP-side memory stays O(1) in
     * the result size regardless of row count. (The driver's client-side
     * buffer is a separate matter — see the note on buffered mode below.)
     *
     * Consume the generator fully (or let it be garbage collected) before
     * running another query on this connection — an unfinished cursor holds
     * the statement. All bundled connectors default to *buffered* mode, so
     * the result set is already fully transferred at execute time and a
     * second query works fine; the restriction only bites when a user opts
     * into MySQL's unbuffered mode via `options` — there, a second query is
     * forbidden until the first result set is drained.
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

            // Bind with an EXPLICIT PDO type. Without one, PDO defaults to
            // PARAM_STR — and an expression like `count(*) > ?` then
            // compares against the string '1', which SQLite evaluates as
            // text-vs-number and always false. Typed columns survive the
            // string bind via column affinity; aggregate expressions have
            // no affinity to save them. (int→PARAM_INT, float→PARAM_STR —
            // PDO has no float type and SQLite compares numerically anyway,
            // bool→PARAM_INT, null→PARAM_NULL.)
            $type = match (true) {
                is_int($value) => \PDO::PARAM_INT,
                is_bool($value) => \PDO::PARAM_INT,
                $value === null => \PDO::PARAM_NULL,
                default => \PDO::PARAM_STR,
            };

            $stmt->bindValue(is_int($key) ? $key + 1 : $key, $value, $type);
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
     * @return TGrammar The grammar used to compile queries.
     */
    abstract protected function getDefaultQueryGrammar(): Grammar;

    /**
     * The default schema grammar for this connection — subclasses override
     * to provide the dialect's DDL compilation (identifier quoting, type
     * mapping, auto-increment clause).
     *
     * @return TSchemaGrammar The grammar used to compile schema changes.
     */
    abstract protected function getDefaultSchemaGrammar(): SchemaGrammar;

    /**
     * The dialect's live-schema reader — the factory hook for
     * {@see $schemaInspector}, the read-side twin of the schema grammar.
     *
     * @return TSchemaInspector The inspector used to read the live schema.
     */
    abstract protected function getDefaultSchemaInspector(): \BlueprintAU\Radiant\Database\Schema\Inspectors\SchemaInspector;

    /**
     * Whether this dialect's DDL is transactional — schema statements can
     * run inside a transaction and roll back on failure.
     *
     * SQLite and Postgres: YES (transactional DDL — a failed multi-change
     * apply rolls back cleanly). MySQL: NO (every DDL statement performs
     * an implicit commit — a mid-apply failure leaves earlier changes
     * applied, and wrapping them in a transaction would silently commit
     * them one by one while appearing atomic).
     *
     * The synchronizer's `transactional` option gates on this.
     *
     * @return bool True when DDL can run inside a transaction.
     */
    public function supportsTransactionalDdl(): bool
    {
        return false;
    }

    // ---- Schema operations (SQL-only) ----

    /**
     * Create a table from a blueprint, plus any indexes declared on it.
     *
     * The table name comes from the blueprint itself — one source of truth.
     *
     * @param Blueprint $blueprint The table and columns to create.
     */
    final public function create(Blueprint $blueprint): void
    {
        $this->statement($this->schemaGrammar->compileCreate($blueprint));

        foreach ($this->schemaGrammar->compileIndexes($blueprint) as $indexSql) {
            $this->statement($indexSql);
        }
    }

    /**
     * Alter a table — add or drop columns.
     *
     * The table name comes from the blueprint itself — one source of truth.
     * The operation picks the compile root (one public compiler per SQL
     * statement — the grammar has no operation-enum dispatch).
     *
     * @param SchemaOperation $operation The operation to perform.
     * @param Blueprint $blueprint The table and columns involved.
     */
    final public function alter(SchemaOperation $operation, Blueprint $blueprint): void
    {
        $this->statement(match ($operation) {
            SchemaOperation::AddColumn => $this->schemaGrammar->compileAddColumns($blueprint),
            SchemaOperation::DropColumn => $this->schemaGrammar->compileDropColumns($blueprint),
            default => throw new \LogicException(
                "Operation [{$operation->value}] is not a column alter; use the "
                . 'dedicated create/drop/rebuildIndexes paths.'
            ),
        });
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

    /**
     * Apply a differ-produced change — dispatches create/alter/drop
     * so the host never writes the match itself. The `create` case routes
     * through {@see create()} so declared indexes are emitted too.
     *
     * NOTE: applying changes is NOT serialized across processes by itself.
     * Wrap the whole `diff → apply` loop in a cross-process lock —
     * {@see withLock()} (schema-sync convention: the name `'radiant:schema'`)
     * or a {@see \BlueprintAU\Radiant\Database\Locks\Lock} adapter — when
     * more than one deployment instance can migrate concurrently.
     *
     * @param \BlueprintAU\Radiant\Database\Schema\SchemaChange $change The change to apply.
     */
    final public function apply(\BlueprintAU\Radiant\Database\Schema\SchemaChange $change): void
    {
        match ($change->operation) {
            SchemaOperation::CreateTable => $this->create($change->blueprint),
            SchemaOperation::AddColumn, SchemaOperation::DropColumn => $this->alter($change->operation, $change->blueprint),
            SchemaOperation::DropTable => $this->drop($change->table),
            SchemaOperation::AlterIndexes => $this->rebuildIndexes($change->blueprint),
            SchemaOperation::RenameTable => $this->renameTable($change->blueprint->getRenamedFrom() ?? throw new \LogicException(
                "A RenameTable change for [{$change->table}] carries no renamedFrom declaration."
            ), $change->table),
            SchemaOperation::RenameColumn => $this->applyColumnRenames($change->blueprint),
            SchemaOperation::ModifyColumn => $this->modifyColumn($change->blueprint),
            SchemaOperation::AddForeignKey => $this->addForeignKey($change->table, $change->blueprint),
            SchemaOperation::DropForeignKey => $this->dropForeignKey($change->table, $change->blueprint),
            SchemaOperation::AddCheck => $this->addCheck($change->table, $change->blueprint),
            SchemaOperation::DropCheck => $this->dropCheck($change->table, $change->blueprint),
        };
    }

    /**
     * Rename a table.
     *
     * Portable across all three dialects (`ALTER TABLE ... RENAME TO`).
     * Non-destructive: the table and every row move together; indexes and
     * constraints travel with the table.
     *
     * @param string $from The live table name.
     * @param string $to The new table name.
     */
    final public function renameTable(string $from, string $to): void
    {
        $this->statement($this->schemaGrammar->compileRenameTable($from, $to));
    }

    /**
     * Rename a column on a table.
     *
     * MySQL 8.0+, Postgres, and SQLite 3.25+ all support `RENAME COLUMN`
     * with the same syntax. Non-destructive: the column's data travels
     * with the rename.
     *
     * @param string $table The table the column is on.
     * @param string $from The live column name.
     * @param string $to The new column name.
     */
    final public function renameColumn(string $table, string $from, string $to): void
    {
        $this->statement($this->schemaGrammar->compileRenameColumn($table, $from, $to));
    }

    /**
     * Apply every column rename declared on the blueprint, in declaration
     * order — the `RenameColumn` change's execution body.
     *
     * @param Blueprint $blueprint The blueprint carrying the renames.
     */
    private function applyColumnRenames(Blueprint $blueprint): void
    {
        foreach ($blueprint->getColumnRenames() as $rename) {
            $this->renameColumn($blueprint->getTable(), $rename['from'], $rename['to']);
        }
    }

    /**
     * Modify one or more columns in place — the content-drift path.
     *
     * The base executes the grammar's `compileModifyColumn()` statements
     * (MySQL `MODIFY`, Postgres `ALTER COLUMN` clauses). Dialects without
     * an in-place form (SQLite) OVERRIDE this method to route through
     * {@see rebuildTable()} instead — the grammar's base throw is never
     * reached on those dialects.
     *
     * @param Blueprint $blueprint The table-bound blueprint carrying the
     *        desired (modified) column shapes.
     */
    public function modifyColumn(Blueprint $blueprint): void
    {
        foreach ($this->schemaGrammar->compileModifyColumn($blueprint) as $sql) {
            $this->statement($sql);
        }
    }

    /**
     * Add a foreign-key constraint to an existing table.
     *
     * The base executes the grammar's `compileAddForeignKey()` (MySQL and
     * Postgres). SQLite overrides to route through {@see rebuildTable()}.
     * The change's blueprint carries the constraint shape AND its final
     * name (derived at declaration — the same doctrine as indexes and
     * checks); the connection renders it verbatim, no naming decisions.
     *
     * @param string $table The table to attach the constraint to.
     * @param Blueprint $blueprint The blueprint carrying the FK shape.
     */
    public function addForeignKey(string $table, Blueprint $blueprint): void
    {
        $foreignKeys = $blueprint->getForeignKeys();
        $first = $foreignKeys[0] ?? throw new \LogicException(
            "An AddForeignKey change for [{$table}] carries no foreign key declaration."
        );

        $this->statement($this->schemaGrammar->compileAddForeignKey(
            $table,
            $first,
            $first['name'],
        ));
    }

    /**
     * Drop a foreign-key constraint from an existing table.
     *
     * The base executes the grammar's `compileDropForeignKey()` (MySQL
     * and Postgres). SQLite overrides to route through
     * {@see rebuildTable()}. The blueprint carries the constraint name to
     * drop (the live handle captured by the inspector).
     *
     * @param string $table The table the constraint is on.
     * @param Blueprint $blueprint The blueprint carrying the drop target.
     */
    public function dropForeignKey(string $table, Blueprint $blueprint): void
    {
        $names = $blueprint->getDropForeignKeys();
        $name = $names[0] ?? throw new \LogicException(
            "A DropForeignKey change for [{$table}] carries no constraint name."
        );

        $this->statement($this->schemaGrammar->compileDropForeignKey($table, $name));
    }

    /**
     * Add a CHECK constraint to an existing table.
     *
     * The base executes the grammar's `compileAddCheck()` (MySQL and
     * Postgres). SQLite overrides to route through {@see rebuildTable()}.
     *
     * @param string $table The table to attach the constraint to.
     * @param Blueprint $blueprint The blueprint carrying the CHECK shape.
     */
    public function addCheck(string $table, Blueprint $blueprint): void
    {
        $checks = $blueprint->getChecks();
        $check = $checks[0] ?? throw new \LogicException(
            "An AddCheck change for [{$table}] carries no CHECK declaration."
        );

        // Every CHECK carries a final name (derived at declaration when
        // omitted) — the name is the drop handle for a later drop.
        $name = $check['name'];

        $this->statement($this->schemaGrammar->compileAddCheck($table, $name, $check['expression']));
    }

    /**
     * Drop a CHECK constraint from an existing table.
     *
     * The base executes the grammar's `compileDropCheck()` (Postgres).
     * MySQL and SQLite override or route through {@see rebuildTable()}.
     *
     * @param string $table The table the constraint is on.
     * @param Blueprint $blueprint The blueprint carrying the drop target.
     */
    public function dropCheck(string $table, Blueprint $blueprint): void
    {
        $names = $blueprint->getDropChecks();
        $name = $names[0] ?? throw new \LogicException(
            "A DropCheck change for [{$table}] carries no constraint name."
        );

        $this->statement($this->schemaGrammar->compileDropCheck($table, $name));
    }

    /**
     * Rebuild a table's indexes — drop each index named on the blueprint
     * (a differ-produced `AlterIndexes` change carries exactly the drifted
     * indexes), then re-create it from the blueprint's declaration, options
     * included (`WHERE` predicate, `NULLS NOT DISTINCT`).
     *
     * Drops run before creates so a re-created index never collides with
     * its stale self. Non-destructive: index rebuilds never touch rows.
     *
     * @param Blueprint $blueprint The indexes to rebuild (table-bound).
     */
    final public function rebuildIndexes(Blueprint $blueprint): void
    {
        foreach ($blueprint->getIndexes() as $index) {
            $this->statement($this->schemaGrammar->compileDropIndex($index['name'], $blueprint->getTable()));
        }

        foreach ($this->schemaGrammar->compileIndexes($blueprint) as $indexSql) {
            $this->statement($indexSql);
        }
    }

    /**
     * Run the callback while holding a cross-process lock taken on THIS
     * connection — the general serialization gate for anything that must
     * not run twice concurrently (the schema `diff → apply` loop, cron
     * overlap, cache warmups).
     *
     * `$name` is the mutual-exclusion domain: one name is one lock, so
     * distinct jobs use distinct names and never serialize each other. The
     * schema-sync convention is `'radiant:schema'` — only code doing schema
     * work should pass it.
     *
     * Each dialect takes the lock natively: MySQL `GET_LOCK`/`RELEASE_LOCK`,
     * Postgres a session advisory lock, SQLite a `BEGIN IMMEDIATE` write
     * transaction (which serializes ALL writes and refuses to run inside an
     * already-open transaction). The lock is held on THIS connection, so
     * the guarded work must run on this same connection — pass a closure
     * that closes over `$this` (or use the facade while this connection is
     * current).
     *
     * @template TReturn
     *
     * @param callable(): TReturn $callback The work to run under lock.
     * @param string $name The lock domain — distinct jobs, distinct names.
     * @return TReturn The callback's return value.
     * @throws UnsupportedFeatureException When the dialect has no native
     *         cross-process lock (overridable — supply a Lock adapter then).
     * @throws \Throwable Whatever the callback throws, after releasing the lock.
     */
    public function withLock(callable $callback, string $name): mixed
    {
        throw new UnsupportedFeatureException(
            'This dialect does not provide a native cross-process lock; supply a Lock adapter.',
        );
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
     * Monotonic savepoint sequence — makes savepoint names unique.
     *
     * Depth-based names (`trans2`) collided when one connection served
     * interleaved nested transactions from two coroutines: both frames
     * would create `trans2`, and a `rollBack()` from one frame rolled back
     * the other's unit of work. Names now carry a per-connection sequence
     * number, so every frame's savepoint is distinct.
     *
     * @var int
     */
    private int $savepointSequence = 0;

    /**
     * The savepoint created by the currently-innermost open nested frame,
     * per depth (depth => name). commit/rollBack need the name of THE
     * savepoint that frame created — with unique names this is tracked at
     * creation time, not re-derived from depth.
     *
     * @var array<int, string>
     */
    private array $savepointsByLevel = [];

    /**
     * The coroutine that opened the current transaction.
     *
     * Null when no transaction is open. When a transaction is open and a
     * DIFFERENT coroutine calls begin/commit/rollback on this connection,
     * the guard throws — see the class docblock's coroutine contract. The
     * identifier is best-effort: a fiber's object id, a Swoole coroutine
     * id, or the process id when no coroutine runtime is detected (in
     * which case every caller matches and the guard is inert — classic
     * FPM behavior is unchanged).
     *
     * @var string|null
     */
    private ?string $transactionOwner = null;

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
     *
     * When a transaction is already open on this connection and the caller
     * is a different coroutine than the one that opened it, this throws —
     * the level counter and savepoint registry are shared connection state,
     * and interleaved frames would cross-commit each other's work. See the
     * class docblock's coroutine contract.
     *
     * @throws \LogicException When a different coroutine touches an open
     *         transaction on this connection.
     */
    final public function beginTransaction(): void
    {
        $this->assertSameCoroutine('beginTransaction');
        $toLevel = $this->transactionLevel + 1;
        if ($toLevel === 1) {
            $this->pdo->beginTransaction();
            $this->transactionOwner = $this->coroutineId();
        } elseif ($this->supportsSavepoints()) {
            // Unique per-frame name (depth + sequence): depth alone collides
            // when interleaved coroutine frames nest on one connection.
            $name = 'trans' . $toLevel . '_' . (++$this->savepointSequence);
            $this->createSavepoint($name);
            $this->savepointsByLevel[$toLevel] = $name;
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
     *
     * A failed top-level commit is reconciled with a best-effort server
     * rollback before the exception propagates: a deferred-constraint
     * violation or a deadlock kill aborts the *transaction*, not the
     * *connection* — the server still holds the aborted transaction, and
     * the next `beginTransaction()` on it would fail (or silently nest)
     * for the rest of the process on a long-running runtime. Clearing it
     * makes the connection reusable; the original exception is what the
     * caller sees.
     */
    final public function commit(): void
    {
        $this->assertSameCoroutine('commit');
        $toLevel = $this->transactionLevel - 1;
        $this->transactionLevel = $toLevel;
        if ($toLevel === 0) {
            try {
                $this->pdo->commit();
            } catch (\PDOException $e) {
                $this->reconcileFailedCommit();
                throw $e;
            }
            $this->transactionOwner = null;
        } elseif ($this->supportsSavepoints()) {
            $this->releaseSavepoint($this->savepointNameFor($toLevel + 1));
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
        $this->assertSameCoroutine('rollBack');
        $toLevel = $this->transactionLevel - 1;
        $this->transactionLevel = $toLevel;
        if ($toLevel === 0) {
            try {
                $this->pdo->rollBack();
            } catch (\PDOException $e) {
                $this->reconcileFailedCommit();
                throw $e;
            }
            $this->transactionOwner = null;
        } elseif ($this->supportsSavepoints()) {
            $this->rollbackToSavepoint($this->savepointNameFor($toLevel + 1));
        }
    }

    /**
     * Best-effort clear of a server-side transaction after a failed
     * top-level commit/rollback.
     *
     * Both Postgres ("current transaction is aborted") and MySQL accept a
     * rollback of an already-aborted transaction, so this succeeds in the
     * deferred-constraint/deadlock cases and silently no-ops in the
     * connection-drop case (where it throws — discarded: the original
     * failure is the caller's problem, and a dead connection is evicted by
     * the staleness machinery anyway).
     */
    private function reconcileFailedCommit(): void
    {
        try {
            $this->pdo->rollBack();
        } catch (\Throwable) {
            // Nothing more can be done here — the connection is dead or the
            // transaction is already gone; staleness handling takes over.
        }
    }

    /**
     * The current coroutine's identity, best-effort.
     *
     * Distinguishes fibers, Swoole coroutines, and (when neither is
     * detected) collapses to the process id — under classic FPM every
     * caller is the same "coroutine", so the ownership guard is inert and
     * adds no overhead beyond the comparison.
     *
     * @return string A stable identifier for the current execution
     *         context.
     */
    private function coroutineId(): string
    {
        if (\class_exists(\Fiber::class)) {
            $fiber = \Fiber::getCurrent();
            if ($fiber !== null) {
                return 'fiber:' . (string) \spl_object_id($fiber);
            }
        }
        if (\class_exists('Swoole\Coroutine')
            && ($cid = \Swoole\Coroutine::getCid()) > 0
        ) {
            return 'swoole:' . (string) $cid;
        }
        $pid = getmypid();
        return 'proc:' . ($pid === false ? 'unknown' : (string) $pid);
    }

    /**
     * Fail fast when a different coroutine touches an open transaction.
     *
     * The guard converts the silent cross-commit/cross-rollback race into a
     * loud contract violation. Only fires when a transaction is open AND
     * the caller's coroutine identity differs from the owner's; under a
     * non-coroutine runtime every caller resolves to the same process id,
     * so the check always passes.
     *
     * @param string $operation The operation name for the error message.
     * @throws \LogicException When the coroutine contract is violated.
     */
    private function assertSameCoroutine(string $operation): void
    {
        if ($this->transactionLevel > 0
            && $this->transactionOwner !== null
            && $this->transactionOwner !== $this->coroutineId()
        ) {
            throw new \LogicException(
                "{$operation}() called from a different coroutine than the one that opened"
                . ' the transaction. Connections are not coroutine-safe while a transaction'
                . ' is open — use one connection per coroutine.'
            );
        }
    }

    /**
     * The savepoint name the frame at a given depth created, forgetting it.
     *
     * @param int $level The depth of the frame being closed.
     * @return string The savepoint name.
     */
    private function savepointNameFor(int $level): string
    {
        $name = $this->savepointsByLevel[$level]
            ?? 'trans' . $level;

        unset($this->savepointsByLevel[$level]);

        return $name;
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

    /**
     * Best-effort rollback of an abandoned transaction at teardown.
     *
     * The `transaction()` helper already rolls back on exception; the MANUAL
     * begin/commit/rollback API does not — a host exception that escapes
     * without `rollBack()` leaves the transaction (and its row locks) open
     * on a connection the manager caches, effectively forever under a
     * long-running worker. The destructor reclaims it: rolling back on GC
     * releases locks and unpoisons the connection's depth counter for the
     * next borrower. It never throws — a destructor must not.
     *
     * @return void
     */
    public function __destruct()
    {
        if ($this->transactionLevel === 0) {
            return;
        }
        try {
            $this->transactionLevel = 0;
            $this->pdo->rollBack();
        } catch (\Throwable) {
            // Teardown is best-effort: the connection may already be dead
            // (which also releases the server-side transaction).
        }
    }
}
