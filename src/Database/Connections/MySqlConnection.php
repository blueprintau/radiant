<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Connections;

use BlueprintAU\Radiant\Database\Grammars\Grammar;
use BlueprintAU\Radiant\Database\Grammars\MySqlGrammar;
use BlueprintAU\Radiant\Database\Schema\Grammars\MySqlSchemaGrammar;
use BlueprintAU\Radiant\Database\Schema\Grammars\SchemaGrammar;
use BlueprintAU\Radiant\Database\Schema\Inspectors\MySqlSchemaInspector;
use BlueprintAU\Radiant\Database\Schema\Inspectors\SchemaInspector;
use Override;

/**
 * A database connection backed by MySQL.
 *
 * MySQL supports savepoints, so all four transaction hooks map onto
 * `SAVEPOINT`, `RELEASE SAVEPOINT` and `ROLLBACK TO SAVEPOINT`. Note that
 * MySQL follows the ANSI SQL standard here: a same-named savepoint is
 * silently replaced, so releasing it is a no-op — only creating and rolling
 * back to one are real SQL statements.
 *
 * @see SqlConnection
 *
 * @extends SqlConnection<\BlueprintAU\Radiant\Database\Grammars\MySqlGrammar, \BlueprintAU\Radiant\Database\Schema\Grammars\MySqlSchemaGrammar, \BlueprintAU\Radiant\Database\Schema\Inspectors\MySqlSchemaInspector>
 */
class MySqlConnection extends SqlConnection
{
    /**
     * The default query grammar for this connection.
     *
     * @return Grammar
     */
    protected function getDefaultQueryGrammar(): Grammar
    {
        return new MySqlGrammar();
    }

    /**
     * The default schema grammar for this connection.
     *
     * @return SchemaGrammar
     */
    protected function getDefaultSchemaGrammar(): SchemaGrammar
    {
        return new MySqlSchemaGrammar();
    }

    /**
     * The dialect's live-schema reader.
     *
     * @return MySqlSchemaInspector
     */
    protected function getDefaultSchemaInspector(): SchemaInspector
    {
        return new MySqlSchemaInspector($this->pdo);
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
     * Deliberately a no-op: MySQL follows the ANSI SQL standard here —
     * `SAVEPOINT name` silently replaces an existing savepoint of the same
     * name rather than pushing onto a stack, so there is no savepoint to
     * release.
     *
     * @param  string  $name
     */
    protected function releaseSavepoint(string $name): void
    {
        // no-op — MySQL replaces same-named savepoints
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
     * Run the callback under MySQL's `GET_LOCK` advisory lock on the given
     * lock domain.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @param  string  $name
     * @return TReturn
     * @throws \Throwable
     */
    #[Override]
    public function withLock(callable $callback, string $name): mixed
    {
        return (new \BlueprintAU\Radiant\Database\Locks\MySqlLock($this))
            ->withLock($callback, $name);
    }
}