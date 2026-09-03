<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Connectors;

use BlueprintAU\Radiant\Database\Connections\MySqlConnection;
use BlueprintAU\Radiant\Database\Connections\SqlConnection;
use Override;

/**
 * MySQL connector — builds a PDO MySQL connection from config.
 *
 * @see \BlueprintAU\Radiant\Database\Connections\SqlConnection
 */
final class MySqlConnector extends SqlConnector
{
    /**
     * MySQL-mandated PDO attributes — merged last, cannot be overridden by
     * the user.
     *
     * `PDO::MYSQL_ATTR_FOUND_ROWS` makes UPDATE/DELETE return the number of
     * rows *actually changed* rather than rows *matched*. The update()
     * contract is "affected rows", and dirty-tracking save() depends on that
     * being honest — so this is forced on.
     *
     * Note: FOUND_ROWS is a recommendation from the plan, not yet verified
     * against a live MySQL server.
     *
     * @var array<int, int|bool>
     */
    protected static array $FORCED_OPTIONS = [
        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        \PDO\Mysql::ATTR_FOUND_ROWS => true,
    ];

    /**
     * Create a MySQL connection from the given config.
     *
     * The charset is set once at connect time, driven by config, so it can't
     * be a static forced option.
     *
     * @param array<string,mixed> $config The connection config (host, port,
     *        database, username, password, charset, …).
     * @return MySqlConnection A ready-to-use MySQL connection.
     * @throws \InvalidArgumentException If a required field is missing or
     *         malformed.
     */
    #[Override]
    public function connect(array $config): SqlConnection
    {
        $host = $config['host'] ?? null;
        $port = $config['port'] ?? 3306;
        $database = $config['database'] ?? null;
        $charset = $config['charset'] ?? 'utf8mb4';

        if (!is_string($host) || !is_int($port) || !is_string($database)) {
            throw new \InvalidArgumentException(
                'MySQL requires a string host, an integer port and a string database.'
            );
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $host,
            $port,
            $database,
            $charset,
        );

        $options = $config['options'] ?? [];
        $pdo = $this->createPdo($dsn, $config['username'] ?? null, $config['password'] ?? null, $options);

        // The charset must match the connection: set it once at connect time
        // (config-driven, so it can't be a static forced option).
        $pdo->exec("SET NAMES {$charset}");

        return new MySqlConnection($pdo);
    }

    /**
     * Validate the shape of a MySQL connection config.
     *
     * MySQL needs `host`, `port` and `database` (the only fields it consumes
     * beyond the shared `driver`). The shared {@see SqlConnector::validConfig()}
     * already checked `driver`; this checks the MySQL-specific fields.
     *
     * @param array<string,mixed> $config The connection config to validate.
     * @throws \InvalidArgumentException When a required field is missing or
     *         malformed.
     */
    #[Override]
    public function validConfig(array $config): void
    {
        parent::validConfig($config);

        $host = $config['host'] ?? null;
        $port = $config['port'] ?? null;
        $database = $config['database'] ?? null;

        if (!is_string($host) || $host === '') {
            throw new \InvalidArgumentException(
                'MySQL requires a non-empty string "host"; got '
                . ($host === null ? 'nothing' : get_debug_type($host))
                . '.'
            );
        }

        if (!is_int($port)) {
            throw new \InvalidArgumentException(
                'MySQL requires an integer "port"; got '
                . ($port === null ? 'nothing' : get_debug_type($port))
                . '.'
            );
        }

        if (!is_string($database) || $database === '') {
            throw new \InvalidArgumentException(
                'MySQL requires a non-empty string "database"; got '
                . ($database === null ? 'nothing' : get_debug_type($database))
                . '.'
            );
        }
    }
}