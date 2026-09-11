<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Schema\Inspectors;

/**
 * Reads the live schema on Postgres — `information_schema` + `pg_catalog`.
 */
final class PostgresSchemaInspector extends SchemaInspector
{
    /**
     * Every table name in the live schema (the connection's search_path).
     *
     * @return list<string> The table names.
     */
    public function tables(): array
    {
        $statement = $this->pdo->prepare(
            "SELECT tablename FROM pg_catalog.pg_tables "
            . "WHERE schemaname NOT IN ('pg_catalog', 'information_schema') "
            . 'ORDER BY tablename',
        );
        $statement->execute();

        // Build by append: the append target is inferred as list<string>,
        // which keeps the return type exact no matter how the underlying
        // PHP version types fetchAll(PDO::FETCH_COLUMN).
        $tables = [];
        foreach ($statement->fetchAll(\PDO::FETCH_COLUMN) as $name) {
            $tables[] = (string) $name;
        }

        return $tables;
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
            throw new \RuntimeException("Table [{$name}] does not exist in the Postgres schema.");
        }

        return new LiveTable(
            $name,
            $this->columns($name),
            $this->indexes($name),
            $this->foreignKeys($name),
        );
    }

    /**
     * The live columns, from `information_schema.columns`.
     *
     * @param string $name The table name.
     * @return list<array{name: string, type: string, nullable: bool, default: mixed, primaryKey: bool}> The columns.
     */
    private function columns(string $name): array
    {
        $statement = $this->pdo->prepare(
            'SELECT c.column_name, c.data_type, c.udt_name, c.is_nullable, c.column_default, '
            . 'EXISTS ('
            . '  SELECT 1 FROM information_schema.table_constraints tc '
            . '  JOIN information_schema.key_column_usage kcu '
            . '    ON kcu.constraint_name = tc.constraint_name '
            . '   AND kcu.table_schema = tc.table_schema '
            . '  WHERE tc.table_schema = c.table_schema '
            . '    AND tc.table_name = c.table_name '
            . '    AND tc.constraint_type = \'PRIMARY KEY\' '
            . '    AND kcu.column_name = c.column_name'
            . ') AS is_primary '
            . 'FROM information_schema.columns c '
            . 'WHERE c.table_schema = current_schema() AND c.table_name = ? '
            . 'ORDER BY c.ordinal_position',
        );
        $statement->execute([$name]);

        $columns = [];

        /** @var array<string, mixed> $row */
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $columns[] = [
                'name' => (string) $row['column_name'],
                // Prefer the udt name ('int4', 'timestamptz') over the verbose
                // data_type ('integer', 'timestamp with time zone') — stable
                // and comparable across Postgres versions.
                'type' => strtolower((string) $row['udt_name']),
                'nullable' => strtoupper((string) $row['is_nullable']) === 'YES',
                // Serial/identity columns report nextval(...) — keep the
                // text; the differ normalizes auto-increment separately.
                'default' => $row['column_default'],
                'primaryKey' => ((int) $row['is_primary']) === 1,
            ];
        }

        return $columns;
    }

    /**
     * The live indexes, from `pg_indexes` (excluding PK-constraint indexes
     * and unique constraints backing UNIQUE — those ride the constraints).
     *
     * @param string $name The table name.
     * @return list<array{name: string|null, columns: list<string>, unique: bool}> The indexes.
     */
    private function indexes(string $name): array
    {
        $statement = $this->pdo->prepare(
            'SELECT i.relname AS index_name, ix.indisunique, ix.indisprimary, '
            . 'pg_get_indexdef(ix.indexrelid) AS indexdef '
            . 'FROM pg_class t '
            . 'JOIN pg_namespace n ON n.oid = t.relnamespace '
            . 'JOIN pg_index ix ON ix.indrelid = t.oid '
            . 'JOIN pg_class i ON i.oid = ix.indexrelid '
            . 'WHERE n.nspname = current_schema() AND t.relname = ? '
            . 'ORDER BY i.relname',
        );
        $statement->execute([$name]);

        $indexes = [];

        /** @var array<string, mixed> $row */
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if (((int) $row['indisprimary']) === 1) {
                continue; // PK rides the columns' primaryKey flag.
            }

            $indexes[] = [
                'name' => (string) $row['index_name'],
                // Parse the column list out of the index definition —
                // pg_get_indexdef renders "CREATE [UNIQUE] INDEX name ON
                // table USING btree (col1, col2)".
                'columns' => $this->parseIndexColumns((string) $row['indexdef']),
                'unique' => ((int) $row['indisunique']) === 1,
            ];
        }

        return $indexes;
    }

    /**
     * Extract the column list from a `pg_get_indexdef` definition.
     *
     * @param string $indexdef The index definition text.
     * @return list<string> The indexed columns.
     */
    private function parseIndexColumns(string $indexdef): array
    {
        $paren = strrpos($indexdef, '(');

        if ($paren === false) {
            return [];
        }

        $inner = rtrim(substr($indexdef, $paren + 1), ') ');

        return array_map(
            fn (string $column) => trim(trim($column), '"'),
            explode(',', $inner),
        );
    }

    /**
     * The live foreign keys, from `information_schema` constraint views.
     *
     * @param string $name The table name.
     * @return list<array{columns: list<string>, referencesTable: string, referencesColumns: list<string>, onDelete: string|null, onUpdate: string|null}> The constraints.
     */
    private function foreignKeys(string $name): array
    {
        $statement = $this->pdo->prepare(
            'SELECT tc.constraint_name, kcu.column_name, ccu.table_name AS referenced_table, '
            . 'ccu.column_name AS referenced_column, kcu.ordinal_position, '
            . 'rc.delete_rule, rc.update_rule '
            . 'FROM information_schema.table_constraints tc '
            . 'JOIN information_schema.key_column_usage kcu '
            . '  ON kcu.constraint_name = tc.constraint_name '
            . ' AND kcu.table_schema = tc.table_schema '
            . 'JOIN information_schema.constraint_column_usage ccu '
            . '  ON ccu.constraint_name = tc.constraint_name '
            . ' AND ccu.table_schema = tc.table_schema '
            . 'JOIN information_schema.referential_constraints rc '
            . '  ON rc.constraint_name = tc.constraint_name '
            . ' AND rc.table_schema = tc.table_schema '
            . 'WHERE tc.table_schema = current_schema() '
            . 'AND tc.table_name = ? AND tc.constraint_type = \'FOREIGN KEY\' '
            . 'ORDER BY tc.constraint_name, kcu.ordinal_position',
        );
        $statement->execute([$name]);

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);

        $groups = [];

        foreach ($rows as $row) {
            $constraintName = (string) $row['constraint_name'];
            $groups[$constraintName]['columns'][] = (string) $row['column_name'];
            $groups[$constraintName]['referencesTable'] = (string) $row['referenced_table'];
            $groups[$constraintName]['referencesColumns'][(int) $row['ordinal_position']] = (string) $row['referenced_column'];
            $groups[$constraintName]['onDelete'] = $row['delete_rule'];
            $groups[$constraintName]['onUpdate'] = $row['update_rule'];
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
     * Normalize Postgres' referential-action text to a canonical value,
     * null for the no-op default.
     *
     * @param mixed $action The raw action text.
     * @return string|null The canonical action, or null for NO ACTION.
     */
    private function normalizeAction(mixed $action): ?string
    {
        $normalized = strtoupper(trim((string) $action));

        return $normalized === 'NO ACTION' ? null : $normalized;
    }
}
