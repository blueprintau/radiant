<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Connections;

use BlueprintAU\Radiant\Database\Grammars\Grammar;
use BlueprintAU\Radiant\Database\Grammars\MySqlGrammar;
use BlueprintAU\Radiant\Database\Schema\Grammars\MySqlSchemaGrammar;
use BlueprintAU\Radiant\Database\Schema\Grammars\SchemaGrammar;
use BlueprintAU\Radiant\Database\Schema\Inspectors\MySqlSchemaInspector;
use BlueprintAU\Radiant\Database\Schema\Inspectors\SchemaInspector;

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
 */
final class MySqlConnection extends SqlConnection
{
    /**
     * The default query grammar for this connection.
     *
     * @return Grammar The MySQL grammar.
     */
    protected function getDefaultQueryGrammar(): Grammar
    {
        return new MySqlGrammar();
    }

    /**
     * The default schema grammar for this connection.
     *
     * @return SchemaGrammar The MySQL schema grammar.
     */
    protected function getDefaultSchemaGrammar(): SchemaGrammar
    {
        return new MySqlSchemaGrammar();
    }

    /**
     * The dialect's live-schema reader ({@see SchemaInspector}).
     *
     * @return MySqlSchemaInspector The live-schema inspector.
     */
    protected function getDefaultSchemaInspector(): SchemaInspector
    {
        return new MySqlSchemaInspector($this->pdo);
    }

    /**
     * Whether this dialect supports savepoints for nested transactions.
     *
     * @return bool True — MySQL supports `SAVEPOINT` natively.
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
     * This is deliberately a no-op. MySQL follows the ANSI SQL standard
     * here: `SAVEPOINT name` silently *replaces* an existing savepoint of
     * the same name rather than pushing onto a stack, so there is no
     * savepoint to release — the innermost savepoint already carries the
     * name. Issuing `RELEASE SAVEPOINT` would be wrong: it would release
     * the *outermost* savepoint of that name, not the innermost one.
     *
     * @param string $name The savepoint name.
     */
    protected function releaseSavepoint(string $name): void
    {
        // no-op — MySQL replaces same-named savepoints
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