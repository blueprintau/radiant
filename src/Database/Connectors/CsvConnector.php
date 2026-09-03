<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Connectors;

use BlueprintAU\Radiant\Database\Connections\ConnectionInterface;
use BlueprintAU\Radiant\Database\Connections\CsvConnection;
use Override;

/**
 * CSV connector — builds a {@see CsvConnection} from config.
 *
 * A CSV connection is a non-SQL backend example: it implements the generic
 * {@see ConnectionInterface} directly and applies queries in PHP. SQL-only
 * features throw {@see \BlueprintAU\Radiant\Database\Exceptions\UnsupportedFeatureException}.
 *
 * @see \BlueprintAU\Radiant\Database\Connections\CsvConnection
 */
final class CsvConnector implements ConnectorInterface
{
    /**
     * Create a CSV connection from the given config.
     *
     * @param array{path: mixed, readonly?: bool, ...<mixed>} $config The
     *        connection config (path, readonly, …).
     * @return ConnectionInterface A ready-to-use CSV connection.
     * @throws \InvalidArgumentException If the path is not a string.
     */
    #[Override]
    public function connect(array $config): ConnectionInterface
    {
        $this->validConfig($config);

        $path = $config['path'];

        if (!is_string($path)) {
            throw new \InvalidArgumentException(
                'CSV connection requires a "path" string; got ' . get_debug_type($path) . '.'
            );
        }

        return new CsvConnection($path, (bool) ($config['readonly'] ?? false));
    }

    /**
     * Validate the shape of a CSV connection config.
     *
     * CSV needs a `path` string, and an optional `readonly` boolean. The
     * shared `driver` key is owned by the {@see DatabaseManager} and is not
     * validated here.
     *
     * @param array<string,mixed> $config The connection config to validate.
     * @throws \InvalidArgumentException When the path is not a string.
     */
    #[Override]
    public function validConfig(array $config): void
    {
        if (!isset($config['path']) || !is_string($config['path'])) {
            throw new \InvalidArgumentException(
                'CSV requires a "path" string; got '
                    . (isset($config['path']) ? get_debug_type($config['path']) : 'nothing')
                    . '.'
            );
        }

        if (array_key_exists('readonly', $config) && !is_bool($config['readonly'])) {
            throw new \InvalidArgumentException(
                'CSV requires a "readonly" boolean; got '
                    . get_debug_type($config['readonly'])
                    . '.'
            );
        }
    }
}
