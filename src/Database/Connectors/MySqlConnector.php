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
 *
 * @phpstan-import-type PdoOptions from \BlueprintAU\Radiant\Database\Connectors\SqlConnector
 */
class MySqlConnector extends SqlConnector
{
    /**
     * The dialect's name in the connector's validation messages.
     *
     * @return string
     */
    protected function dialectLabel(): string
    {
        return 'MySQL';
    }

    /**
     * The connection class this connector builds — the factory hook a
     * dialect subclass overrides.
     *
     * @param  \PDO  $pdo
     * @return MySqlConnection
     */
    protected function makeConnection(\Pdo $pdo): MySqlConnection
    {
        return new MySqlConnection($pdo);
    }

    /**
     * Charsets MySQL accepts on `SET NAMES` — the allowlist the configured
     * charset must match.
     *
     * @var list<string>
     */
    private const ALLOWED_CHARSETS = [
        'utf8mb4', 'utf8mb3', 'utf8', 'latin1', 'latin2', 'ascii', 'binary',
        'cp1250', 'cp1251', 'cp1256', 'cp932', 'euckr', 'gb18030', 'gb2312',
        'gbk', 'koi8r', 'koi8u', 'macce', 'macroman', 'sjis', 'tis620',
        'ucs2', 'ujis', 'utf16', 'utf16le', 'utf32',
    ];

    /**
     * Validate a configured charset against the allowlist.
     *
     * @param  mixed  $charset
     * @return string
     * @throws \InvalidArgumentException
     */
    private function validCharset(mixed $charset): string
    {
        if (!is_string($charset) || $charset === ''
            || !in_array(strtolower($charset), self::ALLOWED_CHARSETS, true)) {
            throw new \InvalidArgumentException(
                $this->dialectLabel() . ' "charset" must be one of: ' . implode(', ', self::ALLOWED_CHARSETS)
                . '; got ' . (is_string($charset) ? "[{$charset}]" : get_debug_type($charset)) . '.'
            );
        }
        return strtolower($charset);
    }

    /**
     * MySQL-mandated PDO attributes — merged over the base forced layer.
     *
     * `PDO::MYSQL_ATTR_FOUND_ROWS` makes UPDATE/DELETE return the number of
     * rows actually changed rather than rows matched, which the update()
     * contract depends on.
     *
     * @return array<int, int|bool>
     */
    #[\Override]
    protected function forcedOptions(): array
    {
        return [
            \PDO\Mysql::ATTR_FOUND_ROWS => true,
        ];
    }

    /**
     * Create a MySQL connection from the given config.
     *
     * @param  array{host?: mixed, port?: mixed, database?: mixed, username?: string|null,
     *        password?: string|null, charset?: string, options?: PdoOptions,
     *        ...<mixed>}  $config
     * @return MySqlConnection
     * @throws \InvalidArgumentException
     */
    #[Override]
    public function connect(array $config): SqlConnection
    {
        $this->validConfig($config);

        $host = $config['host'] ?? null;
        $port = $config['port'] ?? null;
        $database = $config['database'] ?? null;
        $charset = $this->validCharset($config['charset'] ?? 'utf8mb4');

        // validConfig() has already validated host, port and database — the
        // combined shape check stays as defense-in-depth for direct
        // connect() calls that skip the manager.
        if (!is_string($host) || !is_int($port) || !is_string($database)) {
            throw new \InvalidArgumentException(
                $this->dialectLabel() . ' requires a string host, an integer port and a string database.'
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

        return $this->makeConnection($pdo);
    }

    /**
     * Validate the shape of a MySQL connection config.
     *
     * @param  array<string,mixed>  $config
     * @throws \InvalidArgumentException
     */
    #[Override]
    public function validConfig(array $config): void
    {
        parent::validConfig($config);

        $host = $config['host'] ?? null;
        $port = $config['port'] ?? null;
        $database = $config['database'] ?? null;

        // The charset reaches the DSN and a raw SET NAMES statement — validate
        // it at construction time (fail-fast) as well as at connect time.
        if (array_key_exists('charset', $config)) {
            $this->validCharset($config['charset']);
        }

        if (!is_string($host) || $host === '') {
            throw new \InvalidArgumentException(
                $this->dialectLabel() . ' requires a non-empty string "host"; got '
                . ($host === null ? 'nothing' : get_debug_type($host))
                . '.'
            );
        }

        if (!is_int($port)) {
            throw new \InvalidArgumentException(
                $this->dialectLabel() . ' requires an integer "port"; got '
                . ($port === null ? 'nothing' : get_debug_type($port))
                . '.'
            );
        }

        if (!is_string($database) || $database === '') {
            throw new \InvalidArgumentException(
                $this->dialectLabel() . ' requires a non-empty string "database"; got '
                . ($database === null ? 'nothing' : get_debug_type($database))
                . '.'
            );
        }

        // host and database are interpolated into the DSN — metacharacters
        // there re-bind the DSN's key-value parsing (a `;` can inject
        // unix_socket or sslmode). The type/emptiness checks above give the
        // driver-specific message; this adds the metacharacter bound.
        $this->validDsnField($host, 'host');
        $this->validDsnField($database, 'database');
    }
}