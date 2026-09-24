<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Schema\Inspectors;

use BlueprintAU\Radiant\Database\Schema\ConstraintNamer;

/**
 * Reads the live schema on SQLite — `PRAGMA table_info`, `table_xinfo`
 * internals and the `sqlite_master` index rows.
 *
 * @extends SchemaInspector<\BlueprintAU\Radiant\Database\Schema\Grammars\SqliteSchemaGrammar>
 */
final class SqliteSchemaInspector extends SchemaInspector
{
    /**
     * The dialect's schema grammar (the factory hook).
     *
     * @return \BlueprintAU\Radiant\Database\Schema\Grammars\SqliteSchemaGrammar
     */
    protected function getDefaultSchemaGrammar(): \BlueprintAU\Radiant\Database\Schema\Grammars\SchemaGrammar
    {
        return new \BlueprintAU\Radiant\Database\Schema\Grammars\SqliteSchemaGrammar();
    }

    /**
     * Whether a live column's native type text matches the declared
     * logical type — the SQLite mapping.
     *
     * @param  string  $liveType
     * @param  \BlueprintAU\Radiant\Database\Schema\Enums\ColumnType  $declaredType
     * @param  int|null  $declaredLength
     * @return bool
     */
    public function columnTypeMatches(string $liveType, \BlueprintAU\Radiant\Database\Schema\Enums\ColumnType $declaredType, int|null $declaredLength): bool
    {
        return strtolower($liveType) === strtolower($this->schemaGrammar->type($declaredType, $declaredLength));
    }

    /**
     * The live tables that declare a foreign key into the given table.
     *
     * @param  string  $table
     * @return list<string>
     */
    public function referencingTables(string $table): array
    {
        $referencing = [];

        foreach ($this->tables() as $candidate) {
            $statement = $this->pdo->prepare('PRAGMA foreign_key_list(' . $this->quoteIdentifier($candidate) . ')');
            $statement->execute();

            /** @var list<array<string, mixed>> $rows */
            $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);

            foreach ($rows as $row) {
                if ((string) $row['table'] === $table) {
                    $referencing[] = $candidate;
                    break;
                }
            }
        }

        return $referencing;
    }
    /**
     * Every table name in the live schema.
     *
     * Excludes SQLite's own internals (`sqlite_%`) and shadow tables of
     * FTS/virtual tables.
     *
     * @return list<string>
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

        // Build by append: the append target is inferred as list<string>,
        // which keeps the return type exact no matter how the underlying
        // PHP version types fetchAll(PDO::FETCH_COLUMN).
        $tables = [];
        foreach ($result->fetchAll(\PDO::FETCH_COLUMN) as $name) {
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
            throw new \RuntimeException("Table [{$name}] does not exist in the SQLite schema.");
        }

        return new LiveTable(
            $name,
            $this->columns($name),
            $this->indexes($name),
            $this->foreignKeys($name),
            $this->checks($name),
        );
    }

    /**
     * The live CHECK constraints, parsed from the `sqlite_master` SQL.
     *
     * @param  string  $name
     * @return list<array{name: string|null, expression: string|null}>
     */
    private function checks(string $name): array
    {
        $statement = $this->pdo->prepare(
            "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?",
        );
        $statement->execute([$name]);

        /** @var string|false $sql */
        $sql = $statement->fetchColumn();

        if ($sql === false) {
            return [];
        }

        // Extract the parenthesized body of the CREATE TABLE, then scan
        // for CHECK ( ... ) groups with balanced parens.
        if (preg_match_all('/(?:CONSTRAINT\s+(\S+)\s+)?CHECK\s*\(/i', $sql, $matches, \PREG_OFFSET_CAPTURE) === 0) {
            return [];
        }

        $checks = [];

        foreach ($matches[0] as $index => $match) {
            $nameMatch = $matches[1][$index][0] ?? null;
            $openParen = (int) $match[1] + strlen((string) $match[0]) - 1;

            // Walk to the matching close paren (balanced scan).
            $depth = 0;
            $end = -1;
            $length = strlen($sql);

            for ($i = $openParen; $i < $length; $i++) {
                if ($sql[$i] === '(') {
                    $depth++;
                } elseif ($sql[$i] === ')') {
                    $depth--;

                    if ($depth === 0) {
                        $end = $i;
                        break;
                    }
                }
            }

            if ($end === -1) {
                continue; // unbalanced — skip (never guess).
            }

            $expression = trim(substr($sql, $openParen + 1, $end - $openParen - 1));

            $checks[] = [
                'name' => $nameMatch === null ? null : trim($nameMatch, '"`\''),
                'expression' => $expression,
            ];
        }

        return $checks;
    }

    /**
     * The live columns, from `PRAGMA table_info`.
     *
     * @param  string  $name
     * @return list<array{name: string, type: string, nullable: bool, default: mixed, primaryKey: bool}>
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
     * @param  string  $name
     * @return list<array{name: string|null, columns: list<string>, unique: bool, where: string|null, nullsNotDistinct: bool}>
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

            // Build by append: the append target is inferred as list<string>,
            // which keeps the type exact no matter how the underlying PHP
            // version types fetchAll(PDO::FETCH_COLUMN).
            $columns = [];
            foreach ($infoStatement->fetchAll(\PDO::FETCH_COLUMN, 2) as $column) {
                $columns[] = (string) $column;
            }

            $indexes[] = [
                // Auto-named indexes (sqlite_autoindex_*) are unnamed from
                // the user's perspective — they came from inline constraints.
                'name' => str_starts_with($indexName, 'sqlite_autoindex_') ? null : $indexName,
                'columns' => $columns,
                'unique' => ((int) $row['unique']) === 1,
                // The partial-index predicate rides the CREATE INDEX SQL in
                // sqlite_master — PRAGMA index_list does not expose it.
                'where' => $this->parseIndexWhere($indexName),
                // SQLite has no NULLS NOT DISTINCT — always false.
                'nullsNotDistinct' => false,
            ];
        }

        return $indexes;
    }

    /**
     * Extract the partial-index predicate for a named index from the
     * `sqlite_master` SQL.
     *
     * @param  string  $indexName
     * @return string|null
     */
    private function parseIndexWhere(string $indexName): ?string
    {
        $statement = $this->pdo->prepare(
            "SELECT sql FROM sqlite_master WHERE type = 'index' AND name = ?",
        );
        $statement->execute([$indexName]);

        $sql = $statement->fetchColumn();

        if ($sql === false || !is_string($sql)) {
            return null;
        }

        $where = strripos($sql, ' WHERE ');

        if ($where === false) {
            return null;
        }

        return rtrim(trim(substr($sql, $where + 7)), ';');
    }

    /**
     * The live foreign keys, from `PRAGMA foreign_key_list`.
     *
     * @param  string  $name
     * @return list<array{columns: list<string>, referencesTable: string, referencesColumns: list<string>, onDelete: string|null, onUpdate: string|null, deferrable: bool, name: string|null}>
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

        foreach ($groups as $id => $group) {
            $constraints[] = [
                'columns' => $group['columns'],
                'referencesTable' => $group['referencesTable'],
                'referencesColumns' => array_values($group['referencesColumns']),
                'onDelete' => $this->normalizeAction($group['onDelete']),
                'onUpdate' => $this->normalizeAction($group['onUpdate']),
                // SQLite has no DEFERRABLE — always false.
                'deferrable' => false,
                // SQLite does not name inline FK constraints — the derived
                // `{table}_{columns}_foreign` convention is the handle the
                // grammar would use; null when it cannot be derived. The
                // shape comes from the shared {@see ConstraintNamer}, so
                // the read side can never drift from the write side.
                'name' => $group['referencesTable'] === ''
                    ? null
                    : ConstraintNamer::derive($name, $group['columns'], 'foreign'),
            ];
        }

        return $constraints;
    }

    /**
     * Normalize SQLite's referential-action text to a canonical value.
     *
     * @param  mixed  $action
     * @return string|null
     */
    private function normalizeAction(mixed $action): ?string
    {
        $normalized = strtoupper(trim((string) $action));

        return $normalized === 'NO ACTION' ? null : $normalized;
    }

    /**
     * Quote an identifier for direct PRAGMA interpolation.
     *
     * PRAGMA arguments cannot be bound as parameters.
     *
     * @param  string  $name
     * @return string
     */
    private function quoteIdentifier(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }
}
