<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Connectors;

use BlueprintAU\Radiant\Database\Connections\PostgresConnection;
use BlueprintAU\Radiant\Database\Connections\SqlConnection;
use Override;

/**
 * Postgres connector — builds a PDO Postgres connection from config.
 *
 * @see \BlueprintAU\Radiant\Database\Connections\SqlConnection
 *
 * @phpstan-import-type PdoOptions from \BlueprintAU\Radiant\Database\Connectors\SqlConnector
 */
final class PostgresConnector extends SqlConnector
{
    /**
     * `sslmode` values Postgres accepts. The config field is allowlisted so
     * the DSN-integrated setting cannot be hijacked by a metacharacter
     * trick or set to a non-TLS mode by accident.
     *
     * @var list<string>
     */
    private const ALLOWED_SSLMODES = ['disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full'];

    /**
     * Validate a configured sslmode against the allowlist.
     *
     * @param mixed $sslmode The raw sslmode config value.
     * @return string The validated sslmode.
     * @throws \InvalidArgumentException When the sslmode is not one Postgres accepts.
     */
    private function validSslmode(mixed $sslmode): string
    {
        if (!is_string($sslmode) || !in_array(strtolower($sslmode), self::ALLOWED_SSLMODES, true)) {
            throw new \InvalidArgumentException(
                'Postgres "sslmode" must be one of: ' . implode(', ', self::ALLOWED_SSLMODES)
                . '; got ' . (is_string($sslmode) ? "[{$sslmode}]" : get_debug_type($sslmode)) . '.'
            );
        }
        return strtolower($sslmode);
    }
    /**
     * Create a Postgres connection from the given config.
     *
     * @param array{host?: mixed, port?: mixed, database?: mixed, sslmode?: mixed,
     *        username?: string|null, password?: string|null, options?: PdoOptions,
     *        ...<mixed>} $config The connection config (host, port, database,
     *        sslmode, username, password, …).
     * @return PostgresConnection A ready-to-use Postgres connection.
     * @throws \InvalidArgumentException If $host or $database is missing.
     */
    #[Override]
    public function connect(array $config): SqlConnection
    {
        $this->validConfig($config);

        $host = $config['host'] ?? null;
        $port = $config['port'] ?? 5432;
        $database = $config['database'] ?? null;

        if (!is_string($host) || !is_int($port) || !is_string($database)) {
            throw new \InvalidArgumentException(
                'Postgres requires a string host, an integer port and a string database.'
            );
        }

        // (Port's type was already validated by validConfig() above; the
        // combined check remains as defense-in-depth for direct connect()
        // calls that skip the manager.)

        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s',
            $host,
            $port,
            $database,
        );

        // sslmode goes through its own validated config slot — it is a
        // DSN-integrated key, so it must come from config, allowlisted, not
        // injected through a metacharacter in host/database.
        if (array_key_exists('sslmode', $config)) {
            $dsn .= ';sslmode=' . $this->validSslmode($config['sslmode']);
        }

        $options = $config['options'] ?? [];
        $pdo = $this->createPdo($dsn, $config['username'] ?? null, $config['password'] ?? null, $options);

        // Postgres only supports native prepared statements (no emulation),
        // so the inherited EMULATE_PREPARES => false default is moot but
        // harmless. Native types with STRINGIFY_FETCHES => false are the
        // Postgres codec's contract (microsecond datetimes). No extra forced
        // attributes — Postgres' needs are met by the base defaults.
        return new PostgresConnection($pdo);
    }

    /**
     * Validate the shape of a Postgres connection config.
     *
     * Postgres needs `host` and `database` — the only fields it consumes. The
     * shared `driver` key is owned (and validated) by
     * {@see \BlueprintAU\Radiant\Database\DatabaseManager}.
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
                'Postgres requires a non-empty string "host"; got '
                . ($host === null ? 'nothing' : get_debug_type($host))
                . '.'
            );
        }

        if (array_key_exists('port', $config) && !is_int($config['port'])) {
            throw new \InvalidArgumentException(
                'Postgres "port" must be an integer; got '
                . get_debug_type($config['port'])
                . '.'
            );
        }

        if (!is_string($database) || $database === '') {
            throw new \InvalidArgumentException(
                'Postgres requires a non-empty string "database"; got '
                . ($database === null ? 'nothing' : get_debug_type($database))
                . '.'
            );
        }

        // host and database are interpolated into the DSN — metacharacters
        // there re-bind the DSN's key-value parsing (a `;` can inject
        // sslmode=disable or a unix socket). sslmode itself is validated
        // separately when present.
        $this->validDsnField($host, 'host');
        $this->validDsnField($database, 'database');
        if (array_key_exists('sslmode', $config)) {
            $this->validSslmode($config['sslmode']);
        }
    }
}