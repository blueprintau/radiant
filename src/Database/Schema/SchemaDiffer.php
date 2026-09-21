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
     * @return list<SchemaChange> Creates first (dependency-ordered:
     *         referenced tables before their referrers), then renames,
     *         alters, drops last (reverse-dependency: children before
     *         parents) — drops last so a rename (drop + create) never
     *         destroys data before the replacement exists.
     * @throws \LogicException When the desired set contains a circular
     *         FK dependency (no valid creation order exists).
     */
    public function diff(array $desired): array
    {
        $creates = [];
        $renames = [];
        $alters = [];
        $drops = [];

        $liveTables = $this->inspector->tables();
        $desiredTables = [];
        $renamedAway = [];

        foreach ($desired as $blueprint) {
            $table = $blueprint->getTable();
            $desiredTables[] = $table;

            // A DECLARED table rename: the decision, not a guess. Verified
            // against the live schema — the old table must exist and the
            // new one must not. A declaration that does not match reality
            // is a TYPO (the host thinks it is renaming but the database
            // would silently create a DUPLICATE table) — fail fast, never
            // fall through to a create.
            $renamedFrom = $blueprint->getRenamedFrom();

            if ($renamedFrom !== null) {
                if (!in_array($renamedFrom, $liveTables, true)) {
                    throw new \LogicException(sprintf(
                        'Blueprint declares a rename of [%s] to [%s], but [%s] does not exist in the '
                        . 'live schema — the declaration does not match reality. Fix the old table '
                        . 'name, or drop the renamedFrom() declaration if a new table was intended.',
                        $renamedFrom,
                        $table,
                        $renamedFrom,
                    ));
                }

                if (in_array($table, $liveTables, true)) {
                    throw new \LogicException(sprintf(
                        'Blueprint declares a rename of [%s] to [%s], but [%s] already exists in the '
                        . 'live schema — the rename target is taken. Fix the new table name.',
                        $renamedFrom,
                        $table,
                        $table,
                    ));
                }

                $renames[] = new SchemaChange(
                    $table,
                    SchemaOperation::RenameTable,
                    $blueprint,
                    false,
                    sprintf(
                        'rename table [%s] to [%s] — data travels with the rename',
                        $renamedFrom,
                        $table,
                    ),
                    false,
                    $renamedFrom,
                );
                // The old table is RENAMED AWAY, not dropped — the drop
                // loop must not emit a DropTable for it.
                $renamedAway[] = $renamedFrom;
                continue;
            }

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

            // ALL diff passes run for an existing table — a table can need
            // a column alter AND an index rebuild AND constraint changes
            // at once. Column alters come first (an index rebuild may
            // reference a just-added column).
            foreach ([
                ...$this->diffTable($table, $blueprint),
                $this->diffIndexes($table, $blueprint),
                ...$this->diffForeignKeys($table, $blueprint),
                ...$this->diffChecks($table, $blueprint),
            ] as $change) {
                if ($change !== null) {
                    $alters[] = $change;
                }
            }
        }

        // A live table the desired state no longer declares is a drop —
        // destructive, and always last (reverse-dependency ordered). A
        // table RENAMED AWAY by a declared rename is not a drop.
        foreach ($liveTables as $table) {
            if (in_array($table, $renamedAway, true)) {
                continue;
            }

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

        $creates = $this->orderCreatesByDependencies($creates);
        $drops = $this->orderDropsByDependencies($drops);

        $changes = [...$creates, ...$renames, ...$alters, ...$drops];

        return $this->tieTableRenames($changes, $creates, $drops);
    }

    /**
     * Order creates so referenced tables come first (topological sort).
     *
     * Fixes a latent ordering bug: `posts` (FK → users) created before
     * `users` fails on MySQL/Postgres at DDL time and on SQLite at INSERT
     * time (the connector forces `foreign_keys = ON` — table creation
     * succeeds lazily, writes don't). The graph edges come from each
     * blueprint's FK references (resolved table names); MTI child→parent
     * FKs are captured naturally since `fromMetadata()` emits them.
     *
     * External references (an FK to a table NOT in the desired set) are
     * ignored for ordering — assumed to already exist live. Self-
     * references are skipped (same table, no edge).
     *
     * Ties keep declaration order (stable sort semantics via the queue).
     *
     * @param list<SchemaChange> $creates The create changes.
     * @return list<SchemaChange> Dependency-ordered creates.
     * @throws \LogicException When a circular FK dependency exists — no
     *         valid creation order is possible; the message names the
     *         cycle path and the two exits (drop one FK, or hand-author
     *         the two-pass create-then-add-constraint sequence).
     */
    private function orderCreatesByDependencies(array $creates): array
    {
        if (count($creates) < 2) {
            return $creates;
        }

        $byTable = [];

        foreach ($creates as $create) {
            $byTable[$create->table] = $create;
        }

        // Edges: referrer → referenced (referrer depends on referenced).
        $dependencies = [];
        $declaredOrder = [];

        foreach ($creates as $index => $create) {
            $declaredOrder[$create->table] = $index;
            $dependencies[$create->table] = [];

            foreach ($create->blueprint->getForeignKeys() as $foreignKey) {
                $referenced = $foreignKey['references'][0] ?? null;

                if ($referenced === null || $referenced === $create->table) {
                    continue; // external or self-reference — no edge.
                }

                if (isset($byTable[$referenced])) {
                    $dependencies[$create->table][] = $referenced;
                }
            }
        }

        // Kahn's algorithm with declaration-order tie-breaking.
        $ordered = [];
        $remaining = $dependencies;

        while ($remaining !== []) {
            $ready = [];

            foreach ($remaining as $table => $deps) {
                if ($deps === []) {
                    $ready[] = $table;
                }
            }

            if ($ready === []) {
                $cycle = implode(' → ', array_keys($remaining)) . ' → ' . (string) array_key_first($remaining);

                throw new \LogicException(sprintf(
                    'Circular foreign-key dependency among the desired tables: %s. No valid creation '
                    . 'order exists. Drop one of the foreign keys (most "cycles" are a parent link plus '
                    . 'a convenience back-reference that needs no constraint), or create the tables in '
                    . 'two passes: create without the cyclic foreign key, then ALTER TABLE ADD CONSTRAINT '
                    . 'afterwards.',
                    $cycle,
                ));
            }

            usort($ready, fn (string $a, string $b) => $declaredOrder[$a] <=> $declaredOrder[$b]);

            foreach ($ready as $table) {
                $ordered[] = $byTable[$table];
                unset($remaining[$table]);

                foreach ($remaining as &$deps) {
                    $deps = array_values(array_diff($deps, [$table]));
                }
                unset($deps);
            }
        }

        return $ordered;
    }

    /**
     * Order drops in REVERSE dependency order — children before parents.
     *
     * The mirrored bug: dropping a parent table before its child fails
     * under FK enforcement. The edges come from the LIVE schema (the
     * dropped tables' FK declarations), not the desired blueprints — the
     * desired state no longer declares these tables.
     *
     * @param list<SchemaChange> $drops The drop changes.
     * @return list<SchemaChange> Reverse-dependency-ordered drops.
     */
    private function orderDropsByDependencies(array $drops): array
    {
        if (count($drops) < 2) {
            return $drops;
        }

        $dropSet = [];

        foreach ($drops as $drop) {
            $dropSet[$drop->table] = true;
        }

        // A drop of a REFERENCED table must wait for the drops of the
        // tables that reference it (its children go first). The edge
        // points parent → child: the parent's drop depends on the
        // child's drop.
        $dependencies = [];

        foreach ($drops as $drop) {
            $dependencies[$drop->table] = [];
        }

        foreach ($drops as $drop) {
            foreach ($this->inspector->table($drop->table)->foreignKeys as $foreignKey) {
                $referenced = $foreignKey['referencesTable'];

                if ($referenced !== $drop->table && isset($dependencies[$referenced])) {
                    // $drop (child) references $referenced (parent): the
                    // PARENT's drop waits for this child's drop.
                    $dependencies[$referenced][] = $drop->table;
                }
            }
        }

        // Reverse topological order: emit a table only after every table
        // that references it has been emitted. Kahn's on reversed edges.
        $ordered = [];
        $remaining = $dependencies;

        while ($remaining !== []) {
            $ready = [];

            foreach ($remaining as $table => $deps) {
                if ($deps === []) {
                    $ready[] = $table;
                }
            }

            if ($ready === []) {
                // A cycle among dropped tables — impossible to resolve by
                // ordering; emit in input order (the database will reject
                // with a clear FK error, which is the honest outcome).
                foreach (array_keys($remaining) as $table) {
                    $ordered[] = $drops[array_search($table, array_column($drops, 'table'), true)];
                }

                break;
            }

            foreach ($ready as $table) {
                $ordered[] = $drops[array_search($table, array_column($drops, 'table'), true)];
                unset($remaining[$table]);

                foreach ($remaining as &$deps) {
                    $deps = array_values(array_diff($deps, [$table]));
                }
                unset($deps);
            }
        }

        return $ordered;
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
     * Column-level: added columns → `AddColumn`, removed columns →
     * `DropColumn` (destructive), folded into one alter per table.
     * Content drift (a column present on both sides with a changed
     * type/nullable/default) → `ModifyColumn` — a SEPARATE change, so a
     * rename+modify sequence stays independently verifiable.
     *
     * Declared column renames are honored FIRST: a declared rename
     * suppresses the add+drop advisory shape for those columns (the
     * declaration IS the decision — the data-losing alter never
     * materializes for a declared rename).
     *
     * @param string $table The table name.
     * @param Blueprint $blueprint The desired state.
     * @return list<SchemaChange> The changes (alter + modify), empty when
     *         in sync.
     */
    private function diffTable(string $table, Blueprint $blueprint): array
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

        // Declared renames: the live `from` column becomes the `to` column.
        // The rename is verified against the live schema (old exists, new
        // absent); a declaration that does not match reality is IGNORED for
        // the diff (the columns diff as they are — the host sees the real
        // shape, never a wrong rename).
        $renames = [];

        foreach ($blueprint->getColumnRenames() as $rename) {
            if (isset($liveColumns[$rename['from']]) && !isset($liveColumns[$rename['to']])) {
                $renames[$rename['from']] = $rename['to'];
            }
        }

        // A declared rename SATISFIES the desired `to` column (it arrives
        // via the rename, not an add) and RETIRES the live `from` column
        // (it leaves via the rename, not a drop) — the add/drop diff must
        // not double-count either side. The desired `to` shape is kept
        // for the MODIFY comparison: a rename + shape change sequences
        // RenameColumn then ModifyColumn.
        $renamedDesired = [];

        foreach ($renames as $from => $to) {
            if (isset($desiredColumns[$to])) {
                $renamedDesired[$to] = $desiredColumns[$to];
            }

            unset($desiredColumns[$to]);
        }

        $alter = new Blueprint($table);
        $modify = new Blueprint($table);
        $destructive = false;
        $additions = [];
        $drops = [];
        $modifications = [];

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
                continue;
            }

            // Content drift: the column exists on both sides — compare the
            // facets. Type via the dialect's round-trip mapping; nullability
            // and default directly.
            $liveColumn = $liveColumns[$name];
            $typeMatches = $this->inspector->columnTypeMatches(
                $liveColumn['type'],
                $column['type'],
                $column['length'],
            );
            $nullableMatches = $liveColumn['nullable'] === $column['nullable'];
            $defaultMatches = $this->defaultsMatch($liveColumn['default'], $column['default']);

            if (!$typeMatches || !$nullableMatches || !$defaultMatches) {
                $modify->column(
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
                $modifications[] = $name;
            }
        }

        foreach ($liveColumns as $name => $liveColumn) {
            if (isset($renames[$name])) {
                continue; // a declared rename — not a drop.
            }

            if (!isset($desiredColumns[$name])) {
                $alter->dropColumn($name);
                $drops[] = $name;
                $destructive = true;
            }
        }

        // Renamed columns: the desired `to` shape is compared against the
        // live `from` shape — a rename + shape change sequences
        // RenameColumn (first) then ModifyColumn.
        foreach ($renamedDesired as $to => $column) {
            $from = array_search($to, $renames, true);

            if ($from === false || !isset($liveColumns[$from])) {
                continue;
            }

            $liveColumn = $liveColumns[$from];
            $typeMatches = $this->inspector->columnTypeMatches(
                $liveColumn['type'],
                $column['type'],
                $column['length'],
            );
            $nullableMatches = $liveColumn['nullable'] === $column['nullable'];
            $defaultMatches = $this->defaultsMatch($liveColumn['default'], $column['default']);

            if (!$typeMatches || !$nullableMatches || !$defaultMatches) {
                $modify->column(
                    $column['type'],
                    $to,
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
                $modifications[] = $to;
            }
        }

        $changes = [];

        // The rename change FIRST — subsequent alters target the new name.
        if ($renames !== []) {
            $renameBlueprint = new Blueprint($table);

            foreach ($renames as $from => $to) {
                $renameBlueprint->renameColumn($from, $to);
            }

            $changes[] = new SchemaChange(
                $table,
                SchemaOperation::RenameColumn,
                $renameBlueprint,
                false,
                sprintf(
                    'rename column(s) on [%s]: [%s] — data travels with the rename',
                    $table,
                    implode(', ', array_map(fn (string $from) => "[{$from}] -> [{$renames[$from]}]", array_keys($renames))),
                ),
            );
        }

        if ($additions !== [] || $drops !== []) {
            // The operation reflects what DOMINATES the alter; both sides are
            // always in the blueprint and the description. A mixed add+drop
            // is the rename SHAPE — flagged so the host asks, never guessed.
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
                    ' — POSSIBLE RENAME: [%s] -> [%s]? If intended, declare it with'
                    . ' Blueprint::renameColumn() and re-diff; applying as-is destroys the dropped data.',
                    implode(', ', $drops),
                    implode(', ', $additions),
                );
            }

            $changes[] = new SchemaChange($table, $operation, $alter, $destructive, $description, $possibleRename);
        }

        if ($modifications !== []) {
            // The change carries the ORIGINAL desired blueprint: dialects
            // without an in-place modify form (SQLite) rebuild the whole
            // table from it (the rebuild re-binds via forTable()); the
            // in-place dialects compile the modified subset from the
            // change's own record. The description names the modified
            // subset.
            // Destructive when the change tightens nullability or shrinks
            // the type/length (existing rows may violate the new shape);
            // non-destructive for a default-only change.
            $modifyDestructive = false;

            foreach ($modify->getColumns() as $column) {
                // A renamed column's live shape is the FROM column's (the
                // rename has not applied yet at diff time).
                $liveName = array_search($column['name'], $renames, true) ?: $column['name'];
                $liveColumn = $liveColumns[$liveName] ?? null;

                if ($liveColumn === null) {
                    continue;
                }

                if ($column['nullable'] === false && $liveColumn['nullable'] === true) {
                    $modifyDestructive = true; // nullability tightened.
                }
            }

            $changes[] = new SchemaChange(
                $table,
                SchemaOperation::ModifyColumn,
                $blueprint,
                $modifyDestructive,
                sprintf(
                    'modify column(s) on [%s]: [%s]%s',
                    $table,
                    implode(', ', $modifications),
                    $modifyDestructive ? ' — DESTRUCTIVE: existing rows may violate the new shape' : '',
                ),
            );
        }

        return $changes;
    }

    /**
     * Compare a live column default against the declared one.
     *
     * The live default is the dialect's stored text (often a string —
     * `'0'`, `'CURRENT_TIMESTAMP'`, or null for no default); the declared
     * default is the PHP value. The comparison is deliberately
     * conservative: a live NULL (no default) matches a declared null, and
     * scalar values compare loosely (int 0 vs '0' — the codec round-trip
     * makes them the same cell). An Expression default compares by its
     * SQL text.
     *
     * @param mixed $liveDefault The live default (dialect text or null).
     * @param mixed $declaredDefault The declared default.
     * @return bool True when the defaults match.
     */
    private function defaultsMatch(mixed $liveDefault, mixed $declaredDefault): bool
    {
        if ($liveDefault === null || $liveDefault === false) {
            return $declaredDefault === null;
        }

        if ($declaredDefault instanceof \BlueprintAU\Radiant\Database\Query\Expression) {
            return (string) $liveDefault === $declaredDefault->value;
        }

        return $liveDefault == $declaredDefault;
    }

    /**
     * Diff one table's declared foreign keys against the live ones —
     * SHAPE-FIRST matching.
     *
     * A live FK with an identical shape (columns + references + actions)
     * is IN SYNC regardless of its constraint name — pre-existing
     * auto-named constraints must not read as drift and churn drop+re-add.
     * A name match with a DIFFERENT shape is real drift (drop + add).
     * Names are the DROP HANDLE, not the identity.
     *
     * @param string $table The table name.
     * @param Blueprint $blueprint The desired state.
     * @return list<SchemaChange> The add/drop changes, empty when in sync.
     */
    private function diffForeignKeys(string $table, Blueprint $blueprint): array
    {
        $live = $this->inspector->table($table);

        $liveForeignKeys = $live->foreignKeys;
        $matched = [];

        $changes = [];
        $adds = [];
        $drops = [];

        foreach ($blueprint->getForeignKeys() as $desiredFk) {
            $matchedShape = null;

            foreach ($liveForeignKeys as $index => $liveFk) {
                if ($this->foreignKeyShapesMatch($desiredFk, $liveFk)) {
                    $matchedShape = $index;
                    break;
                }
            }

            if ($matchedShape !== null) {
                $matched[] = $matchedShape;
                continue; // in sync — regardless of name.
            }

            $adds[] = $desiredFk;
        }

        foreach ($liveForeignKeys as $index => $liveFk) {
            if (in_array($index, $matched, true)) {
                continue;
            }

            // A live FK the desired state does not declare is a drop —
            // but ONLY when the desired state declares the table at all
            // (the differ never drops constraints from tables it is not
            // managing). The live constraint name is the drop handle.
            $name = $liveFk['name'] ?? null;

            if ($name === null) {
                continue; // unnamed live constraint — cannot address it.
            }

            $changes[] = new SchemaChange(
                $table,
                SchemaOperation::DropForeignKey,
                $blueprint,
                false,
                sprintf(
                    'drop foreign key [%s] on [%s] — the constraint goes, the rows stay',
                    $name,
                    $table,
                ),
            );
        }

        foreach ($adds as $desiredFk) {
            // The change carries the ORIGINAL desired blueprint: on SQLite
            // the FK add routes through the table rebuild (which renders
            // the whole desired schema); the in-place dialects read the
            // FK from the blueprint's first entry.
            $changes[] = new SchemaChange(
                $table,
                SchemaOperation::AddForeignKey,
                $blueprint,
                false,
                sprintf(
                    'add foreign key on [%s] ([%s] -> [%s])',
                    $table,
                    implode(', ', $desiredFk['columns']),
                    (string) $desiredFk['references'][0],
                ),
            );
        }

        return $changes;
    }

    /**
     * Whether a declared FK shape matches a live FK shape — the
     * shape-first identity test.
     *
     * Columns, referenced table + columns, and the ON DELETE/ON UPDATE
     * actions must all match. The constraint NAME is deliberately NOT
     * compared (names are the drop handle, not the identity).
     *
     * @param array{columns: list<string>, references: list<string>, onDelete: \BlueprintAU\Radiant\Database\Schema\Enums\ForeignKeyAction|null, onUpdate: \BlueprintAU\Radiant\Database\Schema\Enums\ForeignKeyAction|null, deferrable: bool, initiallyDeferred: bool} $desiredFk The declared shape.
     * @param array{columns: list<string>, referencesTable: string, referencesColumns: list<string>, onDelete: string|null, onUpdate: string|null, deferrable: bool, name?: string|null} $liveFk The live shape.
     * @return bool True when the shapes match.
     */
    private function foreignKeyShapesMatch(array $desiredFk, array $liveFk): bool
    {
        if ($desiredFk['columns'] !== $liveFk['columns']) {
            return false;
        }

        $referencedTable = $desiredFk['references'][0] ?? null;
        $referencedColumns = array_slice($desiredFk['references'], 1);

        if ($referencedTable !== $liveFk['referencesTable']) {
            return false;
        }

        if ($referencedColumns !== $liveFk['referencesColumns']) {
            return false;
        }

        // Actions: the declared enum value vs the live normalized text
        // (the inspector normalizes `NO ACTION` to null on both sides).
        $desiredDelete = $desiredFk['onDelete']?->value;
        $desiredUpdate = $desiredFk['onUpdate']?->value;

        return $desiredDelete === $liveFk['onDelete'] && $desiredUpdate === $liveFk['onUpdate'];
    }

    /**
     * Diff one table's declared CHECK constraints against the live ones.
     *
     * Expression-level comparison with CONSERVATIVE normalization
     * (whitespace collapsed, dialect quoting stripped): a same-name
     * different-expression mismatch is ADVISORY-ONLY — reported in the
     * description, never auto-executed (a wrong drop+add on an expression
     * guess is the data-corruption scenario; false positives stay bounded
     * because nothing executes). Declared name missing live → `AddCheck`;
     * live name absent from declared → report-only advisory.
     *
     * @param string $table The table name.
     * @param Blueprint $blueprint The desired state.
     * @return list<SchemaChange> The add changes (plus advisories in
     *         descriptions), empty when in sync.
     */
    private function diffChecks(string $table, Blueprint $blueprint): array
    {
        $live = $this->inspector->table($table);

        $liveChecks = [];

        foreach ($live->checks as $check) {
            if ($check['name'] !== null) {
                $liveChecks[$check['name']] = $check;
            }
        }

        $changes = [];

        foreach ($blueprint->getChecks() as $desiredCheck) {
            // Every declared CHECK carries a FINAL name (derived at
            // declaration time when omitted — same rule as indexes), so
            // the diff is by name like every other constraint.
            $name = $desiredCheck['name'];

            $liveCheck = $liveChecks[$name] ?? null;

            if ($liveCheck === null) {
                // The change carries the ORIGINAL desired blueprint: on
                // SQLite the CHECK add routes through the table rebuild.
                $changes[] = new SchemaChange(
                    $table,
                    SchemaOperation::AddCheck,
                    $blueprint,
                    false,
                    sprintf('add check [%s] on [%s]', $name, $table),
                );
                continue;
            }

            // Same name — compare the normalized expressions. A mismatch
            // is ADVISORY-ONLY: reported, never executed.
            if (!$this->checkExpressionsMatch($desiredCheck['expression'], $liveCheck['expression'])) {
                $changes[] = new SchemaChange(
                    $table,
                    SchemaOperation::AddCheck,
                    new Blueprint($table),
                    false,
                    sprintf(
                        'check [%s] on [%s] EXPRESSION DRIFT — declared [%s], live [%s]. '
                        . 'Advisory only: author the drop+add by hand if intended.',
                        $name,
                        $table,
                        $desiredCheck['expression'],
                        (string) $liveCheck['expression'],
                    ),
                );
            }
        }

        // Live CHECKs the desired state no longer declares — report-only
        // advisory (the differ never drops a constraint it cannot verify
        // the expression of).
        foreach ($liveChecks as $name => $liveCheck) {
            $declared = false;

            foreach ($blueprint->getChecks() as $desiredCheck) {
                if ($desiredCheck['name'] === $name) {
                    $declared = true;
                    break;
                }
            }

            if (!$declared) {
                $changes[] = new SchemaChange(
                    $table,
                    SchemaOperation::DropCheck,
                    new Blueprint($table),
                    false,
                    sprintf(
                        'live check [%s] on [%s] is NOT declared — advisory only: author the drop by hand if intended.',
                        $name,
                        $table,
                    ),
                );
            }
        }

        return $changes;
    }

    /**
     * Whether two CHECK expressions match after CONSERVATIVE
     * normalization — whitespace collapsed, dialect quoting stripped.
     *
     * @param string $declared The declared expression.
     * @param string|null $live The live expression (null when the
     *        inspector could not parse it).
     * @return bool True when the normalized expressions match.
     */
    private function checkExpressionsMatch(string $declared, string|null $live): bool
    {
        if ($live === null) {
            return false; // unparseable live text — never assume a match.
        }

        $normalize = fn (string $expression): string => preg_replace('/\s+/', ' ', trim(str_replace(['"', '`', "'"], '', $expression))) ?? $expression;

        return $normalize($declared) === $normalize($live);
    }

    /**
     * Diff one table's declared indexes against the live ones — option
     * drift only, because the live inspector cannot see a DESIRED index
     * that was never created (a declared index absent from the live table
     * is a broken deployment, not a differ job — the differ never creates
     * indexes outside the whole-table create path).
     *
     * A named index present on BOTH sides with a changed `where` predicate
     * or `NULLS NOT DISTINCT` option is reported: rebuilding the index is
     * non-destructive (no rows touched), but the drift means the live
     * constraint is WEAKER than declared (e.g. `NULLS DISTINCT` accepting
     * duplicate NULLs, or no partial filter matching rows it should
     * exclude) — a silent semantic hole if not surfaced.
     *
     * Indexes live in the ALTER vocabulary as a whole-table rebuild: the
     * change carries a blueprint holding the table's FULL desired index
     * list; `apply()` drops the drifted live indexes (by live name — the
     * drift is per-option, names match) and re-runs every `CREATE INDEX`.
     * Unnamed live indexes (inline UNIQUE constraints, `sqlite_autoindex_*`)
     * are skipped — they ride the columns' `unique` flag, not this path.
     *
     * @param string $table The table name.
     * @param Blueprint $blueprint The desired state.
     * @return SchemaChange|null The rebuild change, or null when in sync.
     */
    private function diffIndexes(string $table, Blueprint $blueprint): ?SchemaChange
    {
        $live = $this->inspector->table($table);

        // Live indexes by name (named ones only — unnamed ride the
        // columns' unique flag).
        $liveIndexes = [];

        foreach ($live->indexes as $index) {
            if ($index['name'] !== null) {
                $liveIndexes[$index['name']] = $index;
            }
        }

        $rebuild = new Blueprint($table);
        $drifted = [];

        foreach ($blueprint->getIndexes() as $index) {
            $name = $index['name'];
            $liveIndex = $liveIndexes[$name] ?? null;

            if ($liveIndex === null) {
                continue; // absent live = deployment gap, not option drift.
            }

            $whereMatches = ($index['where'] ?? null) === ($liveIndex['where'] ?? null);
            $nullsMatch = $index['nullsNotDistinct'] === $liveIndex['nullsNotDistinct'];

            if ($whereMatches && $nullsMatch) {
                continue;
            }

            $rebuild->index($name, $index['columns'], unique: $index['unique'], where: $index['where'], nullsNotDistinct: $index['nullsNotDistinct']);
            $drifted[] = $name;
        }

        if ($drifted === []) {
            return null;
        }

        $description = sprintf(
            'alter indexes on [%s]: rebuild [%s] — index options drifted (partial predicate / NULLS NOT DISTINCT)',
            $table,
            implode(', ', $drifted),
        );

        return new SchemaChange($table, SchemaOperation::AlterIndexes, $rebuild, false, $description);
    }
}