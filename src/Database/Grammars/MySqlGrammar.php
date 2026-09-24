<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Grammars;

use BlueprintAU\Radiant\Database\Query\Enums\LockType;
use BlueprintAU\Radiant\Database\Query\QueryBuilder;

/**
 * The MySQL dialect of the SQL Grammar.
 *
 * Identifiers are quoted with backticks (embedded backticks doubled). Plain
 * `INSERT ... RETURNING` is not supported, so {@see usesReturning()} stays
 * false and `insertGetId()` falls back to `lastInsertId()` via
 * {@see compileInsertForId()}'s `returnsKey` flag. Row locks render as
 * `for update` and `lock in share mode`; an offset without a limit is padded
 * with the unsigned-bigint maximum so the offset is accepted.
 */
final class MySqlGrammar extends Grammar
{
    /**
     * Wrap an identifier in MySQL backticks.
     *
     * @param  string  $value
     * @return string
     */
    protected function wrap(string $value): string
    {
        return '`' . str_replace('`', '``', $value) . '`';
    }

    /**
     * Compile the empty-row insert — the one-row `VALUES ()` fallback.
     *
     * MySQL has no `INSERT ... DEFAULT VALUES` form; it accepts the one-row
     * `VALUES ()` and applies the column defaults.
     *
     * @param  QueryBuilder  $builder
     * @return string
     */
    #[\Override]
    protected function compileEmptyInsert(QueryBuilder $builder): string
    {
        return "INSERT INTO {$this->wrapFromTable($builder)} () VALUES ()";
    }

    /**
     * Compile the offset clause, padding a bare offset with the max limit.
     *
     * @param  QueryBuilder  $builder
     * @return string
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
     * @param  QueryBuilder  $builder
     * @return string
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