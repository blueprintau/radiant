<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Schema;

use BlueprintAU\Radiant\Database\Schema\Enums\SchemaOperation;

/**
 * One planned schema change, classified for the host's safety gate.
 *
 * The differ's output unit: the host formats
 * {@see SchemaChange::$description} for `--dry-run` output and gates on
 * {@see SchemaChange::$destructive} before applying. The safety gate is
 * data, not policy — Radiant reports, the host decides.
 *
 * **Rename advisories are data, never decisions.** A column add+drop pair
 * on one table, or a create+drop table pair with shared columns, is
 * flagged (`possibleRename` / `renameOf`) so the host can ask the user
 * "is this a rename?" — the Django/Prisma pattern. Radiant never rewrites
 * the operation into a rename: a wrong guess executing `RENAME COLUMN`
 * between unrelated columns corrupts data, which is worse than the drop
 * it was trying to avoid.
 */
final class SchemaChange
{
    /**
     * Create a schema change.
     *
     * @param string $table The table the change targets.
     * @param SchemaOperation $operation The operation to perform.
     * @param Blueprint $blueprint The columns involved (create/alter); an
     *        empty blueprint for a drop.
     * @param bool $destructive Whether the change can lose data — drops of
     *        columns/tables and any other data-losing operation. The host
     *        must confirm before applying.
     * @param string $description Human-readable summary, for `--dry-run`.
     * @param bool $possibleRename Whether this change is half of a
     *        rename-shaped diff: an alter carrying BOTH column additions
     *        and drops on one table (the columns may have been renamed).
     *        The host should ask before applying as-is.
     * @param string|null $renameOf For table-level create/drop pairs: the
     *        name of the table this change may be renaming (the paired
     *        change's table), or null when not part of a pair. Both sides
     *        of a flagged pair carry the link.
     */
    public function __construct(
        public readonly string $table,
        public readonly SchemaOperation $operation,
        public readonly Blueprint $blueprint,
        public readonly bool $destructive,
        public readonly string $description,
        public readonly bool $possibleRename = false,
        public readonly string|null $renameOf = null,
    ) {}
}
