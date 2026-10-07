<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Schema\Inspectors;

/**
 * Reads the live schema on MySQL — `information_schema` tables.
 *
 * @extends SchemaInspector<\BlueprintAU\Radiant\Database\Schema\Grammars\MySqlSchemaGrammar>
 */
class MySqlSchemaInspector extends SchemaInspector
{
    /**
     * The dialect's schema grammar (the factory hook).
     *
     * @return \BlueprintAU\Radiant\Database\Schema\Grammars\MySqlSchemaGrammar
     */
    protected function getDefaultSchemaGrammar(): \BlueprintAU\Radiant\Database\Schema\Grammars\SchemaGrammar
    {
        return new \BlueprintAU\Radiant\Database\Schema\Grammars\MySqlSchemaGrammar();
    }

    /**
     * Whether a live column's native type text matches the declared
     * logical type — the MySQL mapping.
     *
     * @param  string  $liveType
     * @param  \BlueprintAU\Radiant\Database\Schema\Enums\ColumnType  $declaredType
     * @param  int|null  $declaredLength
     * @param  int|null  $declaredPrecision
     * @param  int|null  $declaredScale
     * @return bool
     */
    public function columnTypeMatches(string $liveType, \BlueprintAU\Radiant\Database\Schema\Enums\ColumnType $declaredType, int|null $declaredLength, int|null $declaredPrecision = null, int|null $declaredScale = null): bool
    {
        return strtolower($liveType) === strtolower($this->schemaGrammar->type($declaredType, $declaredLength, $declaredPrecision, $declaredScale));
    }

    /**
     * The live tables that declare a foreign key into the given table.
     *
     * @param  string  $table
     * @return list<string>
     */
    public function referencingTables(string $table): array
    {
        $statement = $this->pdo->prepare(
            'SELECT DISTINCT table_name FROM information_schema.key_column_usage '
            . 'WHERE table_schema = DATABASE() AND referenced_table_name = ? '
            . 'AND referenced_table_name IS NOT NULL',
        );
        $statement->execute([$table]);

        $tables = [];
        foreach ($statement->fetchAll(\PDO::FETCH_COLUMN) as $name) {
            $tables[] = (string) $name;
        }

        return $tables;
    }
    /**
     * Every table name in the live schema (the connection's default database).
     *
     * @return list<string>
     */
    public function tables(): array
    {
        $statement = $this->pdo->prepare(
            'SELECT table_name FROM information_schema.tables '
            . 'WHERE table_schema = DATABASE() ORDER BY table_name',
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
     * @param  string  $name
     * @return LiveTable
     * @throws \RuntimeException
     */
    public function table(string $name): LiveTable
    {
        if (!$this->hasTable($name)) {
            throw new \RuntimeException("Table [{$name}] does not exist in the MySQL schema.");
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
     * @param  string  $name
     * @return list<array{name: string, type: string, nullable: bool, default: mixed, primaryKey: bool}>
     */
    private function columns(string $name): array
    {
        $statement = $this->pdo->prepare(
            'SELECT c.column_name, c.column_type, c.is_nullable, c.column_default, '
            . '(c.column_key = \'PRI\') AS is_primary '
            . 'FROM information_schema.columns c '
            . 'WHERE c.table_schema = DATABASE() AND c.table_name = ? '
            . 'ORDER BY c.ordinal_position',
        );
        $statement->execute([$name]);

        $columns = [];

        /** @var array<string, mixed> $row */
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            // Native prepares (EMULATE_PREPARES=false) report information_
            // schema bare-column metadata in UPPERCASE — normalize so the
            // lowercase reads below work on every driver.
            $row = array_change_key_case($row, CASE_LOWER);
            $default = $row['column_default'];

            $columns[] = [
                'name' => (string) $row['column_name'],
                'type' => strtolower((string) $row['column_type']),
                'nullable' => strtoupper((string) $row['is_nullable']) === 'YES',
                // MySQL reports CURRENT_TIMESTAMP (and other literals) as
                // strings; pass through as-is — the differ compares text.
                'default' => $this->normalizeColumnDefault($default),
                'primaryKey' => ((int) $row['is_primary']) === 1,
            ];
        }

        return $columns;
    }

    /**
     * Normalize a live column default's dialect spelling.
     *
     * @param  mixed  $default
     * @return mixed
     */
    protected function normalizeColumnDefault(mixed $default): mixed
    {
        return $default;
    }

    /**
     * The live indexes, from `information_schema.statistics`.
     *
     * @param  string  $name
     * @return list<array{name: string|null, columns: list<string>, unique: bool, where: string|null, nullsNotDistinct: bool}>
     */
    private function indexes(string $name): array
    {
        $statement = $this->pdo->prepare(
            'SELECT index_name, column_name, non_unique, seq_in_index '
            . 'FROM information_schema.statistics '
            . 'WHERE table_schema = DATABASE() AND table_name = ? '
            . 'ORDER BY index_name, seq_in_index',
        );
        $statement->execute([$name]);

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);

        $groups = [];

        foreach ($rows as $row) {
            // Native prepares report information_schema keys in UPPERCASE.
            $row = array_change_key_case($row, CASE_LOWER);
            $indexName = (string) $row['index_name'];

            // PRIMARY rides the columns' primaryKey flag, not the index list.
            if ($indexName === 'PRIMARY') {
                continue;
            }

            $groups[$indexName]['columns'][(int) $row['seq_in_index']] = (string) $row['column_name'];
            $groups[$indexName]['unique'] = ((int) $row['non_unique']) === 0;
        }

        $indexes = [];

        foreach ($groups as $indexName => $group) {
            $indexes[] = [
                'name' => $indexName,
                'columns' => array_values($group['columns']),
                'unique' => $group['unique'],
                'where' => null,
                'nullsNotDistinct' => false,
            ];
        }

        return $indexes;
    }

    /**
     * The live foreign keys, from `information_schema.key_column_usage` +
     * `referential_constraints` (for the actions).
     *
     * @param  string  $name
     * @return list<array{columns: list<string>, referencesTable: string, referencesColumns: list<string>, onDelete: string|null, onUpdate: string|null, deferrable: bool, name: string}>
     */
    private function foreignKeys(string $name): array
    {
        $statement = $this->pdo->prepare(
            'SELECT kcu.constraint_name, kcu.column_name, kcu.referenced_table_name, '
            . 'kcu.referenced_column_name, kcu.ordinal_position, '
            . 'rc.delete_rule, rc.update_rule '
            . 'FROM information_schema.key_column_usage kcu '
            . 'JOIN information_schema.referential_constraints rc '
            . 'ON rc.constraint_name = kcu.constraint_name '
            . 'AND rc.constraint_schema = kcu.constraint_schema '
            . 'WHERE kcu.table_schema = DATABASE() AND kcu.table_name = ? '
            . 'AND kcu.referenced_table_name IS NOT NULL '
            . 'ORDER BY kcu.constraint_name, kcu.ordinal_position',
        );
        $statement->execute([$name]);

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);

        $groups = [];

        foreach ($rows as $row) {
            // Native prepares report information_schema keys in UPPERCASE.
            $row = array_change_key_case($row, CASE_LOWER);
            $constraintName = (string) $row['constraint_name'];
            $groups[$constraintName]['columns'][] = (string) $row['column_name'];
            $groups[$constraintName]['referencesTable'] = (string) $row['referenced_table_name'];
            $groups[$constraintName]['referencesColumns'][(int) $row['ordinal_position']] = (string) $row['referenced_column_name'];
            $groups[$constraintName]['onDelete'] = $row['delete_rule'];
            $groups[$constraintName]['onUpdate'] = $row['update_rule'];
        }

        $constraints = [];

        foreach ($groups as $constraintName => $group) {
            $constraints[] = [
                'columns' => $group['columns'],
                'referencesTable' => $group['referencesTable'],
                'referencesColumns' => array_values($group['referencesColumns']),
                'onDelete' => $this->normalizeAction($group['onDelete']),
                'onUpdate' => $this->normalizeAction($group['onUpdate']),
                // MySQL has no DEFERRABLE — always false.
                'deferrable' => false,
                // The live constraint name — the drop handle.
                'name' => $constraintName,
            ];
        }

        return $constraints;
    }

    /**
     * Normalize MySQL's referential-action text to a canonical value.
     *
     * @param  mixed  $action
     * @return string|null
     */
    private function normalizeAction(mixed $action): ?string
    {
        $normalized = strtoupper(trim((string) $action));

        return $normalized === 'NO ACTION' ? null : $normalized;
    }
}
