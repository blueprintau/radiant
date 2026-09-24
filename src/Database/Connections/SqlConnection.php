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
 * rather than constructing it yourself.
 *
 * A connection is not safe for concurrent use by multiple coroutines while
 * a transaction is open — use one connection per coroutine in that case.
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
     * Reads the live schema — the read-side twin of {@see $schemaGrammar}.
     *
     * @var TSchemaInspector
     */
    public readonly \BlueprintAU\Radiant\Database\Schema\Inspectors\SchemaInspector $schemaInspector;

    /**
     * Create a new SQL connection wrapping a PDO instance.
     *
     * @param  \PDO  $pdo
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
     * @param  ConnectionInterface  $connection
     * @phpstan-assert SqlConnection<Grammar, SchemaGrammar, \BlueprintAU\Radiant\Database\Schema\Inspectors\SchemaInspector> $connection
     */
    final public static function assertSql(ConnectionInterface $connection): void
    {
        if (!$connection instanceof self) {
            throw new UnsupportedFeatureException('The connection is not a SQL connection.');
        }
    }

    /**
     * Narrow a connection to SQL and to this dialect — return it typed, or
     * throw.
     *
     * @param  ConnectionInterface  $connection
     * @return static
     * @throws UnsupportedFeatureException
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
     * @param  string  $identifier
     * @return QueryBuilder
     */
    #[Override]
    final public function table(string $identifier): QueryBuilder
    {
        return new QueryBuilder($this, $identifier);
    }

    /**
     * Run the query and return the matching rows.
     *
     * @param  QueryBuilder  $query
     * @return Collection<int,\stdClass>
     * @throws \LogicException
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
     * Run the query and return the first selected column's values.
     *
     * @param  QueryBuilder  $query
     * @return Collection<int, mixed>
     * @throws QueryException
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
     * @param  QueryBuilder  $query
     * @param  array<string,mixed>|list<array<string,mixed>>  $values
     * @return int
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
     * @param  QueryBuilder  $query
     * @param  array<string,mixed>  $values
     * @return string|int|null
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
     * @param  QueryBuilder  $query
     * @param  array<string,mixed>  $values
     * @return int
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
     * @param  QueryBuilder  $query
     * @return int
     */
    #[Override]
    final public function delete(QueryBuilder $query): int
    {
        $sql = $this->grammar->compileDelete($query);
        return $this->affectingStatement($sql, $query->getBindings([BindingCategory::Join, BindingCategory::Where]));
    }

    /**
     * Run the query and yield each matching row as it arrives.
     *
     * @param  QueryBuilder  $query
     * @return \Generator<int, \stdClass>
     * @throws QueryException
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
     * @param  array<string,mixed>|list<array<string,mixed>>  $values
     * @return list<mixed>
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
     * @param  string  $sql
     * @param  array<string|int, mixed>  $bindings
     * @return Collection<int,\stdClass>
     */
    final public function selectSql(string $sql, array $bindings = []): Collection
    {
        return Collection::make($this->run($sql, $bindings, fn(\PDOStatement $stmt) => $stmt->fetchAll(\PDO::FETCH_OBJ)));
    }

    /**
     * Run a raw SQL query and return the first selected column's values.
     *
     * @param  string  $sql
     * @param  array<string|int, mixed>  $bindings
     * @return Collection<int, mixed>
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
     * Consume the generator fully (or let it be garbage collected) before
     * running another query on this connection — an unfinished cursor holds
     * the statement.
     *
     * @param  string  $sql
     * @param  array<string|int, mixed>  $bindings
     * @return \Generator<int, \stdClass>
     * @throws QueryException
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
     * The callback returning `false` (strictly) stops the iteration
     * immediately; any other return value continues.
     *
     * @param  string  $sql
     * @param  array<string|int, mixed>  $bindings
     * @param  int  $size
     * @param  callable(list<\stdClass>): mixed  $callback
     * @return void
     * @throws \InvalidArgumentException
     * @throws QueryException
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
     * that manage the cursor themselves.
     *
     * @param  string  $sql
     * @param  array<string|int, mixed>  $bindings
     * @return \PDOStatement
     * @throws QueryException
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
     * @param  string  $sql
     * @param  array<string|int, mixed>  $bindings
     */
    final public function statement(string $sql, array $bindings = []): void
    {
        $this->run($sql, $bindings, fn() => null);
    }

    /**
     * Run a raw SQL statement and return how many rows it affected.
     *
     * @param  string  $sql
     * @param  array<string|int, mixed>  $bindings
     * @return int
     */
    final public function affectingStatement(string $sql, array $bindings = []): int
    {
        return $this->run($sql, $bindings, fn(\PDOStatement $stmt) => $stmt->rowCount());
    }

    /**
     * Bind values to the statement, adapting them through the codec.
     *
     * @param  \PDOStatement  $stmt
     * @param  array<string|int, mixed>  $bindings
     * @throws \InvalidArgumentException
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
     * @param  string  $sql
     * @param  array<string|int, mixed>  $bindings
     * @param  callable(\PDOStatement): T  $callback
     * @return T
     * @throws QueryException
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
     * The default codec for this connection.
     *
     * @return ValueCodecInterface
     */
    protected function getDefaultValueCodec(): ValueCodecInterface
    {
        return new DefaultValueCodec();
    }

    /**
     * The default query grammar for this connection.
     *
     * @return TGrammar
     */
    abstract protected function getDefaultQueryGrammar(): Grammar;

    /**
     * The default schema grammar for this connection.
     *
     * @return TSchemaGrammar
     */
    abstract protected function getDefaultSchemaGrammar(): SchemaGrammar;

    /**
     * The dialect's live-schema reader.
     *
     * @return TSchemaInspector
     */
    abstract protected function getDefaultSchemaInspector(): \BlueprintAU\Radiant\Database\Schema\Inspectors\SchemaInspector;

    /**
     * Whether this dialect's DDL is transactional.
     *
     * @return bool
     */
    public function supportsTransactionalDdl(): bool
    {
        return false;
    }

    // ---- Schema operations (SQL-only) ----

    /**
     * Create a table from a blueprint, plus any indexes declared on it.
     *
     * @param  Blueprint  $blueprint
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
     * @param  SchemaOperation  $operation
     * @param  Blueprint  $blueprint
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
     * @param  string  $table
     */
    final public function drop(string $table): void
    {
        $this->statement($this->schemaGrammar->compileDrop($table));
    }

    /**
     * Apply a differ-produced change — dispatches create/alter/drop so the
     * host never writes the match itself.
     *
     * @param  \BlueprintAU\Radiant\Database\Schema\SchemaChange  $change
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
     * @param  string  $from
     * @param  string  $to
     */
    final public function renameTable(string $from, string $to): void
    {
        $this->statement($this->schemaGrammar->compileRenameTable($from, $to));
    }

    /**
     * Rename a column on a table.
     *
     * @param  string  $table
     * @param  string  $from
     * @param  string  $to
     */
    final public function renameColumn(string $table, string $from, string $to): void
    {
        $this->statement($this->schemaGrammar->compileRenameColumn($table, $from, $to));
    }

    /**
     * Apply every column rename declared on the blueprint, in declaration
     * order.
     *
     * @param  Blueprint  $blueprint
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
     * @param  Blueprint  $blueprint
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
     * @param  string  $table
     * @param  Blueprint  $blueprint
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
     * @param  string  $table
     * @param  Blueprint  $blueprint
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
     * @param  string  $table
     * @param  Blueprint  $blueprint
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
     * @param  string  $table
     * @param  Blueprint  $blueprint
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
     * Rebuild a table's indexes — drop each index named on the blueprint,
     * then re-create it from the blueprint's declaration.
     *
     * @param  Blueprint  $blueprint
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
     * Run the callback while holding a cross-process lock taken on this
     * connection.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @param  string  $name  The lock domain — distinct jobs, distinct names.
     * @return TReturn
     * @throws UnsupportedFeatureException
     * @throws \Throwable
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
     * @var int
     */
    private int $transactionLevel = 0;

    /**
     * Monotonic savepoint sequence — makes savepoint names unique.
     *
     * @var int
     */
    private int $savepointSequence = 0;

    /**
     * The savepoint created by the currently-innermost open nested frame,
     * per depth.
     *
     * @var array<int, string>
     */
    private array $savepointsByLevel = [];

    /**
     * The coroutine that opened the current transaction.
     *
     * @var string|null
     */
    private ?string $transactionOwner = null;

    /**
     * The current transaction nesting depth.
     *
     * @return int
     */
    final public function transactionLevel(): int
    {
        return $this->transactionLevel;
    }

    /**
     * Whether this dialect supports savepoints for nested transactions.
     *
     * @return bool
     */
    abstract protected function supportsSavepoints(): bool;

    /**
     * Create a named savepoint.
     *
     * @param  string  $name
     */
    abstract protected function createSavepoint(string $name): void;

    /**
     * Release a named savepoint.
     *
     * @param  string  $name
     */
    abstract protected function releaseSavepoint(string $name): void;

    /**
     * Roll back to a named savepoint.
     *
     * @param  string  $name
     */
    abstract protected function rollbackToSavepoint(string $name): void;

    /**
     * Begin a transaction, nesting via savepoints when supported.
     *
     * @throws \LogicException
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
     * @return string
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
     * @param  string  $operation
     * @throws \LogicException
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
     * @param  int  $level
     * @return string
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
     * @param  callable(SqlConnection): mixed  $callback
     * @return mixed
     * @throws \Throwable
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
