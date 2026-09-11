<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Connections;

use BlueprintAU\Radiant\Database\Grammars\Grammar;
use BlueprintAU\Radiant\Database\Grammars\SqliteGrammar;
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
     * Whether this dialect supports savepoints for nested transactions.
     *
     * @return bool True — SQLite supports `SAVEPOINT` natively.
     */
    protected function supportsSavepoints(): bool
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