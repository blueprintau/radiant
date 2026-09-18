<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Grammars;

use BlueprintAU\Radiant\Database\Exceptions\UnsupportedFeatureException;

/**
 * The SQLite dialect of the SQL Grammar.
 *
 * Identifiers are quoted with double quotes (embedded quotes doubled).
 * SQLite 3.35+ supports `INSERT ... RETURNING`, so {@see usesReturning()}
 * returns true and {@see compileInsertForId()} compiles the clause in.
 * SQLite has no row-locking syntax, so {@see compileLock()}
 * inherits the base Grammar's {@see UnsupportedFeatureException} — a lock
 * request fails fast rather than silently dropping the lock.
 */
class SqliteGrammar extends Grammar
{
    /**
     * Wrap an identifier in SQLite double quotes.
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
     * @return bool True — SQLite 3.35+ supports RETURNING.
     */
    public function usesReturning(): bool
    {
        return true;
    }
}