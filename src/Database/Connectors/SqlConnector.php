<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Connectors;

use BlueprintAU\Radiant\Database\Connections\SqlConnection;
use BlueprintAU\Radiant\Database\Exceptions\ConnectionException;

/**
 * Base class for SQL connectors — shares the PDO construction logic
 * across drivers.
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
     * Subclasses add their own via {@see defaultOptions()}.
     */
    private const DEFAULT_OPTIONS = [
        \PDO::ATTR_STRINGIFY_FETCHES => false
    ];

    /**
     * PDO attributes that cannot be overridden by the user.
     */
    private const FORCED_OPTIONS = [
        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        \PDO::ATTR_EMULATE_PREPARES => false,
    ];

    /**
     * The subclass's driver-appropriate default options.
     *
     * @return array<int, int|bool>
     */
    protected function defaultOptions(): array
    {
        return [];
    }

    /**
     * The subclass's deliberate dialect mandates.
     *
     * @return array<int, int|bool>
     */
    protected function forcedOptions(): array
    {
        return [];
    }

    /**
     * Create a connection for the given config.
     *
     * @param  array<string,mixed>  $config
     * @return SqlConnection
     * @throws ConnectionException
     */
    #[\Override]
    abstract public function connect(array $config): SqlConnection;

    /**
     * Validate the shape of a connection config before it reaches
     * {@see connect()}.
     *
     * @param  array<string,mixed>  $config
     * @throws \InvalidArgumentException
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
     * @param  mixed  $value  Must be a non-empty string free of DSN metacharacters.
     * @param  string  $field
     * @return string
     *
     * @throws \InvalidArgumentException
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
     * @param  mixed  $options
     * @throws \InvalidArgumentException
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
     * `PDO::connect()` (PHP 8.4+) is used rather than `new \PDO()` because
     * it returns the driver-specific subclass chosen by the DSN.
     *
     * @param  string  $dsn
     * @param  string|null  $username
     * @param  string|null  $password
     * @param  PdoOptions  $options
     * @return \PDO
     *
     * @throws ConnectionException
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
