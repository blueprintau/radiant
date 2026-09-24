<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Connectors;

use BlueprintAU\Radiant\Database\Connections\ConnectionInterface;
use BlueprintAU\Radiant\Database\Connections\CsvConnection;
use Override;

/**
 * CSV connector — builds a {@see CsvConnection} from config.
 *
 * @see \BlueprintAU\Radiant\Database\Connections\CsvConnection
 */
final class CsvConnector implements ConnectorInterface
{
    /**
     * Create a CSV connection from the given config.
     *
     * @param  array{path: mixed, readonly?: bool, ...<mixed>}  $config
     * @return ConnectionInterface
     * @throws \InvalidArgumentException
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
     * @param  array<string,mixed>  $config
     * @throws \InvalidArgumentException
     */
    #[Override]
    public function validConfig(array $config): void
    {
        if (!isset($config['path']) || !is_string($config['path']) || $config['path'] === '') {
            throw new \InvalidArgumentException(
                'CSV requires a non-empty "path" string; got '
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
