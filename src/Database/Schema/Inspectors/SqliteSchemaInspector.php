<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Schema\Inspectors;

/**
 * Reads the live schema on SQLite — `PRAGMA table_info`, `table_xinfo`
 * internals and the `sqlite_master` index rows.
 */
final class SqliteSchemaInspector extends SchemaInspector
{
    /**
     * Every table name in the live schema.
     *
     * Excludes SQLite's own internals (`sqlite_%`) and shadow tables of
     * FTS/virtual tables (which carry `%_data`, `%_idx`, … suffixes —
     * they are implementation detail, not user schema).
     *
     * @return list<string> The table names.
     */
    public function tables(): array
    {
        $result = $this->pdo->query(
            "SELECT name FROM sqlite_master WHERE type = 'table' "
            . "AND name NOT LIKE 'sqlite_%' ORDER BY name",
        );

        if ($result === false) {
            throw new \RuntimeException('Could not list SQLite tables.');
        }

        return array_values(array_map('strval', $result->fetchAll(\PDO::FETCH_COLUMN)));
    }

    /**
     * One table's live schema.
     *
     * @param string $name The table name.
     * @return LiveTable The live snapshot.
     * @throws \RuntimeException When the table does not exist.
     */
    public function table(string $name): LiveTable
    {
        if (!$this->hasTable($name)) {
            throw new \RuntimeException("Table [{$name}] does not exist in the SQLite schema.");
        }

        return new LiveTable(
            $name,
            $this->columns($name),
            $this->indexes($name),
            $this->foreignKeys($name),
        );
    }

    /**
     * The live columns, from `PRAGMA table_info`.
     *
     * @param string $name The table name.
     * @return list<array{name: string, type: string, nullable: bool, default: mixed, primaryKey: bool}> The columns.
     */
    private function columns(string $name): array
    {
        $statement = $this->pdo->prepare('PRAGMA table_info(' . $this->quoteIdentifier($name) . ')');
        $statement->execute();

        $columns = [];

        /** @var array<string, mixed> $row */
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $columns[] = [
                'name' => (string) $row['name'],
                'type' => strtolower((string) $row['type']),
                'nullable' => ((int) $row['notnull']) === 0,
                'default' => $row['dflt_value'],
                'primaryKey' => ((int) $row['pk']) > 0,
            ];
        }

        return $columns;
    }

    /**
     * The live indexes, from `PRAGMA index_list` + `index_info`.
     *
     * @param string $name The table name.
     * @return list<array{name: string|null, columns: list<string>, unique: bool}> The indexes.
     */
    private function indexes(string $name): array
    {
        $statement = $this->pdo->prepare('PRAGMA index_list(' . $this->quoteIdentifier($name) . ')');
        $statement->execute();

        $indexes = [];

        /** @var array<string, mixed> $row */
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $origin = (string) $row['origin'];

            // 'pk' indexes restate the primary key (already on the columns);
            // 'u'/'c' are unique/constraint indexes — real user schema.
            if ($origin === 'pk') {
                continue;
            }

            $indexName = (string) $row['name'];
            $infoStatement = $this->pdo->prepare('PRAGMA index_info(' . $this->quoteIdentifier($indexName) . ')');
            $infoStatement->execute();

            $columns = array_values(array_map(
                'strval',
                $infoStatement->fetchAll(\PDO::FETCH_COLUMN, 2),
            ));

            $indexes[] = [
                // Auto-named indexes (sqlite_autoindex_*) are unnamed from
                // the user's perspective — they came from inline constraints.
                'name' => str_starts_with($indexName, 'sqlite_autoindex_') ? null : $indexName,
                'columns' => $columns,
                'unique' => ((int) $row['unique']) === 1,
            ];
        }

        return $indexes;
    }

    /**
     * The live foreign keys, from `PRAGMA foreign_key_list`.
     *
     * @param string $name The table name.
     * @return list<array{columns: list<string>, referencesTable: string, referencesColumns: list<string>, onDelete: string|null, onUpdate: string|null}> The constraints.
     */
    private function foreignKeys(string $name): array
    {
        $statement = $this->pdo->prepare('PRAGMA foreign_key_list(' . $this->quoteIdentifier($name) . ')');
        $statement->execute();

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);

        // Composite FKs span several rows (one per column, sharing `id`) —
        // group by id, keeping declaration order.
        $groups = [];

        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $groups[$id]['columns'][] = (string) $row['from'];
            $groups[$id]['referencesTable'] = (string) $row['table'];
            $groups[$id]['referencesColumns'][(int) $row['seq']] = (string) $row['to'];
            $groups[$id]['onDelete'] = $row['on_delete'];
            $groups[$id]['onUpdate'] = $row['on_update'];
        }

        $constraints = [];

        foreach ($groups as $group) {
            $constraints[] = [
                'columns' => $group['columns'],
                'referencesTable' => $group['referencesTable'],
                'referencesColumns' => array_values($group['referencesColumns']),
                'onDelete' => $this->normalizeAction($group['onDelete']),
                'onUpdate' => $this->normalizeAction($group['onUpdate']),
            ];
        }

        return $constraints;
    }

    /**
     * Normalize SQLite's referential-action text ('NO ACTION', 'CASCADE',
     * 'SET NULL', …) to a canonical value, null for the no-op default.
     *
     * @param mixed $action The raw action text.
     * @return string|null The canonical action, or null for NO ACTION.
     */
    private function normalizeAction(mixed $action): ?string
    {
        $normalized = strtoupper(trim((string) $action));

        return $normalized === 'NO ACTION' ? null : $normalized;
    }

    /**
     * Quote an identifier for direct PRAGMA interpolation.
     *
     * PRAGMA arguments cannot be bound as parameters — quote the double
     * quotes instead, mirroring the dialect's wrap() convention.
     *
     * @param string $name The identifier.
     * @return string The quoted identifier.
     */
    private function quoteIdentifier(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }
}
