<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Connectors;

use BlueprintAU\Radiant\Database\Connections\ConnectionInterface;

/**
 * Creates a connection from a config array.
 *
 * @see \BlueprintAU\Radiant\Database\Connections\ConnectionInterface
 */
interface ConnectorInterface
{
    /**
     * Create a connection from the given config.
     *
     * @param  array<string, mixed>  $config
     * @return ConnectionInterface
     */
    public function connect(array $config): ConnectionInterface;

    /**
     * Validate the shape of a connection config before it reaches
     * {@see connect()}.
     *
     * @param  array<string,mixed>  $config
     * @throws \InvalidArgumentException
     */
    public function validConfig(array $config): void;
}
