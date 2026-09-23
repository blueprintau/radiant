<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Connectors;

use BlueprintAU\Radiant\Database\Connections\SqlConnection;
use BlueprintAU\Radiant\Database\Exceptions\ConnectionException;

/**
 * Base class for SQL connectors — shares the PDO construction logic
 * across drivers.
 *
 * Subclasses implement {@see connect()} to build the driver-specific DSN
 * and return a concrete {@see SqlConnection}. User-supplied options are
 * merged over the security-critical defaults, so they can't be accidentally
 * dropped.
 *
 * @see \BlueprintAU\Radiant\Database\Connections\SqlConnection
 *
 * @phpstan-type PdoOptions array<int, int|bool|array<mixed>>
 */
abstract class SqlConnector implements ConnectorInterface
{
    /**
     * Default PDO attributes applied to every SQL connection. Subclass
     * defaults are merged OVER these (see {@see createPdo()}), so subclasses
     * only declare their additions — e.g. SQLite's busy timeout — and do
     * not have to restate this list.
     *
     * - ATTR_STRINGIFY_FETCHES => false — return native types, not strings.
     *
     * These are strong defaults but can be overridden by the user when they
     * have a good reason. Subclasses may override this property to provide
     * driver-appropriate defaults; it is read with `static::` late binding
     * in {@see createPdo()} so overrides take effect.
     *
     * @var array<int, int|bool>
     */
    private const DEFAULT_OPTIONS = [
        \PDO::ATTR_STRINGIFY_FETCHES => false
    ];

    /**
     * PDO attributes that cannot be overridden by the user — always merged
     * after user options.
     *
     * ERRMODE_EXCEPTION is contract-critical: the entire error path of
     * {@see SqlConnection} (`run()`, transactions, savepoints) assumes
     * failures surface as \PDOException rather than silent `false` returns.
     * Letting a user drop it to ERRMODE_SILENT would break the abstraction
     * in a confusing way (e.g. prepare() returning false → TypeError), so it
     * is forced unconditionally on every SQL connection.
     *
     * EMULATE_PREPARES => false forces real server-side prepares on every
     * SQL connector: client-side emulation interpolates bound values into
     * the SQL TEXT, which both widens the surface of any host raw-path
     * mistake into classic SQL injection and makes failed-query text carry
     * real PII.
     *
     * Merge order and extension: see {@see createPdo()}. In short, subclass
     * forced options merge OVER these — so a connector can deliberately
     * override a base-forced attribute where its dialect demands it — but
     * user config can never reach past any forced layer. (ERRMODE itself
     * remains guaranteed regardless of everything, because
     * {@see SqlConnection::__construct()} re-asserts it on every
     * connection.)
     *
     * @var array<int, int|bool>
     */
    private const FORCED_OPTIONS = [
        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        // Native prepared statements on every dialect; see the class docblock
        // for why emulation must not be re-enableable via config.
        \PDO::ATTR_EMULATE_PREPARES => false,
    ];

    /**
     * The subclass's driver-appropriate default options — merged over the
     * base {@see DEFAULT_OPTIONS} layer.
     *
     * Override to declare only the driver's DIFFERENCES (e.g. SQLite's
     * busy timeout); the base defaults always apply underneath.
     *
     * @return array<int, int|bool> The subclass default options.
     */
    protected function defaultOptions(): array
    {
        return [];
    }

    /**
     * The subclass's deliberate dialect mandates — merged over the base
     * {@see FORCED_OPTIONS} layer, able to override even a base-forced
     * attribute when a dialect genuinely requires it (a documented,
     * code-level decision — never reachable from config).
     *
     * @return array<int, int|bool> The subclass forced options.
     */
    protected function forcedOptions(): array
    {
        return [];
    }

    /**
     * Create a connection for the given config.
     *
     * @param array<string,mixed> $config The connection config.
     * @return SqlConnection A ready-to-use SQL connection.
     * @throws ConnectionException If the connection cannot be established.
     */
    #[\Override]
    abstract public function connect(array $config): SqlConnection;

    /**
     * Validate the shape of a connection config before it reaches
     * {@see connect()}.
     *
     * The shared `driver` key is owned and validated by
     * {@see \BlueprintAU\Radiant\Database\DatabaseManager} — it is the only
     * consumer that reads it (to pick the connector from its registry).
     * Connectors validate only the fields they themselves consume, so the
     * base implementation validates the one field every SQL connector
     * shares — `options` — and each driver connector validates its own
     * fields (e.g. {@see SqliteConnector} checks `database` is a path
     * string).
     *
     * @param array<string,mixed> $config The connection config to validate.
     * @throws \InvalidArgumentException When the config is not shaped like
     *         this connector expects.
     */
    #[\Override]
    public function validConfig(array $config): void
    {
        if (array_key_exists('options', $config)) {
            $this->validateOptions($config['options']);
        }
    }

    /**
     * Validate a config value that is interpolated into the DSN.
     *
     * DSN metacharacters matter: a `host` or `database` value containing a
     * `;` re-binds the DSN's key-value parsing (e.g. a database of
     * `bar;unix_socket=/tmp/x` or `;sslmode=disable` silently redefines
     * connection parameters — wrong database, dropped TLS, socket
     * redirection). Control characters and whitespace are rejected for the
     * same reason. This is defense-in-depth: config is normally
     * host-developer-owned, but the library cannot know where config
     * originates, so the values it interpolates are bounded at the boundary.
     *
     * @param mixed $value The raw config value (expected string).
     * @param string $field The config field name, for the error message.
     * @return string The validated value.
     * @throws \InvalidArgumentException When the value is not a string or
     *         contains DSN metacharacters, control characters, or whitespace.
     */
    protected function validDsnField(mixed $value, string $field): string
    {
        if (!is_string($value) || $value === '') {
            throw new \InvalidArgumentException(
                'The "' . $field . '" config field must be a non-empty string; got '
                . ($value === null ? 'nothing' : get_debug_type($value)) . '.'
            );
        }
        if (preg_match('/[;\s\x00-\x1f\x7f]/', $value) === 1) {
            throw new \InvalidArgumentException(
                'The "' . $field . '" config field must not contain semicolons, whitespace '
                . 'or control characters (they are DSN metacharacters); got a value that does.'
            );
        }
        return $value;
    }

    /**
     * Validate the user-supplied PDO options before they reach the driver.
     *
     * PDO is lenient about malformed options and may silently ignore them or
     * fail with a vague \PDOException. Checking the shape here catches the
     * common mistakes — a non-array, non-integer attribute keys, or
     * non-scalar values — and fails fast with a message that names the
     * problem, which is far easier to debug than a generic driver error.
     *
     * This validates shape only, not driver-specific attribute support: what
     * an attribute means is up to PDO and the active driver, and PDO already
     * fails fast on unsupported attributes inside createPdo().
     *
     * @param mixed $options The raw options value from the config (expected
     *        to be an array of PDO attributes).
     * @throws \InvalidArgumentException When the options are not shaped like
     *         an attribute array — non-array, non-integer key, or a value
     *         that is not a scalar or array.
     */
    final protected function validateOptions(mixed $options): void
    {
        if (!is_array($options)) {
            throw new \InvalidArgumentException(
                'PDO options must be an array of attributes; got ' . get_debug_type($options) . '.'
            );
        }

        foreach ($options as $key => $value) {
            if (!is_int($key)) {
                throw new \InvalidArgumentException(
                    'PDO option keys must be integer attribute constants (PDO::ATTR_*); got ' . get_debug_type($key) . '.'
                );
            }

            if (!is_scalar($value) && !is_array($value)) {
                throw new \InvalidArgumentException(
                    'PDO option values must be a scalar or array; got ' . get_debug_type($value) . ' for option ' . $key . '.'
                );
            }
        }
    }

    /**
     * Create a PDO instance from a DSN, merging option layers in strict
     * precedence order.
     *
     * Merge order, lowest to highest:
     *
     * 1. base {@see DEFAULT_OPTIONS} — the library's cross-dialect defaults;
     * 2. `static::$DEFAULT_OPTIONS` — the subclass's driver-appropriate
     *    additions (e.g. SQLite's busy timeout), merged so a subclass only
     *    declares its differences;
     * 3. user-supplied `$options` — host config, which can tune anything
     *    the library merely defaults;
     * 4. base {@see FORCED_OPTIONS} — contract-critical attributes user
     *    config can never drop;
     * 5. `static::$FORCED_OPTIONS` — the subclass's deliberate dialect
     *    mandates, able to override even the base forced layer when a
     *    dialect genuinely requires it (a documented, code-level decision —
     *    never reachable from config).
     *
     * This is a defense-in-depth guarantee alongside the enforcement in
     * {@see SqlConnection::__construct()}, which also covers PDO instances
     * built directly.
     *
     * `PDO::connect()` (PHP 8.4+) is used rather than `new \PDO()` because it
     * returns the driver-specific subclass (Pdo\Sqlite, Pdo\Pgsql, Pdo\MySql)
     * chosen by the DSN. Those subclasses expose driver-only methods
     * (e.g. Pdo\Pgsql::copyTo(), copyFrom(), lobOpen(), getNotify()) that a
     * base \PDO can never call — a plain instance would make them
     * unreachable. The subclass still passes every `instanceof \PDO` check,
     * so nothing breaks. (Requires PHP 8.4+; see composer.json.)
     *
     * @param string $dsn The driver-specific DSN (e.g. "mysql:host=…").
     * @param string|null $username The username, or null if not required.
     * @param string|null $password The password, or null if not required.
     * @param PdoOptions $options User-supplied PDO attributes.
     * @return \PDO A configured PDO instance (a Pdo\* driver subclass),
     *         ready to use.
     * @throws ConnectionException When the PDO constructor fails — the
     *         underlying \PDOException is preserved as the previous exception.
     */
    final protected function createPdo(string $dsn, ?string $username, #[\SensitiveParameter] ?string $password, array $options): \Pdo
    {
        $this->validateOptions($options);
        $options = array_replace(
            self::DEFAULT_OPTIONS,
            static::defaultOptions(),
            $options,
            self::FORCED_OPTIONS,
            static::forcedOptions(),
        );
        try {
            return \PDO::connect($dsn, $username, $password, $options);
        } catch (\PDOException $e) {
            throw new ConnectionException("Could not connect to database.", $e->getCode(), $e);
        }
    }
}
