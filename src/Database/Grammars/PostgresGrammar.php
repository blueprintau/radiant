<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Grammars;

use BlueprintAU\Radiant\Database\Query\Enums\LockType;
use BlueprintAU\Radiant\Database\Query\QueryBuilder;

/**
 * The Postgres dialect of the SQL Grammar.
 *
 * Identifiers are quoted with double quotes (embedded quotes doubled).
 * Postgres supports `INSERT ... RETURNING`, so {@see usesReturning()} returns
 * true and {@see compileInsertForId()} compiles the clause in. Row locks
 * render as `for update` and `for share`; a bare offset is
 * valid without a limit.
 */
final class PostgresGrammar extends Grammar
{
    /**
     * Wrap an identifier in Postgres double quotes.
     *
     * @param string $value The identifier to quote.
     * @return string The quoted identifier.
     */
    protected function wrap(string $value): string
    {
        return '"' . str_replace('"', '""', $value) . '"';
    }

    /**
     * Whether the dialect supports `INSERT ... RETURNING`.
     *
     * @return bool True — Postgres supports RETURNING.
     */
    public function usesReturning(): bool
    {
        return true;
    }

    /**
     * Compile the row lock for Postgres.
     *
     * @param QueryBuilder $builder The query to compile.
     * @return string The lock clause, or an empty string when there is none.
     */
    protected function compileLock(QueryBuilder $builder): string
    {
        return match ($builder->getLock()) {
            null => '',
            LockType::Update => 'FOR UPDATE',
            LockType::Shared => 'FOR SHARE',
        };
    }
}