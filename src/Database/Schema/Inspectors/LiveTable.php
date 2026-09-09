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
     * @param list<array{name: string|null, columns: list<string>, unique: bool}> $indexes
     *        The live indexes (unique constraints included; the PK is NOT
     *        an index here — it rides the columns' `primaryKey` flag).
     * @param list<array{columns: list<string>, referencesTable: string, referencesColumns: list<string>, onDelete: string|null, onUpdate: string|null}> $foreignKeys
     *        The live foreign-key constraints.
     */
    public function __construct(
        public readonly string $name,
        public readonly array $columns,
        public readonly array $indexes = [],
        public readonly array $foreignKeys = [],
    ) {
    }
}
