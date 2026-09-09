<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Schema;

use BlueprintAU\Radiant\Database\Schema\Enums\SchemaOperation;
use BlueprintAU\Radiant\Database\Schema\Inspectors\SchemaInspector;

/**
 * Desired state vs. live schema → ordered, classified changes.
 *
 * Pure computation — desired state in, classified {@see SchemaChange}s out.
 * No I/O decisions, no console, no filesystem: the Prisma `migrate diff` /
 * Django autodetector role. The host command becomes trivial wiring:
 * plan → show → apply.
 *
 * Output ordering matters: creates first, then alters, drops last — so a
 * rename (drop + create) never destroys data before the replacement exists.
 *
 * **The safety gate is data, not policy.** Every change is classified
 * `destructive` or not; the host decides what to do (require `--force`,
 * prompt, refuse). Classification rule: anything that can lose data is
 * destructive — dropping a table, dropping a column. Everything else
 * (create, add column) is safe.
 */
final class SchemaDiffer
{
    /**
     * Create a differ over a live-schema inspector.
     *
     * @param SchemaInspector $inspector Reads the live schema.
     */
    public function __construct(
        private readonly SchemaInspector $inspector,
    ) {
    }

    /**
     * Diff the desired state against the live schema.
     *
     * The table each blueprint builds is read from the blueprint itself
     * ({@see Blueprint::getTable()}) — the blueprint is the source of truth
     * for its own name.
     *
     * @param list<Blueprint> $desired The desired states.
     * @return list<SchemaChange> Creates first, then alters, drops last —
     *         drops last so a rename (drop + create) never destroys data
     *         before the replacement exists.
     */
    public function diff(array $desired): array
    {
        $creates = [];
        $alters = [];
        $drops = [];

        $liveTables = $this->inspector->tables();
        $desiredTables = [];

        foreach ($desired as $blueprint) {
            $table = $blueprint->getTable();
            $desiredTables[] = $table;

            if (!in_array($table, $liveTables, true)) {
                $creates[] = new SchemaChange(
                    $table,
                    SchemaOperation::CreateTable,
                    $blueprint,
                    false,
                    "create table [{$table}]",
                );
                continue;
            }

            $change = $this->diffTable($table, $blueprint);

            if ($change !== null) {
                $alters[] = $change;
            }
        }

        // A live table the desired state no longer declares is a drop —
        // destructive, and always last.
        foreach ($liveTables as $table) {
            if (!in_array($table, $desiredTables, true)) {
                $drops[] = new SchemaChange(
                    $table,
                    SchemaOperation::DropTable,
                    new Blueprint($table),
                    true,
                    "drop table [{$table}] — DESTRUCTIVE: data loss",
                );
            }
        }

        $changes = [...$creates, ...$alters, ...$drops];

        return $this->tieTableRenames($changes, $creates, $drops);
    }

    /**
     * Tie table-level create/drop pairs into rename advisories.
     *
     * Runs AFTER both loops: creates come from the desired loop, drops from
     * the live loop, and only once both exist can they be paired. A pair is
     * flagged when the created table's columns overlap the dropped table's
     * live columns (a renamed table keeps its columns — Django's signal).
     * Below the threshold the pair stays unflagged: a genuinely new table
     * plus a genuinely dead one must NOT read as a rename.
     *
     * The tie NEVER rewrites operations — no `RENAME TABLE` exists in the
     * operation vocabulary, and auto-executing one on a guess is the
     * data-corruption scenario. Both sides of a flagged pair carry
     * {@see SchemaChange::$renameOf} so the host's gate can ask one
     * question per PAIR without string-matching descriptions.
     *
     * Multiple candidate drops for one create: tie to the highest overlap
     * only, and leave the others unflagged — never pick silently.
     *
     * @param list<SchemaChange> $changes The ordered change list.
     * @param list<SchemaChange> $creates The create changes.
     * @param list<SchemaChange> $drops The drop changes.
     * @return list<SchemaChange> The same order, with advisory data filled.
     */
    private function tieTableRenames(array $changes, array $creates, array $drops): array
    {
        if ($creates === [] || $drops === []) {
            return $changes;
        }

        // Live column names per dropped table, for overlap scoring.
        $dropColumns = [];
        foreach ($drops as $drop) {
            $dropColumns[$drop->table] = array_map(
                fn (array $column) => $column['name'],
                $this->inspector->table($drop->table)->columns,
            );
        }

        // Best drop per create, by shared-column ratio.
        $best = []; // create table => [drop table, overlap]

        foreach ($creates as $create) {
            $createNames = array_map(
                fn (array $column) => $column['name'],
                $create->blueprint->getColumns(),
            );

            foreach ($drops as $drop) {
                $shared = count(array_intersect($createNames, $dropColumns[$drop->table]));
                $overlap = count($createNames) === 0 ? 0.0 : $shared / count($createNames);

                if ($overlap < 0.5) {
                    continue; // below threshold — unrelated, do not flag.
                }

                if (!isset($best[$create->table]) || $overlap > $best[$create->table][1]) {
                    $best[$create->table] = [$drop->table, $overlap];
                }
            }
        }

        if ($best === []) {
            return $changes;
        }

        // Rebuild each change that participates in a pair, with the link.
        $linked = [];

        foreach ($best as $createTable => [$dropTable, $overlap]) {
            $linked[$createTable] = $dropTable;
            $linked[$dropTable] = $createTable;
        }

        foreach ($changes as $index => $change) {
            if (!isset($linked[$change->table])) {
                continue;
            }

            $other = $linked[$change->table];
            $label = $change->operation === SchemaOperation::DropTable
                ? sprintf(
                    '%s — POSSIBLE RENAME of [%s]: columns shared. If intended, '
                    . 'copy the data between the steps and author the rename '
                    . '(ALTER TABLE ... RENAME TO ...) in host code.',
                    $change->description,
                    $other,
                )
                : sprintf(
                    '%s — POSSIBLE RENAME of [%s] (%d%% column overlap).',
                    $change->description,
                    $other,
                    (int) round($best[$change->table][1] * 100),
                );

            $changes[$index] = new SchemaChange(
                $change->table,
                $change->operation,
                $change->blueprint,
                $change->destructive,
                $label,
                $change->possibleRename,
                $other,
            );
        }

        return $changes;
    }

    /**
     * Diff one table's desired state against its live columns.
     *
     * Column-level only in v1: added columns → `AddColumn`, removed columns
     * → `DropColumn` (destructive), folded into one alter per table. A
     * column present on both sides with a changed type/nullable/default is
     * reported as an add (re-add) — the ALTER vocabulary has no modify case
     * yet; a mismatch fails loudly in the host's review of the plan instead
     * of silently passing.
     *
     * @param string $table The table name.
     * @param Blueprint $blueprint The desired state.
     * @return SchemaChange|null The change, or null when in sync.
     */
    private function diffTable(string $table, Blueprint $blueprint): ?SchemaChange
    {
        $live = $this->inspector->table($table);
        $liveColumns = [];

        foreach ($live->columns as $column) {
            $liveColumns[$column['name']] = $column;
        }

        $desiredColumns = [];

        foreach ($blueprint->getColumns() as $column) {
            // An explicit dropColumn() removes the column from the desired
            // set — a hand-written ALTER delta says "this column goes", so
            // the live column of that name must diff as a drop, not match
            // the still-present metadata declaration.
            $desiredColumns[$column['name']] = $column;
        }

        foreach ($blueprint->getDropColumns() as $name) {
            unset($desiredColumns[$name]);
        }

        $alter = new Blueprint($table);
        $destructive = false;
        $additions = [];
        $drops = [];

        foreach ($desiredColumns as $name => $column) {
            if (!isset($liveColumns[$name])) {
                $alter->column(
                    $column['type'],
                    $name,
                    primaryKey: $column['primaryKey'],
                    autoIncrement: $column['autoIncrement'],
                    nullable: $column['nullable'],
                    unique: $column['unique'],
                    index: $column['index'],
                    length: $column['length'],
                    default: $column['default'],
                    foreign: $column['foreign'],
                    onDelete: $column['onDelete'],
                    onUpdate: $column['onUpdate'],
                );
                $additions[] = $name;
            }
        }

        foreach ($liveColumns as $name => $liveColumn) {
            if (!isset($desiredColumns[$name])) {
                $alter->dropColumn($name);
                $drops[] = $name;
                $destructive = true;
            }
        }

        if ($additions === [] && $drops === []) {
            return null;
        }

        // The operation reflects what DOMINATES the alter; both sides are
        // always in the blueprint and the description. A mixed add+drop is
        // the rename SHAPE — flagged so the host asks, never guessed.
        $possibleRename = $additions !== [] && $drops !== [];

        $operation = $drops === [] ? SchemaOperation::AddColumn : SchemaOperation::DropColumn;

        $parts = [];
        if ($additions !== []) {
            $parts[] = sprintf('add column(s) [%s]', implode(', ', $additions));
        }
        if ($drops !== []) {
            $parts[] = sprintf('drop column(s) [%s]', implode(', ', $drops));
        }
        $description = sprintf(
            'alter table [%s]: %s%s',
            $table,
            implode(', ', $parts),
            $destructive ? ' — DESTRUCTIVE: data loss' : '',
        );

        if ($possibleRename) {
            $description .= sprintf(
                ' — POSSIBLE RENAME: [%s] -> [%s]? If intended, author a rename'
                . ' migration instead; applying as-is destroys the dropped data.',
                implode(', ', $drops),
                implode(', ', $additions),
            );
        }

        return new SchemaChange($table, $operation, $alter, $destructive, $description, $possibleRename);
    }
}