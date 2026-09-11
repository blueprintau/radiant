<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Grammars;

use BlueprintAU\Radiant\Database\Query\Enums\LockType;
use BlueprintAU\Radiant\Database\Query\QueryBuilder;

/**
 * The MySQL dialect of the SQL Grammar.
 *
 * Identifiers are quoted with backticks (embedded backticks doubled). MySQL
 * supports `INSERT ... RETURNING` only from 8.0.19+ with a `RETURNING`
 * clause on `DELETE`/`UPDATE` — plain `INSERT ... RETURNING` is not
 * supported, so {@see usesReturning()} stays false and `insertGetId()` falls
 * back to `lastInsertId()`. Row locks render as `for update` and
 * `lock in share mode`; an offset without a limit is padded with the
 * unsigned-bigint maximum so the offset is accepted.
 */
class MySqlGrammar extends Grammar
{
    /**
     * Wrap an identifier in MySQL backticks.
     *
     * @param string $value The identifier to quote.
     * @return string The quoted identifier.
     */
    protected function wrap(string $value): string
    {
        return '`' . str_replace('`', '``', $value) . '`';
    }

    /**
     * MySQL has no `INSERT ... DEFAULT VALUES` form — the one-row
     * `VALUES ()` fallback compiles instead (MySQL accepts it and applies
     * the column defaults).
     *
     * @return bool False — MySQL uses the `VALUES ()` fallback.
     */
    #[\Override]
    protected function supportsDefaultValues(): bool
    {
        return false;
    }

    /**
     * Compile the offset clause, padding a bare offset with the max limit.
     *
     * MySQL requires a `LIMIT` before `OFFSET`; a bare offset is padded with
     * the unsigned-bigint maximum so the query stays valid.
     *
     * @param QueryBuilder $builder The query to compile.
     * @return string The offset clause, or an empty string when there is none.
     */
    protected function compileOffset(QueryBuilder $builder): string
    {
        if ($builder->getOffset() === null) {
            return '';
        }
        if ($builder->getLimit() === null) {
            return 'LIMIT 18446744073709551615 OFFSET ' . $builder->getOffset();
        }
        return 'OFFSET ' . $builder->getOffset();
    }

    /**
     * Compile the row lock for MySQL.
     *
     * @param QueryBuilder $builder The query to compile.
     * @return string The lock clause, or an empty string when there is none.
     */
    protected function compileLock(QueryBuilder $builder): string
    {
        return match ($builder->getLock()) {
            null => '',
            LockType::Update => 'FOR UPDATE',
            LockType::Shared => 'LOCK IN SHARE MODE',
        };
    }
}