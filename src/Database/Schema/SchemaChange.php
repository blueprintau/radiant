<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Schema;

use BlueprintAU\Radiant\Database\Schema\Enums\SchemaOperation;

/**
 * One planned schema change, classified for the host's safety gate.
 */
final class SchemaChange
{
    /**
     * Create a schema change.
     *
     * @param  string  $table
     * @param  SchemaOperation  $operation
     * @param  Blueprint  $blueprint  The full desired shape of the table.
     * @param  bool  $destructive  Whether the change can lose data.
     * @param  string  $description
     * @param  bool  $possibleRename  Whether this change is half of a rename-shaped diff.
     * @param  string|null  $renameOf  The paired change's table, when part of a flagged pair.
     * @param  list<string>|null  $subject  The columns this change acts on; null means the whole blueprint.
     */
    public function __construct(
        public readonly string $table,
        public readonly SchemaOperation $operation,
        public readonly Blueprint $blueprint,
        public readonly bool $destructive,
        public readonly string $description,
        public readonly bool $possibleRename = false,
        public readonly string|null $renameOf = null,
        public readonly array|null $subject = null,
    ) {}
}
