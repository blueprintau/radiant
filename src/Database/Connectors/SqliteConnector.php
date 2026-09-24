<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Connectors;

use BlueprintAU\Radiant\Database\Connections\SqlConnection;
use BlueprintAU\Radiant\Database\Connections\SqliteConnection;
use Override;

/**
 * SQLite connector — builds a PDO SQLite connection from config.
 *
 * @see \BlueprintAU\Radiant\Database\Connections\SqlConnection
 *
 * @phpstan-import-type PdoOptions from \BlueprintAU\Radiant\Database\Connectors\SqlConnector
 */
final class SqliteConnector extends SqlConnector
{
    /**
     * SQLite-specific default PDO attributes — merged over the base
     * defaults.
     *
     * The busy timeout is set via \PDO::ATTR_TIMEOUT, which pdo_sqlite
     * maps to SQLite's busy timeout in seconds.
     *
     * @return array<int, int|bool>
     */
    #[\Override]
    protected function defaultOptions(): array
    {
        return [
            \PDO::ATTR_TIMEOUT => 5, // seconds → SQLite busy timeout (5000ms)
        ];
    }

    /**
     * Create a SQLite connection from the given config.
     *
     * @param  array{database: mixed, options?: PdoOptions, ...<mixed>}  $config
     * @return SqliteConnection
     * @throws \InvalidArgumentException
     */
    #[Override]
    public function connect(array $config): SqlConnection
    {
        $this->validConfig($config);
        $path = $config['database'];

        if (!is_string($path)) {
            throw new \InvalidArgumentException(
                'SQLite database must be a path string; got ' . get_debug_type($path) . '.'
            );
        }

        $options = $config['options'] ?? [];
        $pdo = $this->createPdo("sqlite:{$path}", null, null, $options);

        // SQLite ships with foreign key enforcement OFF by default — orphaned
        // rows and no cascades unless it is enabled explicitly. This is a
        // post-connect PRAGMA (not a PDO attribute), so it runs here rather
        // than in the options layers. It is forced unconditionally: a
        // database relying on broken FK data is the user's own setup problem,
        // but a library silently dropping FK guarantees would be the library's.
        $pdo->exec('PRAGMA foreign_keys = ON');

        return new SqliteConnection($pdo);
    }

    /**
     * Validate the shape of a SQLite connection config.
     *
     * @param  array<string,mixed>  $config
     * @throws \InvalidArgumentException
     */
    #[Override]
    public function validConfig(array $config): void
    {
        parent::validConfig($config);

        if (!isset($config['database']) || !is_string($config['database']) || $config['database'] === '') {
            throw new \InvalidArgumentException(
                'SQLite database must be a non-empty path string; got '
                . (isset($config['database']) ? get_debug_type($config['database']) : 'nothing')
                . '.'
            );
        }
    }
}
