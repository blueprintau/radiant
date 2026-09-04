<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Connectors;

use BlueprintAU\Radiant\Database\Connectors\MySqlConnector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for charset validation:
 *
 * - the MySQL charset reaches the DSN and a raw
 *   `SET NAMES` statement, so it is allowlisted at validation time.
 */
final class MySqlCharsetTest extends TestCase
{
    /**
     * Hostile and invalid charset values — all must be rejected before they
     * can reach the DSN or `SET NAMES`.
     *
     * @return iterable<string, array{0: mixed}> The payloads.
     */
    public static function maliciousCharsetProvider(): iterable
    {
        yield 'stacked statement' => ['utf8mb4; DROP TABLE users; --'];
        yield 'quote escape' => ["utf8mb4' OR '1'='1"];
        yield 'newline' => ["utf8mb4\nDROP TABLE users"];
        yield 'not a charset' => ['definitely-not-a-charset'];
        yield 'empty' => [''];
        yield 'wrong type' => [3306];
    }

    /**
     * validConfig() must reject every hostile charset at construction time.
     *
     * @param mixed $charset The payload.
     */
    #[DataProvider('maliciousCharsetProvider')]
    public function testValidConfigRejectsInvalidCharsets(mixed $charset): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new MySqlConnector())->validConfig($this->config($charset));
    }

    /**
     * connect() must reject them too, for direct connector use.
     *
     * @param mixed $charset The payload.
     */
    #[DataProvider('maliciousCharsetProvider')]
    public function testConnectRejectsInvalidCharsets(mixed $charset): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new MySqlConnector())->connect($this->config($charset));
    }

    /**
     * Known MySQL charsets (any case) pass validation.
     */
    public function testValidConfigAcceptsKnownCharsets(): void
    {
        $connector = new MySqlConnector();
        foreach (['utf8mb4', 'UTF8MB4', 'latin1', 'ascii'] as $charset) {
            $connector->validConfig($this->config($charset));
        }
        // Reaching here without an exception is the assertion.
        $this->addToAssertionCount(1);
    }

    /**
     * A minimal valid MySQL config with the given charset.
     *
     * @param mixed $charset The charset value to inject.
     * @return array<string, mixed> The config.
     */
    private function config(mixed $charset): array
    {
        return [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'radiant',
            'username' => 'root',
            'password' => '',
            'charset' => $charset,
        ];
    }
}
