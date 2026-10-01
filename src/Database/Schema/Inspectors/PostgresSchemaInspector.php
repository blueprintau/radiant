<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Schema\Inspectors;

/**
 * Reads the live schema on Postgres — `information_schema` + `pg_catalog`.
 *
 * @extends SchemaInspector<\BlueprintAU\Radiant\Database\Schema\Grammars\PostgresSchemaGrammar>
 */
final class PostgresSchemaInspector extends SchemaInspector
{
    /**
     * The dialect's schema grammar (the factory hook).
     *
     * @return \BlueprintAU\Radiant\Database\Schema\Grammars\PostgresSchemaGrammar
     */
    protected function getDefaultSchemaGrammar(): \BlueprintAU\Radiant\Database\Schema\Grammars\SchemaGrammar
    {
        return new \BlueprintAU\Radiant\Database\Schema\Grammars\PostgresSchemaGrammar();
    }

    /**
     * Whether a live column's native type text matches the declared
     * logical type — the Postgres mapping.
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
        // Split a size suffix off, normalize the udt base ('int4' →
        // 'integer', 'bpchar' → 'char', …), then re-attach the size so the
        // composed text compares against the grammar's rendering verbatim.
        $base = strtolower($liveType);
        $suffix = '';
        if (preg_match('/^(.+?)\((\d+(?:,\d+)?)\)$/', $base, $matches) === 1) {
            $base = $matches[1];
            $suffix = '(' . $matches[2] . ')';
        }

        $normalized = match ($base) {
            'int4' => 'integer',
            'int8' => 'bigint',
            'float8' => 'double precision',
            'bool' => 'boolean',
            'timestamp' => 'timestamp',
            'timestamptz' => 'timestamp',
            'json' => 'jsonb',
            'bytea' => 'bytea',
            'uuid' => 'uuid',
            'bpchar' => 'char',
            default => $base,
        };

        return $normalized . $suffix === strtolower($this->schemaGrammar->type($declaredType, $declaredLength, $declaredPrecision, $declaredScale));
    }

    /**
     * Compose a column's type text from its udt name plus its information_
     * schema size — the udt name alone never carries one.
     *
     * Only the types whose grammar rendering includes a size get one:
     * character types from character_maximum_length, numeric from its
     * precision/scale (absent when the numeric is unconstrained).
     *
     * @param  array<string, mixed>  $row
     * @return string
     */
    private function composeType(array $row): string
    {
        $type = strtolower((string) $row['udt_name']);

        if (in_array($type, ['varchar', 'bpchar'], true) && $row['character_maximum_length'] !== null) {
            return $type . '(' . (int) $row['character_maximum_length'] . ')';
        }

        if ($type === 'numeric' && $row['numeric_precision'] !== null) {
            return $type . '(' . (int) $row['numeric_precision'] . ',' . (int) $row['numeric_scale'] . ')';
        }

        return $type;
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
            "SELECT DISTINCT relname FROM pg_catalog.pg_constraint c "
            . "JOIN pg_catalog.pg_class r ON r.oid = c.conrelid "
            . "JOIN pg_catalog.pg_namespace n ON n.oid = r.relnamespace "
            . "WHERE c.contype = 'f' AND c.confrelid = (SELECT oid FROM pg_catalog.pg_class "
            . "WHERE relname = ? AND relnamespace = (SELECT oid FROM pg_catalog.pg_namespace "
            . "WHERE nspname = current_schema())) AND n.nspname = current_schema()",
        );
        $statement->execute([$table]);

        $tables = [];
        foreach ($statement->fetchAll(\PDO::FETCH_COLUMN) as $name) {
            $tables[] = (string) $name;
        }

        return $tables;
    }
    /**
     * Every table name in the live schema (the connection's search_path).
     *
     * @return list<string>
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
     * @param  string  $name
     * @return LiveTable
     * @throws \RuntimeException
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
     * @param  string  $name
     * @return list<array{name: string, type: string, nullable: bool, default: mixed, primaryKey: bool}>
     */
    private function columns(string $name): array
    {
        $statement = $this->pdo->prepare(
            'SELECT c.column_name, c.udt_name, c.character_maximum_length, '
            . 'c.numeric_precision, c.numeric_scale, c.is_nullable, c.column_default, '
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
                // The udt name ('int4', 'timestamptz') is stable and
                // comparable across Postgres versions, but it never carries
                // a size — compose one from information_schema so the text
                // matches the grammar's rendering ('varchar(100)',
                // 'numeric(8,2)') and length drift stays detectable.
                'type' => $this->composeType($row),
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
     * The live indexes, from `pg_indexes` (excluding PK-constraint indexes).
     *
     * @param  string  $name
     * @return list<array{name: string|null, columns: list<string>, unique: bool, where: string|null, nullsNotDistinct: bool}>
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

            $indexdef = (string) $row['indexdef'];

            $indexes[] = [
                'name' => (string) $row['index_name'],
                // Parse the column list out of the index definition —
                // pg_get_indexdef renders "CREATE [UNIQUE] INDEX name ON
                // table USING btree (col1, col2)".
                'columns' => $this->parseIndexColumns($indexdef),
                'unique' => ((int) $row['indisunique']) === 1,
                // pg_get_indexdef normalizes the predicate but preserves
                // its semantics — the differ compares it against the
                // declared text only when both are in sync.
                'where' => $this->parseIndexWhere($indexdef),
                'nullsNotDistinct' => str_contains($indexdef, 'NULLS NOT DISTINCT'),
            ];
        }

        return $indexes;
    }

    /**
     * Extract the column list from a `pg_get_indexdef` definition.
     *
     * @param  string  $indexdef
     * @return list<string>
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
     * Extract the partial-index predicate from a `pg_get_indexdef`
     * definition.
     *
     * @param  string  $indexdef
     * @return string|null
     */
    private function parseIndexWhere(string $indexdef): ?string
    {
        $where = strripos($indexdef, ' WHERE ');

        if ($where === false) {
            return null;
        }

        return trim(substr($indexdef, $where + 7));
    }

    /**
     * The live foreign keys, from `information_schema` constraint views
     * (deferrability from `pg_constraint`).
     *
     * @param  string  $name
     * @return list<array{columns: list<string>, referencesTable: string, referencesColumns: list<string>, onDelete: string|null, onUpdate: string|null, deferrable: bool, name: string}>
     */
    private function foreignKeys(string $name): array
    {
        $statement = $this->pdo->prepare(
            'SELECT tc.constraint_name, kcu.column_name, ccu.table_name AS referenced_table, '
            . 'ccu.column_name AS referenced_column, kcu.ordinal_position, '
            . 'rc.delete_rule, rc.update_rule, pc.condeferrable '
            . 'FROM information_schema.table_constraints tc '
            . 'JOIN information_schema.key_column_usage kcu '
            . '  ON kcu.constraint_name = tc.constraint_name '
            . ' AND kcu.table_schema = tc.table_schema '
            . 'JOIN information_schema.constraint_column_usage ccu '
            . '  ON ccu.constraint_name = tc.constraint_name '
            . ' AND ccu.table_schema = tc.table_schema '
            . 'JOIN information_schema.referential_constraints rc '
            . '  ON rc.constraint_name = tc.constraint_name '
            . ' AND rc.constraint_schema = tc.constraint_schema '
            . 'JOIN pg_catalog.pg_constraint pc '
            . '  ON pc.conname = tc.constraint_name '
            . ' AND pc.connamespace = (SELECT oid FROM pg_catalog.pg_namespace '
            . '     WHERE nspname = current_schema()) '
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
            $groups[$constraintName]['deferrable'] = $row['condeferrable'];
        }

        $constraints = [];

        foreach ($groups as $constraintName => $group) {
            $constraints[] = [
                'columns' => $group['columns'],
                'referencesTable' => $group['referencesTable'],
                'referencesColumns' => array_values($group['referencesColumns']),
                'onDelete' => $this->normalizeAction($group['onDelete']),
                'onUpdate' => $this->normalizeAction($group['onUpdate']),
                'deferrable' => ((int) $group['deferrable']) === 1,
                // The live constraint name — the drop handle.
                'name' => $constraintName,
            ];
        }

        return $constraints;
    }

    /**
     * Normalize Postgres' referential-action text to a canonical value.
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
