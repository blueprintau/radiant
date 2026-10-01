<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Support;

use BlueprintAU\Radiant\Database\Connectors\ConnectorInterface;
use BlueprintAU\Radiant\Database\DatabaseManager;
use BlueprintAU\Radiant\Database\Connections\ConnectionInterface;

/**
 * Fixture connector: wires the array-row connection for the hydration
 * guard tests.
 */
final class ArrayRowConnector implements ConnectorInterface
{
    /**
     * Register this connector as the 'default' driver's handler.
     *
     * @param  DatabaseManager  $manager  The manager to extend.
     * @return void
     */
    public static function install(DatabaseManager $manager): void
    {
        $manager->extendConnector('sqlite', self::class);
    }

    /**
     * Build the array-row connection.
     *
     * @param  array<string, mixed>  $config
     * @return ConnectionInterface
     */
    #[\Override]
    public function connect(array $config): ConnectionInterface
    {
        return new ArrayRowConnection();
    }

    /**
     * Accept any config shape.
     *
     * @param  array<string, mixed>  $config
     * @return void
     */
    #[\Override]
    public function validConfig(array $config): void
    {
    }
}
