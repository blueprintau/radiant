<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Connections;

use BlueprintAU\Radiant\Database\Grammars\Grammar;
use BlueprintAU\Radiant\Database\Grammars\SqliteGrammar;
use BlueprintAU\Radiant\Database\Schema\SchemaGrammar;
use BlueprintAU\Radiant\Database\Schema\SqliteSchemaGrammar;

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
}