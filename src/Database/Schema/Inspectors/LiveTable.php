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
     * @param  string  $name
     * @param  list<array{name: string, type: string, nullable: bool, default: mixed, primaryKey: bool}>  $columns
     * @param  list<array{name: string|null, columns: list<string>, unique: bool, where: string|null, nullsNotDistinct: bool}>  $indexes
     * @param  list<array{columns: list<string>, referencesTable: string, referencesColumns: list<string>, onDelete: string|null, onUpdate: string|null, deferrable: bool, name?: string|null}>  $foreignKeys
     * @param  list<array{name: string|null, expression: string|null}>  $checks
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
