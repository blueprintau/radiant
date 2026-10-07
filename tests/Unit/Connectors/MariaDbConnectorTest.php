<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Connectors;

use BlueprintAU\Radiant\Database\Connectors\MariaDbConnector;
use PHPUnit\Framework\TestCase;

/**
 * MariaDB connector — validation messages name the dialect, and the
 * 'mariadb' driver resolves in the manager's registry.
 */
final class MariaDbConnectorTest extends TestCase
{
    /**
     * A valid MariaDB config passes validation.
     */
    #[\PHPUnit\Framework\Attributes\DoesNotPerformAssertions]
    public function testValidConfigPasses(): void
    {
        (new MariaDbConnector())->validConfig([
            'driver' => 'mariadb',
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'radiant',
        ]);
    }

    /**
     * A missing host rejects with the MariaDB label in the message.
     */
    public function testMissingHostThrowsWithMariaDbLabel(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('MariaDB requires a non-empty string "host"; got nothing.');

        (new MariaDbConnector())->validConfig([
            'port' => 3306,
            'database' => 'radiant',
        ]);
    }

    /**
     * The 'mariadb' driver resolves to the MariaDB connector in the
     * manager's registry — its field validation runs (rather than the
     * "No connector registered" lookup failure an unregistered driver
     * throws).
     */
    public function testManagerRegistryResolvesMariaDbDriver(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('MariaDB requires a non-empty string "host"');

        new \BlueprintAU\Radiant\Database\DatabaseManager([
            'default' => [
                'driver' => 'mariadb',
                'port' => 3306,
                'database' => 'radiant',
            ],
        ]);
    }

    /**
     * A non-integer port rejects with the MariaDB label in the message.
     */
    public function testNonIntPortThrowsWithMariaDbLabel(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('MariaDB requires an integer "port"; got string.');

        (new MariaDbConnector())->validConfig([
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => 'radiant',
        ]);
    }

    /**
     * A missing database rejects with the MariaDB label in the message.
     */
    public function testMissingDatabaseThrowsWithMariaDbLabel(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('MariaDB requires a non-empty string "database"; got nothing.');

        (new MariaDbConnector())->validConfig([
            'host' => '127.0.0.1',
            'port' => 3306,
        ]);
    }

    /**
     * A charset outside the allowlist rejects with the MariaDB label.
     */
    public function testUnknownCharsetThrowsWithMariaDbLabel(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('MariaDB "charset" must be one of:');

        (new MariaDbConnector())->validConfig([
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'radiant',
            'charset' => 'latin9',
        ]);
    }
}
