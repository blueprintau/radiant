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
     * Default PDO attributes applied to every SQL connection.
     *
     * - ATTR_STRINGIFY_FETCHES => false — return native types, not strings.

     * - ATTR_EMULATE_PREPARES => false — use real prepared statements.

     * These are strong defaults but can be overridden by the user when they
     * have a good reason. Subclasses may override this property to provide
     * driver-appropriate defaults; it is read with `static::` late binding
     * in {@see createPdo()} so overrides take effect.
     *
     * @var array<int, int|bool>
     */
    protected static array $DEFAULT_OPTIONS = [
        \PDO::ATTR_STRINGIFY_FETCHES => false,
        \PDO::ATTR_EMULATE_PREPARES => false
    ];

    /**
     * PDO attributes that cannot be overridden by the user — always merged
     * last.
     *
     * ERRMODE_EXCEPTION is contract-critical: the entire error path of
     * {@see SqlConnection} (`run()`, transactions, savepoints) assumes
     * failures surface as \PDOException rather than silent `false` returns.
     * Letting a user drop it to ERRMODE_SILENT would break the abstraction
     * in a confusing way (e.g. prepare() returning false → TypeError), so it
     * is forced unconditionally on every SQL connection.
     *
     * Subclasses may override this property to force driver-specific
     * attributes (e.g. MySQL's INIT_COMMAND or SQLite's BUSY_TIMEOUT); it is
     * read with `static::` late binding in {@see createPdo()} so overrides
     * take effect. ERRMODE itself remains guaranteed regardless of any
     * override, because {@see SqlConnection::__construct()} re-asserts it on
     * every connection.
     *
     * @var array<int, int|bool>
     */
    protected static array $FORCED_OPTIONS = [
        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
    ];

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
     * Create a PDO instance from a DSN, merging user options between the
     * driver defaults and the non-overridable forced attributes.
     *
     * Precedence, lowest to highest: {@see DEFAULT_OPTIONS} → user-supplied
     * `$options` → {@see FORCED_OPTIONS}. The forced layer always wins, so
     * contract-critical attributes (ERRMODE_EXCEPTION) can't be dropped —
     * this is a defense-in-depth guarantee alongside the enforcement in
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
        $options = array_replace(static::$DEFAULT_OPTIONS, $options, static::$FORCED_OPTIONS);
        try {
            return \PDO::connect($dsn, $username, $password, $options);
        } catch (\PDOException $e) {
            throw new ConnectionException("Could not connect to database.", $e->getCode(), $e);
        }
    }
}
