<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Schema;

use BlueprintAU\Radiant\Database\Schema\Enums\CastSafety;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Database\Schema\Enums\SchemaOperation;
use BlueprintAU\Radiant\Database\Schema\Inspectors\LiveTable;
use BlueprintAU\Radiant\Database\Schema\Inspectors\SchemaInspector;

/**
 * Desired state vs. live schema → ordered, classified changes.
 *
 * Pure computation — desired state in, classified {@see SchemaChange}s out.
 * Creates come first, then alters, drops last.
 */
final class SchemaDiffer
{
    /**
     * Create a differ over a live-schema inspector.
     *
     * @param  SchemaInspector  $inspector
     */
    public function __construct(
        private readonly SchemaInspector $inspector,
    ) {
    }

    /**
     * Diff the desired state against the live schema.
     *
     * @param  list<Blueprint>  $desired
     * @param  list<string>  $protected  Tables that must never be dropped or offered as a rename target.
     * @param  bool  $dropTables  Whether undeclared live tables are emitted as DropTable changes.
     * @return list<SchemaChange>
     * @throws \LogicException
     */
    public function diff(array $desired, array $protected = [], bool $dropTables = true): array
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

                // The rename is the STARTING point, not the terminal one:
                // the columns the rename carries over (the old table's live
                // shape) are diffed against the desired shape, so the follow-up
                // column/index/constraint changes land in the SAME plan. The
                // changes target the NEW name — the rename applies first
                // (renames precede alters in the final ordering).
                foreach ([
                    ...$this->diffTable($table, $blueprint, $renamedFrom),
                    $this->diffIndexes($table, $blueprint, $renamedFrom),
                    ...$this->diffForeignKeys($table, $blueprint, $renamedFrom),
                    ...$this->diffChecks($table, $blueprint, $renamedFrom),
                ] as $change) {
                    if ($change !== null) {
                        $alters[] = $change;
                    }
                }
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
        // table RENAMED AWAY by a declared rename is not a drop, and a
        // PROTECTED table is never dropped — protection must not be
        // defeatable by the host filtering the drop after the fact, so
        // it is enforced here, before the changes are ever linked.
        // With $dropTables off the plan is ADDITIVE-ONLY: undeclared
        // tables are left untouched (not synced), and with no drop list
        // the rename tie has nothing to pair against — creates stay
        // plain creates.
        if ($dropTables) {
            foreach ($liveTables as $table) {
                if (in_array($table, $renamedAway, true)) {
                    continue;
                }

                if (in_array($table, $protected, true)) {
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
        }

        $creates = $this->orderCreatesByDependencies($creates);
        $drops = $this->orderDropsByDependencies($drops);

        $changes = [...$creates, ...$renames, ...$alters, ...$drops];

        return $this->tieTableRenames($changes, $creates, $drops);
    }

    /**
     * Order creates so referenced tables come first (topological sort).
     *
     * @param  list<SchemaChange>  $creates
     * @return list<SchemaChange>
     * @throws \LogicException
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
     * Order drops in reverse dependency order — children before parents.
     *
     * @param  list<SchemaChange>  $drops
     * @return list<SchemaChange>
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
     * A pair is flagged when the created table's columns overlap the
     * dropped table's live columns. The tie never rewrites operations —
     * both sides of a flagged pair carry {@see SchemaChange::$renameOf}.
     *
     * @param  list<SchemaChange>  $changes
     * @param  list<SchemaChange>  $creates
     * @param  list<SchemaChange>  $drops
     * @return list<SchemaChange>
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
     * @param  string  $table
     * @param  Blueprint  $blueprint
     * @param  string|null  $liveTable  The live table to read, when it differs from the target (a declared rename).
     * @return list<SchemaChange>
     */
    private function diffTable(string $table, Blueprint $blueprint, string|null $liveTable = null): array
    {
        $live = $this->inspector->table($liveTable ?? $table);
        $liveColumns = [];

        foreach ($live->columns as $column) {
            $liveColumns[$column['name']] = $column;
        }

        // A declared rename SATISFIES the desired `to` column (it arrives
        // via the rename, not an add) and RETIRES the live `from` column
        // (it leaves via the rename, not a drop) — the add/drop diff must
        // not double-count either side. The desired `to` shape is kept
        // for the MODIFY comparison: a rename + shape change sequences
        // RenameColumn then ModifyColumn.
        ['columns' => $desiredColumns, 'renames' => $renames, 'renamedDesired' => $renamedDesired] = $this->columnSets($blueprint, $liveColumns);

        $additions = [];
        $drops = [];
        $modifications = [];

        // The drift DETAIL rides the detection pass: the facets each
        // modified column drifts by are rendered here, while the
        // live/desired pair is in hand — modifyChange() never re-runs
        // the tests. Keys are the modification names (the `to` side for
        // renamed columns); values are the rendered facet strings.
        // Destructiveness is classified in the same pass: nullability
        // tightening and cast risk are exactly the arms driftDetail()
        // and castSafety() already know about.
        $details = [];
        $destructive = false;

        foreach ($desiredColumns as $name => $column) {
            if (!isset($liveColumns[$name])) {
                $additions[] = $name;
                continue;
            }

            // Content drift: the column exists on both sides — compare the
            // facets. Type via the dialect's round-trip mapping; nullability
            // and default directly. An enum column's inline CHECK is part
            // of its definition — a values change is content drift.
            $detail = $this->driftDetail($liveColumns[$name], $column);

            if (!$this->enumCheckMatches($table, $column, $live)) {
                $detail[] = sprintf('enum values changed: %s', $this->enumValuesDetail($column));
            }

            if ($detail !== []) {
                $modifications[] = $name;
                $details[$name] = $detail;
            }

            if ($column['nullable'] === false && $liveColumns[$name]['nullable'] === true) {
                $destructive = true; // nullability tightened.
            }

            // A type change must be a cast the dialect can perform: fail
            // fast on an impossible one, flag a data-dependent one.
            $safety = $this->inspector->castSafety($liveColumns[$name]['type'], $column['type']);

            if ($safety === CastSafety::Uncastable) {
                throw new \LogicException(sprintf(
                    'Cannot modify [%s].[%s]: the live type [%s] cannot be cast to [%s].',
                    $table,
                    $name,
                    $liveColumns[$name]['type'],
                    $column['type']->value,
                ));
            }

            if ($safety === CastSafety::Risky) {
                $destructive = true; // the cast may lose data or fail on some values.
            }
        }

        foreach ($liveColumns as $name => $liveColumn) {
            if (isset($renames[$name])) {
                continue; // a declared rename — not a drop.
            }

            if (!isset($desiredColumns[$name])) {
                $drops[] = $name;
            }
        }

        // Renamed columns: the desired `to` shape is compared against the
        // live `from` shape — a rename + shape change sequences
        // RenameColumn (first) then ModifyColumn. Same single-pass detail
        // rendering as above (no enum arm here: the rename carries the
        // desired shape, and its CHECK is handled by the plain-name pass
        // when the desired column is not also renamed away).
        foreach ($renamedDesired as $to => $column) {
            $from = array_search($to, $renames, true);

            if ($from === false || !isset($liveColumns[$from])) {
                continue;
            }

            $detail = $this->driftDetail($liveColumns[$from], $column);

            if (!$this->enumCheckMatches($table, $column, $live)) {
                $detail[] = sprintf('enum values changed: %s', $this->enumValuesDetail($column));
            }

            if ($detail !== []) {
                $modifications[] = $to;
                $details[$to] = $detail;
            }

            if ($column['nullable'] === false && $liveColumns[$from]['nullable'] === true) {
                $destructive = true; // nullability tightened.
            }

            $safety = $this->inspector->castSafety($liveColumns[$from]['type'], $column['type']);

            if ($safety === CastSafety::Uncastable) {
                throw new \LogicException(sprintf(
                    'Cannot modify [%s].[%s]: the live type [%s] cannot be cast to [%s].',
                    $table,
                    $to,
                    $liveColumns[$from]['type'],
                    $column['type']->value,
                ));
            }

            if ($safety === CastSafety::Risky) {
                $destructive = true; // the cast may lose data or fail on some values.
            }
        }

        $changes = [];

        // The rename change FIRST — subsequent alters target the new name.
        if ($renames !== []) {
            $changes[] = $this->renameChange($table, $renames);
        }

        // Adds and drops are SEPARATE changes — a merged add+drop alter
        // would dispatch only the dominant side and silently lose the
        // other. The add applies first (a later modify may reference a
        // just-added column); the drop follows. Each change carries the
        // FULL desired blueprint plus the NAMES of the columns it acts on
        // — the dialects filter the blueprint by those names.
        if ($additions !== []) {
            $changes[] = $this->addChange($table, $blueprint, $additions, $drops);
        }

        if ($drops !== []) {
            $changes[] = $this->dropChange($table, $blueprint, $additions, $drops);
        }

        if ($modifications !== []) {
            $changes[] = $this->modifyChange($table, $blueprint, $modifications, $details, $destructive);
        }

        return $changes;
    }

    /**
     * Build the desired column set, the verified renames, and the renamed desired shapes.
     *
     * @param  Blueprint  $blueprint
     * @param  array<string, array<string, mixed>>  $liveColumns
     * @return array{columns: array<string, array<string, mixed>>, renames: array<string, string>, renamedDesired: array<string, array<string, mixed>>}
     */
    private function columnSets(Blueprint $blueprint, array $liveColumns): array
    {
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

        $renamedDesired = [];

        foreach ($renames as $from => $to) {
            if (isset($desiredColumns[$to])) {
                $renamedDesired[$to] = $desiredColumns[$to];
            }

            unset($desiredColumns[$to]);
        }

        return ['columns' => $desiredColumns, 'renames' => $renames, 'renamedDesired' => $renamedDesired];
    }

    /**
     * The human-readable facets a live column drifts from the declared
     * shape — the same tests the detection pass classifies, rendered:
     * type (`live -> declared native`), nullability, default, enum values.
     *
     * @param  array<string, mixed>  $liveColumn
     * @param  array<string, mixed>  $column
     * @return list<string>
     */
    private function driftDetail(array $liveColumn, array $column): array
    {
        $detail = [];

        $typeMatches = $this->inspector->columnTypeMatches(
            $liveColumn['type'],
            $column['type'],
            $column['length'],
            $column['precision'],
            $column['scale'] ?? null,
        );

        if (!$typeMatches) {
            $detail[] = sprintf(
                '%s -> %s',
                (string) $liveColumn['type'],
                $this->inspector->schemaGrammar->type(
                    $column['type'],
                    $column['length'],
                    $column['precision'],
                    $column['scale'] ?? null,
                ),
            );
        }

        if ($liveColumn['nullable'] !== $column['nullable']) {
            $detail[] = $column['nullable'] ? 'not null -> nullable' : 'nullable -> not null';
        }

        if (!$this->defaultsMatch($liveColumn['default'], $column['default'])) {
            $detail[] = sprintf(
                'default changed: %s -> %s',
                $liveColumn['default'] === null || $liveColumn['default'] === false
                    ? 'NULL'
                    : (string) $liveColumn['default'],
                $this->renderDefault($column['default']),
            );
        }

        return $detail;
    }

    /**
     * Render a declared column default for a description — a plain
     * scalar as-is, `NULL` for null, everything else through var_export.
     *
     * @param  mixed  $default
     */
    private function renderDefault(mixed $default): string
    {
        if ($default === null) {
            return 'NULL';
        }

        if (is_bool($default)) {
            return $default ? 'true' : 'false';
        }

        if (is_scalar($default)) {
            return (string) $default;
        }

        return var_export($default, true);
    }

    /**
     * Build the RenameColumn change for a table's verified renames.
     *
     * @param  string  $table
     * @param  array<string, string>  $renames
     * @return SchemaChange
     */
    private function renameChange(string $table, array $renames): SchemaChange
    {
        $renameBlueprint = new Blueprint($table);

        foreach ($renames as $from => $to) {
            $renameBlueprint = $renameBlueprint->renameColumn($from, $to);
        }

        return new SchemaChange(
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

    /**
     * Build the AddColumn change for a table's column additions.
     *
     * @param  string  $table
     * @param  Blueprint  $blueprint  The full desired blueprint.
     * @param  list<string>  $additions
     * @param  list<string>  $drops  The drop side, for the rename advisory.
     * @return SchemaChange
     */
    private function addChange(string $table, Blueprint $blueprint, array $additions, array $drops): SchemaChange
    {
        $description = sprintf(
            'alter table [%s]: add column(s) [%s]',
            $table,
            implode(', ', $additions),
        );

        // A mixed add+drop is the rename SHAPE — flagged on BOTH halves so
        // the host asks, never guessed.
        $possibleRename = $drops !== [];

        if ($possibleRename) {
            $description .= sprintf(
                ' — POSSIBLE RENAME: [%s] -> [%s]? If intended, declare it with'
                . ' Blueprint::renameColumn() and re-diff; applying as-is destroys the dropped data.',
                implode(', ', $drops),
                implode(', ', $additions),
            );
        }

        return new SchemaChange($table, SchemaOperation::AddColumn, $blueprint, false, $description, $possibleRename, null, $additions);
    }

    /**
     * Build the DropColumn change for a table's column drops.
     *
     * @param  string  $table
     * @param  Blueprint  $blueprint  The full desired blueprint.
     * @param  list<string>  $additions  The add side, for the rename advisory.
     * @param  list<string>  $drops
     * @return SchemaChange
     */
    private function dropChange(string $table, Blueprint $blueprint, array $additions, array $drops): SchemaChange
    {
        $description = sprintf(
            'alter table [%s]: drop column(s) [%s] — DESTRUCTIVE: data loss',
            $table,
            implode(', ', $drops),
        );

        $possibleRename = $additions !== [];

        if ($possibleRename) {
            $description .= sprintf(
                ' — POSSIBLE RENAME: [%s] -> [%s]? If intended, declare it with'
                . ' Blueprint::renameColumn() and re-diff; applying as-is destroys the dropped data.',
                implode(', ', $drops),
                implode(', ', $additions),
            );
        }

        return new SchemaChange($table, SchemaOperation::DropColumn, $blueprint, true, $description, $possibleRename, null, $drops);
    }

    /**
     * Build the ModifyColumn change from the detection pass's findings.
     *
     * @param  string  $table
     * @param  Blueprint  $blueprint  The full desired blueprint carried on the change.
     * @param  list<string>  $modifications  The drifted column names (the subject).
     * @param  array<string, list<string>>  $details  The rendered drift facets per modified column,
     *        computed in the detection pass ({@see diffTable}) — keys match $modifications.
     * @param  bool  $destructive  Classified in the detection pass: nullability tightened or a
     *        data-dependent cast.
     * @return SchemaChange
     */
    private function modifyChange(string $table, Blueprint $blueprint, array $modifications, array $details, bool $destructive): SchemaChange
    {
        $detailed = [];

        foreach ($modifications as $name) {
            $detail = $details[$name] ?? [];

            $detailed[] = $detail === [] ? $name : sprintf('%s (%s)', $name, implode(', ', $detail));
        }

        return new SchemaChange(
            $table,
            SchemaOperation::ModifyColumn,
            $blueprint,
            $destructive,
            sprintf(
                'modify column(s) on [%s]: [%s]%s',
                $table,
                implode(', ', $detailed),
                $destructive ? ' — DESTRUCTIVE: existing rows may violate the new shape' : '',
            ),
            false,
            null,
            $modifications,
        );
    }

    /**
     * Compare a live column default against the declared one.
     *
     * @param  mixed  $liveDefault
     * @param  mixed  $declaredDefault
     * @return bool
     */
    private function defaultsMatch(mixed $liveDefault, mixed $declaredDefault): bool
    {
        if ($liveDefault === null || $liveDefault === false) {
            return $declaredDefault === null;
        }

        if ($declaredDefault instanceof \BlueprintAU\Radiant\Database\Query\Expression) {
            return (string) $liveDefault === $declaredDefault->value;
        }

        // Inspectors pass the default through as text, and every dialect
        // reports a string default as its quoted SQL literal (`''`, `'x'` —
        // Postgres appends a `::type` cast). Compare the unquoted literal,
        // so a converged column does not re-plan as a ModifyColumn forever.
        if (is_string($liveDefault)) {
            return $this->unquoteLiteral($liveDefault) == $declaredDefault;
        }

        return $liveDefault == $declaredDefault;
    }

    /**
     * Strip the SQL literal quoting a dialect wraps a string default in.
     *
     * @param  string  $value
     * @return string
     */
    private function unquoteLiteral(string $value): string
    {
        $quote = $value[0] ?? '';

        if ($quote !== "'" && $quote !== '"') {
            return $value;
        }

        // Find the literal's closing quote (a doubled quote is an escape),
        // so a `::` INSIDE the literal is never mistaken for a cast.
        $length = strlen($value);
        $end = null;

        for ($i = 1; $i < $length; $i++) {
            if ($value[$i] !== $quote) {
                continue;
            }

            if (($i + 1) < $length && $value[$i + 1] === $quote) {
                $i++; // escaped quote — keep scanning.
                continue;
            }

            $end = $i;
            break;
        }

        if ($end === null) {
            return $value; // unbalanced — never guess.
        }

        $rest = substr($value, $end + 1);

        // A Postgres type cast (`'x'::character varying`) rides AFTER the
        // literal; anything else means this is not a plain literal.
        if ($rest !== '' && !str_starts_with($rest, '::')) {
            return $value;
        }

        return str_replace($quote . $quote, $quote, substr($value, 1, $end - 1));
    }

    /**
     * Diff one table's declared foreign keys against the live ones —
     * shape-first matching.
     *
     * @param  string  $table
     * @param  Blueprint  $blueprint
     * @param  string|null  $liveTable  The live table to read, when it differs from the target (a declared rename).
     * @return list<SchemaChange>
     */
    private function diffForeignKeys(string $table, Blueprint $blueprint, string|null $liveTable = null): array
    {
        $live = $this->inspector->table($liveTable ?? $table);

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
            $actions = array_filter([
                $desiredFk['onDelete'] === null ? null : "on delete {$desiredFk['onDelete']->value}",
                $desiredFk['onUpdate'] === null ? null : "on update {$desiredFk['onUpdate']->value}",
            ]);

            $changes[] = new SchemaChange(
                $table,
                SchemaOperation::AddForeignKey,
                $blueprint,
                false,
                sprintf(
                    'add foreign key on [%s] ([%s] -> [%s] ([%s]))%s',
                    $table,
                    implode(', ', $desiredFk['columns']),
                    (string) $desiredFk['references'][0],
                    implode(', ', array_slice($desiredFk['references'], 1)),
                    $actions === [] ? '' : ' ' . implode(' ', $actions),
                ),
            );
        }

        return $changes;
    }

    /**
     * Whether a declared FK shape matches a live FK shape — the
     * shape-first identity test.
     *
     * @param  array{columns: list<string>, references: list<string>, onDelete: \BlueprintAU\Radiant\Database\Schema\Enums\ForeignKeyAction|null, onUpdate: \BlueprintAU\Radiant\Database\Schema\Enums\ForeignKeyAction|null, deferrable: bool, initiallyDeferred: bool}  $desiredFk
     * @param  array{columns: list<string>, referencesTable: string, referencesColumns: list<string>, onDelete: string|null, onUpdate: string|null, deferrable: bool, name?: string|null}  $liveFk
     * @return bool
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
     * @param  string  $table
     * @param  Blueprint  $blueprint
     * @param  string|null  $liveTable  The live table to read, when it differs from the target (a declared rename).
     * @return list<SchemaChange>
     */
    private function diffChecks(string $table, Blueprint $blueprint, string|null $liveTable = null): array
    {
        $live = $this->inspector->table($liveTable ?? $table);

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
     * Whether two CHECK expressions match after conservative
     * normalization — whitespace collapsed, dialect quoting stripped.
     *
     * @param  string  $declared
     * @param  string|null  $live
     * @return bool
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
     * Whether an enum column's inline CHECK matches the live schema —
     * the values-drift comparison. A non-enum column always matches
     * (no CHECK is part of its definition).
     *
     * @param  string  $table
     * @param  array<string, mixed>  $column  The desired ColumnShape.
     * @param  LiveTable  $live
     * @return bool
     */
    private function enumCheckMatches(string $table, array $column, LiveTable $live): bool
    {
        if ($column['type'] !== ColumnType::Enum) {
            return true;
        }

        $values = $column['values'] ?? null;

        if ($values === null || $values === []) {
            return true; // nothing declared — nothing to compare.
        }

        // Render the desired CHECK exactly as the grammar does, then
        // compare against every live CHECK on the table (the inline CHECK
        // is unnamed on some dialects, so match by expression). The
        // comparison normalizes quoting away, so the dialect's wrap
        // character does not matter — plain double quotes suffice.
        $desired = 'CHECK ("' . $column['name'] . '" IN ('
            . implode(', ', array_map(fn (string $value) => "'" . str_replace("'", "''", $value) . "'", $values)) . '))';

        foreach ($live->checks as $check) {
            if ($this->checkExpressionsMatch($desired, $check['expression'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Render a drifted enum column's declared values for a description —
     * the quoted list the grammar's CHECK carries.
     *
     * @param  array<string, mixed>  $column  The desired ColumnShape.
     */
    private function enumValuesDetail(array $column): string
    {
        $values = $column['values'] ?? [];

        return '[' . implode(', ', array_map(
            fn (string $value) => "'" . str_replace("'", "''", $value) . "'",
            $values,
        )) . ']';
    }

    /**
     * Diff one table's declared indexes against the live ones — option
     * drift only.
     *
     * @param  string  $table
     * @param  Blueprint  $blueprint
     * @param  string|null  $liveTable  The live table to read, when it differs from the target (a declared rename).
     * @return SchemaChange|null
     */
    private function diffIndexes(string $table, Blueprint $blueprint, string|null $liveTable = null): ?SchemaChange
    {
        $live = $this->inspector->table($liveTable ?? $table);

        // Live indexes by name (named ones only — unnamed ride the
        // columns' unique flag).
        $liveIndexes = [];

        foreach ($live->indexes as $index) {
            if ($index['name'] !== null) {
                $liveIndexes[$index['name']] = $index;
            }
        }

        $rebuild = new Blueprint($table);
        $detailed = [];

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

            $rebuild = $rebuild->index($name, $index['columns'], unique: $index['unique'], where: $index['where'], nullsNotDistinct: $index['nullsNotDistinct']);

            // WHICH option drifted and from what to what — rendered while
            // the live/desired pair is in hand.
            $options = [];

            if (!$whereMatches) {
                $options[] = sprintf(
                    'where: %s -> %s',
                    $liveIndex['where'] ?? 'none',
                    $index['where'] ?? 'none',
                );
            }

            if (!$nullsMatch) {
                $options[] = sprintf(
                    'nulls not distinct: %s -> %s',
                    $liveIndex['nullsNotDistinct'] ? 'on' : 'off',
                    $index['nullsNotDistinct'] ? 'on' : 'off',
                );
            }

            $detailed[] = sprintf('%s (%s)', $name, implode('; ', $options));
        }

        if ($detailed === []) {
            return null;
        }

        $description = sprintf(
            'alter indexes on [%s]: rebuild [%s] — index options drifted (partial predicate / NULLS NOT DISTINCT)',
            $table,
            implode(', ', $detailed),
        );

        return new SchemaChange($table, SchemaOperation::AlterIndexes, $rebuild, false, $description);
    }
}