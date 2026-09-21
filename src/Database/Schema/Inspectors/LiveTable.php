<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Schema\Inspectors;

/**
 * The live schema of one table, as the differ sees it.
 *
 * A dialect-neutral snapshot — the differ compares this against the
 * desired-state {@see \BlueprintAU\Radiant\Database\Schema\Blueprint}
 * without knowing which dialect produced it.
 */
final class LiveTable
{
    /**
     * Create a live-table snapshot.
     *
     * @param string $name The table name.
     * @param list<array{name: string, type: string, nullable: bool, default: mixed, primaryKey: bool}> $columns
     *        The live columns — `type` is the dialect's native type text.
     * @param list<array{name: string|null, columns: list<string>, unique: bool, where: string|null, nullsNotDistinct: bool}> $indexes
     *        The live indexes (unique constraints included; the PK is NOT
     *        an index here — it rides the columns' `primaryKey` flag).
     *        `where` is the partial-index predicate (parsed from the
     *        dialect's definition text); `nullsNotDistinct` mirrors the
     *        declared option (Postgres only).
     * @param list<array{columns: list<string>, referencesTable: string, referencesColumns: list<string>, onDelete: string|null, onUpdate: string|null, deferrable: bool, name?: string|null}> $foreignKeys
     *        The live foreign-key constraints. `deferrable` mirrors the
     *        declared option (Postgres only; others always false). `name`
     *        is the live constraint name (the drop handle) when the
     *        dialect exposes one.
     * @param list<array{name: string|null, expression: string|null}> $checks
     *        The live CHECK constraints. `expression` is the live
     *        predicate text when the dialect exposes it parseable, null
     *        otherwise (an unparseable expression never matches — the
     *        conservative default).
     */
    public function __construct(
        public readonly string $name,
        public readonly array $columns,
        public readonly array $indexes = [],
        public readonly array $foreignKeys = [],
        public readonly array $checks = [],
    ) {
    }
}
