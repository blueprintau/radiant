<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Connections;

use BlueprintAU\Radiant\Database\Grammars\Grammar;
use BlueprintAU\Radiant\Database\Grammars\SqliteGrammar;
use BlueprintAU\Radiant\Database\Schema\Blueprint;
use BlueprintAU\Radiant\Database\Schema\Grammars\SchemaGrammar;
use BlueprintAU\Radiant\Database\Schema\Inspectors\SchemaInspector;
use BlueprintAU\Radiant\Database\Schema\Inspectors\SqliteSchemaInspector;
use BlueprintAU\Radiant\Database\Schema\Grammars\SqliteSchemaGrammar;
use Override;

/**
 * A database connection backed by SQLite.
 *
 * SQLite supports savepoints for nested transactions (like Postgres), so
 * all four transaction hooks map onto `SAVEPOINT`, `RELEASE SAVEPOINT` and
 * `ROLLBACK TO SAVEPOINT`.
 *
 * @see SqlConnection
 *
 * @extends SqlConnection<\BlueprintAU\Radiant\Database\Grammars\SqliteGrammar, \BlueprintAU\Radiant\Database\Schema\Grammars\SqliteSchemaGrammar, \BlueprintAU\Radiant\Database\Schema\Inspectors\SqliteSchemaInspector>
 */
final class SqliteConnection extends SqlConnection
{
    /**
     * The default query grammar for this connection.
     *
     * @return Grammar The SQLite grammar.
     */
    protected function getDefaultQueryGrammar(): Grammar
    {
        return new SqliteGrammar();
    }

    /**
     * The default schema grammar for this connection.
     *
     * @return SchemaGrammar The SQLite schema grammar.
     */
    protected function getDefaultSchemaGrammar(): SchemaGrammar
    {
        return new SqliteSchemaGrammar();
    }

    /**
     * The dialect's live-schema reader ({@see SchemaInspector}).
     *
     * @return SqliteSchemaInspector The live-schema inspector.
     */
    protected function getDefaultSchemaInspector(): SchemaInspector
    {
        return new SqliteSchemaInspector($this->pdo);
    }

    /**
     * Modify columns on SQLite — routed through the table rebuild.
     *
     * SQLite has NO in-place `ALTER COLUMN` form; the data-preserving
     * answer is the rebuild sequence (create temp → copy → drop old →
     * rename → indexes → integrity gate). The base
     * {@see SqlConnection::modifyColumn()} would reach the grammar's
     * fail-fast throw; this override never lets it get there.
     *
     * @param Blueprint $blueprint The table-bound blueprint carrying the
     *        desired (modified) column shapes.
     */
    #[Override]
    public function modifyColumn(Blueprint $blueprint): void
    {
        $this->rebuildTable($blueprint);
    }

    /**
     * Add a foreign-key constraint on SQLite — routed through the table
     * rebuild.
     *
     * SQLite has no in-place constraint ALTER; the rebuild's temp CREATE
     * already renders the full desired FK set, so the rebuild IS the
     * add.
     *
     * @param string $table The table to attach the constraint to.
     * @param Blueprint $blueprint The blueprint carrying the FK shape.
     */
    #[Override]
    public function addForeignKey(string $table, Blueprint $blueprint): void
    {
        $this->rebuildTable($blueprint);
    }

    /**
     * Drop a foreign-key constraint on SQLite — routed through the table
     * rebuild.
     *
     * The rebuild's temp CREATE renders the DESIRED FK set; the differ
     * builds the change's blueprint with the dropped constraint removed
     * from the declared set, so the rebuild IS the drop.
     *
     * @param string $table The table the constraint is on.
     * @param Blueprint $blueprint The blueprint carrying the desired
     *        (post-drop) FK set.
     */
    #[Override]
    public function dropForeignKey(string $table, Blueprint $blueprint): void
    {
        $this->rebuildTable($blueprint);
    }

    /**
     * Add a CHECK constraint on SQLite — routed through the table
     * rebuild (same mechanism as FK adds).
     *
     * @param string $table The table to attach the constraint to.
     * @param Blueprint $blueprint The blueprint carrying the CHECK shape.
     */
    #[Override]
    public function addCheck(string $table, Blueprint $blueprint): void
    {
        $this->rebuildTable($blueprint);
    }

    /**
     * Drop a CHECK constraint on SQLite — routed through the table
     * rebuild (same mechanism as FK drops).
     *
     * @param string $table The table the constraint is on.
     * @param Blueprint $blueprint The blueprint carrying the desired
     *        (post-drop) CHECK set.
     */
    #[Override]
    public function dropCheck(string $table, Blueprint $blueprint): void
    {
        $this->rebuildTable($blueprint);
    }

    /**
     * Rebuild a table — the data-preserving answer to every change SQLite
     * cannot make in place (content drift, FK/CHECK changes).
     *
     * ORCHESTRATION, not compilation: the grammar compiles the statement
     * sequence ({@see SqliteSchemaGrammar::compileRebuildTable()}); THIS
     * method owns the execution strategy the compiled list cannot express
     * — the transaction straddle (SQLite's transactional DDL makes the
     * whole rebuild atomic, unlike Laravel's non-transactional rebuild),
     * the `foreign_key_check` verification gate before commit, and the
     * PRAGMA read-and-restore.
     *
     * The PRAGMA toggle is CONDITIONAL: it runs only when the table
     * participates in at least one FK relationship (as parent or child —
     * checked via the inspector) AND the connection currently has
     * enforcement ON. A standalone-table rebuild never touches global
     * connection state. The prior value is restored exactly — never
     * assumed.
     *
     * Sequence: PRAGMA off (conditional) → BEGIN → create temp (full
     * desired schema) → copy live rows → drop old → rename temp →
     * re-create indexes from the ORIGINAL blueprint (after the rename, so
     * derived names carry the final table name) → `foreign_key_check`
     * must be empty (else ROLLBACK + throw) → COMMIT → PRAGMA restore.
     *
     * @param Blueprint $desired The desired-state blueprint (bound to the
     *        final table name).
     * @throws \Throwable When any statement fails or the integrity check
     *         finds violations — the transaction rolls back, the table is
     *         untouched.
     */
    private function rebuildTable(Blueprint $desired): void
    {
        $table = $desired->getTable();

        // FK involvement decides the PRAGMA toggle: the table itself
        // declares FKs, OR any live table references it (it is a parent —
        // one inspector query, not an N+1 loop over full snapshots).
        $live = $this->schemaInspector->table($table);
        $involvesForeignKeys = $live->foreignKeys !== []
            || $this->schemaInspector->referencingTables($table) !== [];

        $foreignKeyConstraintsEnabled = false;

        if ($involvesForeignKeys) {
            $statement = $this->pdo->query('PRAGMA foreign_keys');

            if ($statement === false) {
                throw new \RuntimeException('Could not read the SQLite foreign_keys pragma.');
            }

            $row = $statement->fetch(\PDO::FETCH_OBJ);
            $foreignKeyConstraintsEnabled = $row !== false && (int) $row->{'foreign_keys'} === 1;
        }

        // The temp name: validated for the dialect and checked absent.
        $tempName = $table . '__radiant_new';
        $this->schemaGrammar->assertValidIdentifier($tempName);

        if ($this->schemaInspector->hasTable($tempName)) {
            throw new \LogicException(
                "Cannot rebuild [{$table}]: the temp table [{$tempName}] already exists."
            );
        }

        // The live column names — the copy projection is the INTERSECTION
        // with the desired shape (computed by the grammar's compile).
        $liveColumns = array_map(fn (array $column) => $column['name'], $live->columns);

        $statements = $this->schemaGrammar->compileRebuildTable(
            $desired,
            $tempName,
            $liveColumns,
            $foreignKeyConstraintsEnabled,
        );

        // The PRAGMA toggle MUST run OUTSIDE the transaction — it is a
        // no-op inside one (SQLite docs). The compiled list carries the
        // PRAGMAs at its edges; peel them off and run them around the
        // transaction straddle. A rebuild that NEEDS the toggle but is
        // ALREADY inside a transaction (e.g. the synchronizer's
        // transactional apply) cannot toggle — fail fast rather than
        // silently running the drop under enforcement (CASCADE children
        // would lose rows).
        $pragmaOff = null;
        $pragmaOn = null;

        if ($statements[0] === 'PRAGMA foreign_keys = OFF') {
            $pragmaOff = array_shift($statements);
        }

        if (count($statements) > 0 && $statements[count($statements) - 1] === 'PRAGMA foreign_keys = ON') {
            $pragmaOn = array_pop($statements);
        }

        if ($pragmaOff !== null && $this->transactionLevel() > 0) {
            throw new \LogicException(sprintf(
                'Cannot rebuild [%s] inside a transaction: the foreign_keys PRAGMA toggle is a no-op '
                . 'inside a transaction, and dropping the table under enforcement would cascade-delete '
                . 'child rows. Run the rebuild outside a transactional apply.',
                $table,
            ));
        }

        if ($pragmaOff !== null) {
            $this->statement($pragmaOff);
        }

        $this->beginTransaction();

        try {
            foreach ($statements as $sql) {
                $this->statement($sql);
            }

            // Indexes re-created from the ORIGINAL blueprint AFTER the
            // rename — derived names carry the final table name.
            foreach ($this->schemaGrammar->compileIndexes($desired) as $indexSql) {
                $this->statement($indexSql);
            }

            // The integrity gate: any FK violation rolls the WHOLE rebuild
            // back — the table is untouched, never silently corrupted.
            $checkStatement = $this->pdo->query('PRAGMA foreign_key_check');

            if ($checkStatement === false) {
                throw new \RuntimeException('Could not run the SQLite foreign_key_check pragma.');
            }

            $violations = $checkStatement->fetchAll(\PDO::FETCH_OBJ);

            if ($violations !== []) {
                throw new \LogicException(sprintf(
                    'Rebuilding [%s] would violate foreign keys: %d row(s) reference missing parents. '
                    . 'The rebuild rolled back; fix the orphaned rows first.',
                    $table,
                    count($violations),
                ));
            }

            $this->commit();
        } catch (\Throwable $exception) {
            $this->rollBack();

            // The PRAGMA was toggled OUTSIDE the transaction — restore it
            // even on the failure path.
            if ($pragmaOn !== null) {
                $this->statement($pragmaOn);
            }

            throw $exception;
        }

        if ($pragmaOn !== null) {
            $this->statement($pragmaOn);
        }
    }

    /**
     * Whether this dialect supports savepoints for nested transactions.
     *
     * @return bool True — SQLite supports `SAVEPOINT` natively.
     */
    protected function supportsSavepoints(): bool
    {
        return true;
    }

    /**
     * SQLite DDL is transactional — schema statements roll back with the
     * transaction (the rebuild relies on this for its atomicity).
     *
     * @return bool True.
     */
    #[Override]
    public function supportsTransactionalDdl(): bool
    {
        return true;
    }

    /**
     * Create a named savepoint.
     *
     * @param string $name The savepoint name.
     */
    protected function createSavepoint(string $name): void
    {
        $this->pdo->exec("SAVEPOINT {$name}");
    }

    /**
     * Release a named savepoint.
     *
     * @param string $name The savepoint name.
     */
    protected function releaseSavepoint(string $name): void
    {
        $this->pdo->exec("RELEASE SAVEPOINT {$name}");
    }

    /**
     * Roll back to a named savepoint.
     *
     * @param string $name The savepoint name.
     */
    protected function rollbackToSavepoint(string $name): void
    {
        $this->pdo->exec("ROLLBACK TO SAVEPOINT {$name}");
    }

    /**
     * Run the callback inside a `BEGIN IMMEDIATE` write transaction —
     * SQLite's native cross-process serialization. The name is accepted
     * for signature parity and ignored: a database-wide write transaction
     * has nothing to name.
     *
     * @template TReturn
     *
     * @param callable(): TReturn $callback The work to run under lock.
     * @param string $name The lock domain (ignored on SQLite).
     * @return TReturn The callback's return value.
     * @throws \Throwable Whatever the callback throws, after rolling back.
     */
    #[Override]
    public function withLock(callable $callback, string $name): mixed
    {
        return (new \BlueprintAU\Radiant\Database\Locks\SqliteLock($this))
            ->withLock($callback, $name);
    }
}