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
     * @return Grammar
     */
    protected function getDefaultQueryGrammar(): Grammar
    {
        return new SqliteGrammar();
    }

    /**
     * The default schema grammar for this connection.
     *
     * @return SchemaGrammar
     */
    protected function getDefaultSchemaGrammar(): SchemaGrammar
    {
        return new SqliteSchemaGrammar();
    }

    /**
     * The dialect's live-schema reader.
     *
     * @return SqliteSchemaInspector
     */
    protected function getDefaultSchemaInspector(): SchemaInspector
    {
        return new SqliteSchemaInspector($this->pdo);
    }

    /**
     * Apply a ModifyColumn change through the table rebuild.
     *
     * The rebuild renders the full desired shape (the change's main
     * blueprint), not the drifted subset the in-place dialects compile.
     *
     * @param  \BlueprintAU\Radiant\Database\Schema\SchemaChange  $change
     */
    #[Override]
    protected function applyModifyColumn(\BlueprintAU\Radiant\Database\Schema\SchemaChange $change): void
    {
        $this->modifyColumn($change->blueprint);
    }

    /**
     * Apply a DropColumn change, skipping columns already absent.
     *
     * A prior rebuild on the same table renders the full desired shape,
     * which excludes the dropped columns — a second drop would fail.
     *
     * @param  \BlueprintAU\Radiant\Database\Schema\SchemaChange  $change
     */
    #[Override]
    protected function applyDropColumn(\BlueprintAU\Radiant\Database\Schema\SchemaChange $change): void
    {
        // A null subject falls back to the blueprint's own dropColumn()
        // declarations.
        $subject = $change->subject ?? $change->blueprint->getDropColumns();
        $live = array_column($this->schemaInspector->table($change->table)->columns, 'name');
        $pending = array_values(array_intersect($subject, $live));

        if ($pending === []) {
            return; // Already dropped (a rebuild realized the desired shape).
        }

        parent::applyDropColumn($change);
    }

    /**
     * Apply an AddColumn change, routing a NOT NULL-without-default add
     * through the table rebuild.
     *
     * SQLite cannot add such a column in place to a non-empty table, so
     * the change rebuilds from the full desired blueprint and backfills
     * the existing rows. Every other add stays in place.
     *
     * @param  \BlueprintAU\Radiant\Database\Schema\SchemaChange  $change
     */
    #[Override]
    protected function applyAddColumn(\BlueprintAU\Radiant\Database\Schema\SchemaChange $change): void
    {
        // A null subject acts on every column the blueprint declares.
        $subject = $change->subject ?? array_map(
            fn (array $column) => $column['name'],
            $change->blueprint->getColumns(),
        );
        $live = array_column($this->schemaInspector->table($change->table)->columns, 'name');
        $pending = array_values(array_diff($subject, $live));

        if ($pending === []) {
            return; // Already added (a rebuild realized the desired shape).
        }

        if ($this->addRequiresRebuild($change)) {
            $this->rebuildTable($change->blueprint);
            return;
        }

        parent::applyAddColumn($change);
    }

    /**
     * Whether an add change carries a NOT NULL column without a default.
     *
     * @param  \BlueprintAU\Radiant\Database\Schema\SchemaChange  $change
     * @return bool
     */
    private function addRequiresRebuild(\BlueprintAU\Radiant\Database\Schema\SchemaChange $change): bool
    {
        $subject = $change->subject ?? array_map(
            fn (array $column) => $column['name'],
            $change->blueprint->getColumns(),
        );

        foreach ($change->blueprint->onlyColumns($subject)->getColumns() as $column) {
            if ($column['nullable'] !== true && $column['default'] === null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Modify columns on SQLite — routed through the table rebuild.
     *
     * @param  Blueprint  $blueprint
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
     * @param  string  $table
     * @param  Blueprint  $blueprint
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
     * @param  string  $table
     * @param  Blueprint  $blueprint
     */
    #[Override]
    public function dropForeignKey(string $table, Blueprint $blueprint): void
    {
        $this->rebuildTable($blueprint);
    }

    /**
     * Add a CHECK constraint on SQLite — routed through the table rebuild.
     *
     * @param  string  $table
     * @param  Blueprint  $blueprint
     */
    #[Override]
    public function addCheck(string $table, Blueprint $blueprint): void
    {
        $this->rebuildTable($blueprint);
    }

    /**
     * Drop a CHECK constraint on SQLite — routed through the table rebuild.
     *
     * @param  string  $table
     * @param  Blueprint  $blueprint
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
     * Sequence: PRAGMA off (conditional) → BEGIN → create temp (full
     * desired schema) → copy live rows → drop old → rename temp →
     * re-create indexes → `foreign_key_check` must be empty (else
     * ROLLBACK + throw) → COMMIT → PRAGMA restore.
     *
     * @param  Blueprint  $desired
     * @throws \Throwable
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
     * @return bool
     */
    protected function supportsSavepoints(): bool
    {
        return true;
    }

    /**
     * SQLite DDL is transactional — schema statements roll back with the
     * transaction.
     *
     * @return bool
     */
    #[Override]
    public function supportsTransactionalDdl(): bool
    {
        return true;
    }

    /**
     * Create a named savepoint.
     *
     * @param  string  $name
     */
    protected function createSavepoint(string $name): void
    {
        $this->pdo->exec("SAVEPOINT {$name}");
    }

    /**
     * Release a named savepoint.
     *
     * @param  string  $name
     */
    protected function releaseSavepoint(string $name): void
    {
        $this->pdo->exec("RELEASE SAVEPOINT {$name}");
    }

    /**
     * Roll back to a named savepoint.
     *
     * @param  string  $name
     */
    protected function rollbackToSavepoint(string $name): void
    {
        $this->pdo->exec("ROLLBACK TO SAVEPOINT {$name}");
    }

    /**
     * Run the callback inside a `BEGIN IMMEDIATE` write transaction —
     * SQLite's native cross-process serialization.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @param  string  $name  The lock domain (ignored on SQLite).
     * @return TReturn
     * @throws \Throwable
     */
    #[Override]
    public function withLock(callable $callback, string $name): mixed
    {
        return (new \BlueprintAU\Radiant\Database\Locks\SqliteLock($this))
            ->withLock($callback, $name);
    }
}