<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Connections;

use BlueprintAU\Radiant\Database\Grammars\Grammar;
use BlueprintAU\Radiant\Database\Grammars\PostgresGrammar;
use BlueprintAU\Radiant\Database\Schema\Grammars\PostgresSchemaGrammar;
use BlueprintAU\Radiant\Database\Schema\Grammars\SchemaGrammar;
use BlueprintAU\Radiant\Database\ValueCodecs\PostgresValueCodec;
use BlueprintAU\Radiant\Database\ValueCodecs\ValueCodecInterface;

/**
 * A database connection backed by Postgres.
 *
 * Postgres supports savepoints for nested transactions, so all four
 * transaction hooks map onto `SAVEPOINT`, `RELEASE SAVEPOINT` and
 * `ROLLBACK TO SAVEPOINT`. It also overrides the default value codec with
 * {@see PostgresValueCodec} so datetimes are formatted with microsecond
 * precision, matching Postgres' native `timestamp` type.
 *
 * @see SqlConnection
 */
final class PostgresConnection extends SqlConnection
{
    /**
     * The default query grammar for this connection.
     *
     * @return Grammar The Postgres grammar.
     */
    protected function getDefaultQueryGrammar(): Grammar
    {
        return new PostgresGrammar();
    }

    /**
     * The default schema grammar for this connection.
     *
     * @return SchemaGrammar The Postgres schema grammar.
     */
    protected function getDefaultSchemaGrammar(): SchemaGrammar
    {
        return new PostgresSchemaGrammar();
    }

    /**
     * The default value codec for this connection.
     *
     * Postgres' native `timestamp` stores microseconds, so datetimes are
     * formatted as `Y-m-d H:i:s.u` on the write path.
     *
     * @return ValueCodecInterface The microsecond Postgres codec.
     */
    protected function getDefaultValueCodec(): ValueCodecInterface
    {
        return new PostgresValueCodec();
    }

    /**
     * Whether this dialect supports savepoints for nested transactions.
     *
     * @return bool True — Postgres supports `SAVEPOINT` natively.
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