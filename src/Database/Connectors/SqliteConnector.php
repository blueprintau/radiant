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
 */
final class SqliteConnector extends SqlConnector
{
    /**
     * SQLite-specific default PDO attributes.
     *
     * Extends the shared defaults with SQLite's busy timeout. SQLite's
     * default busy timeout is 0ms — the moment another connection holds a
     * write lock, a query fails immediately with "database is locked". A
     * 5s timeout lets concurrent access wait briefly instead of failing
     * spuriously. Overridable by the user via config.
     *
     * The busy timeout is set via \PDO::ATTR_TIMEOUT, which pdo_sqlite
     * maps to SQLite's busy timeout in seconds (verified empirically:
     * ATTR_TIMEOUT => 5 yields PRAGMA busy_timeout = 5000ms). There is no
     * \PDO::SQLITE_ATTR_BUSY_TIMEOUT constant — it has never been defined
     * by the extension, on PDO or Pdo\Sqlite (PHP 8.4+), so ATTR_TIMEOUT is
     * the canonical, portable way to set it.
     *
     * @var array<int, int|bool>
     */
    protected static array $DEFAULT_OPTIONS = [
        \PDO::ATTR_STRINGIFY_FETCHES => false,
        \PDO::ATTR_EMULATE_PREPARES => false,
        \PDO::ATTR_TIMEOUT => 5, // seconds → SQLite busy timeout (5000ms)
    ];

    /**
     * Create a SQLite connection from the given config.
     *
     * @param array{database: mixed, options?: array<int, int|bool|array<mixed>>, ...} $config The connection config (database path, options, …).
     * @return SqlConnection A ready-to-use SQLite connection.
     * @throws \InvalidArgumentException If the database path is not a string.
     */
    #[Override]
    public function connect(array $config): SqlConnection
    {
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
     * SQLite needs a `database` path string — the only field it consumes
     * beyond the shared `driver`. The shared {@see SqlConnector::validConfig()}
     * already checked `driver`; this checks the SQLite-specific field.
     *
     * @param array<string,mixed> $config The connection config to validate.
     * @throws \InvalidArgumentException When the database path is not a
     *         string.
     */
    #[Override]
    public function validConfig(array $config): void
    {
        parent::validConfig($config);

        if (!isset($config['database']) || !is_string($config['database'])) {
            throw new \InvalidArgumentException(
                'SQLite database must be a path string; got '
                . (isset($config['database']) ? get_debug_type($config['database']) : 'nothing')
                . '.'
            );
        }
    }
}
