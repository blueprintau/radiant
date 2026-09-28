<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Database;

use BlueprintAU\Radiant\Database;
use BlueprintAU\Radiant\Database\Connections\ConnectionInterface;
use BlueprintAU\Radiant\Database\Connectors\ConnectorInterface;
use BlueprintAU\Radiant\Database\DatabaseManager;
use BlueprintAU\Radiant\Tests\Support\NullConnection;
use PHPUnit\Framework\TestCase;

/**
 * {@see DatabaseManager} lifecycle: the flush()/disconnect() eviction
 * paths, config replacement, and the fail-fast guards — the registry
 * behaviors the connection-resolution tests don't touch.
 */
final class DatabaseManagerLifecycleTest extends TestCase
{
    /**
     * Reset the static facade after every test — the manager under test
     * is never installed there, but the facade must not leak state.
     */
    protected function tearDown(): void
    {
        Database::setManager(new DatabaseManager([
            'default' => ['driver' => 'sqlite', 'database' => ':memory:'],
        ]));
    }

    /**
     * A minimal sqlite connections map for the manager under test.
     *
     * @return array<string, array<string, mixed>>
     */
    private function sqliteMap(): array
    {
        return [
            'default' => ['driver' => 'sqlite', 'database' => ':memory:'],
            'secondary' => ['driver' => 'sqlite', 'database' => ':memory:'],
        ];
    }

    /**
     * flush() on a RESOLVED connection evicts it — the next resolution
     * builds a FRESH instance (a new sqlite handle, not the cached one).
     */
    public function testFlushEvictsResolvedConnection(): void
    {
        $manager = new DatabaseManager($this->sqliteMap());

        $first = $manager->connection('secondary');
        $manager->flush('secondary');
        $second = $manager->connection('secondary');

        self::assertNotSame($first, $second);
    }

    /**
     * flush() on a name that exists in the map but was never resolved is
     * a no-op — nothing to evict, no throw.
     */
    public function testFlushUnresolvedConnectionIsNoOp(): void
    {
        $manager = new DatabaseManager($this->sqliteMap());

        $manager->flush('secondary');

        // Still resolvable afterwards.
        self::assertInstanceOf(ConnectionInterface::class, $manager->connection('secondary'));
    }

    /**
     * flush() on a name that exists nowhere in the map rejects.
     */
    public function testFlushUnknownConnectionThrows(): void
    {
        $manager = new DatabaseManager($this->sqliteMap());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Unknown connection [ghost].');

        $manager->flush('ghost');
    }

    /**
     * A null-name flush() walks every resolved connection — each cache
     * entry is evicted, so the next resolutions return fresh instances.
     */
    public function testFlushAllEvictsEveryResolvedConnection(): void
    {
        $manager = new DatabaseManager($this->sqliteMap());

        $defaultFirst = $manager->connection();
        $secondaryFirst = $manager->connection('secondary');

        $manager->flush();

        self::assertNotSame($defaultFirst, $manager->connection());
        self::assertNotSame($secondaryFirst, $manager->connection('secondary'));
    }

    /**
     * flush() rolls back an open transaction on the evicted connection
     * before dropping it — no transaction leaks past the eviction. A
     * FILE-backed database is required: the :memory: schema dies with the
     * evicted connection, so the post-eviction assertions need the file.
     */
    public function testFlushRollsBackOpenTransaction(): void
    {
        $path = sys_get_temp_dir() . '/radiant-flush-' . uniqid() . '.sqlite';
        $manager = new DatabaseManager([
            'default' => ['driver' => 'sqlite', 'database' => ':memory:'],
            'secondary' => ['driver' => 'sqlite', 'database' => $path],
        ]);

        try {
            $connection = $manager->sqlConnection('secondary');
            $connection->statement('CREATE TABLE lifecycles (id INTEGER PRIMARY KEY, name TEXT)');
            $connection->transaction(function () use ($connection): void {
                $connection->table('lifecycles')->insert(['name' => 'kept']);
            });
            $connection->beginTransaction();
            $connection->table('lifecycles')->insert(['name' => 'doomed']);
            self::assertSame(1, $connection->transactionLevel());

            $manager->flush('secondary');

            // The rollback happened at eviction: the fresh connection sees
            // the committed row but NOT the doomed one.
            $fresh = $manager->sqlConnection('secondary');
            self::assertSame(0, $fresh->transactionLevel());
            self::assertSame(1, $fresh->table('lifecycles')->count());
            self::assertSame(0, $fresh->table('lifecycles')->where('name', '=', 'doomed')->count());
        } finally {
            @unlink($path);
        }
    }

    /**
     * disconnect() is a readable alias for flush($name) — same eviction
     * semantics.
     */
    public function testDisconnectEvictsResolvedConnection(): void
    {
        $manager = new DatabaseManager($this->sqliteMap());

        $first = $manager->connection('secondary');
        $manager->disconnect('secondary');
        $second = $manager->connection('secondary');

        self::assertNotSame($first, $second);
    }

    /**
     * setConnectionConfig() validates the replacement, swaps it in, and
     * evicts the resolved instance — the next resolution uses the NEW
     * config.
     */
    public function testSetConnectionConfigSwapsAndEvicts(): void
    {
        $manager = new DatabaseManager($this->sqliteMap());

        $first = $manager->connection('secondary');

        // A file-backed database the :memory: instance cannot see.
        $path = sys_get_temp_dir() . '/radiant-manager-' . uniqid() . '.sqlite';
        try {
            $manager->setConnectionConfig('secondary', ['driver' => 'sqlite', 'database' => $path]);

            $second = $manager->sqlConnection('secondary');
            self::assertNotSame($first, $second);

            // The replacement config is live: a table written to the
            // file-backed connection would not exist on :memory:.
            $second->statement('CREATE TABLE marker (id INTEGER PRIMARY KEY)');
            self::assertTrue($second->schemaInspector->hasTable('marker'));
        } finally {
            @unlink($path);
        }
    }

    /**
     * setConnectionConfig() on an unknown name rejects — add it via
     * addConnection() first.
     */
    public function testSetConnectionConfigUnknownNameThrows(): void
    {
        $manager = new DatabaseManager($this->sqliteMap());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Unknown connection [ghost]; add it via addConnection() first.');

        $manager->setConnectionConfig('ghost', ['driver' => 'sqlite', 'database' => ':memory:']);
    }

    /**
     * setConnectionConfig() validates the replacement through the
     * connector — a malformed config fails fast before the swap.
     */
    public function testSetConnectionConfigValidatesReplacement(): void
    {
        $manager = new DatabaseManager($this->sqliteMap());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Each connection must declare a non-empty string "driver"; got nothing.');

        $manager->setConnectionConfig('secondary', ['database' => ':memory:']);
    }

    /**
     * addConnection() registers a new named connection; a duplicate name
     * rejects.
     */
    public function testAddConnectionAndDuplicateThrows(): void
    {
        $manager = new DatabaseManager($this->sqliteMap());

        $manager->addConnection('tertiary', ['driver' => 'sqlite', 'database' => ':memory:']);
        self::assertTrue($manager->hasConnection('tertiary'));
        self::assertInstanceOf(ConnectionInterface::class, $manager->connection('tertiary'));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Connection [tertiary] is already defined.');

        $manager->addConnection('tertiary', ['driver' => 'sqlite', 'database' => ':memory:']);
    }

    /**
     * hasConnection() reports map membership, not resolution state.
     */
    public function testHasConnectionReflectsMapMembership(): void
    {
        $manager = new DatabaseManager($this->sqliteMap());

        self::assertTrue($manager->hasConnection('default'));
        self::assertFalse($manager->hasConnection('ghost'));
    }

    /**
     * currentConnection() reports the active name; usingConnection()
     * swaps it for the callback's duration and restores it after — even
     * when the callback throws.
     */
    public function testCurrentConnectionAndUsingConnectionRestore(): void
    {
        $manager = new DatabaseManager($this->sqliteMap());

        self::assertSame('default', $manager->currentConnection());

        $inside = $manager->usingConnection('secondary', function () use ($manager): string {
            return $manager->currentConnection();
        });
        self::assertSame('secondary', $inside);
        self::assertSame('default', $manager->currentConnection());

        $restored = $this->currentName($manager);

        try {
            $manager->usingConnection('secondary', function (): void {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
            $restored = $this->currentName($manager);
        }

        self::assertSame('default', $restored);
    }

    /**
     * Read the active connection name through a mixed-typed boundary —
     * PHPStan's pure-inference can't track the manager's mutable state
     * across the callback, so the read flows untyped.
     *
     * @param  DatabaseManager  $manager
     * @return mixed
     */
    private function currentName(DatabaseManager $manager): mixed
    {
        return $manager->currentConnection();
    }

    /**
     * extendConnector() registers a custom driver, and a connection
     * registered against it resolves through the custom connector.
     */
    public function testExtendConnectorResolvesCustomDriver(): void
    {
        $manager = new DatabaseManager($this->sqliteMap());

        $manager->extendConnector('memory', MemoryConnector::class);
        $manager->addConnection('inmem', ['driver' => 'memory']);

        self::assertInstanceOf(NullConnection::class, $manager->connection('inmem'));
    }

    /**
     * A connector class that does not implement ConnectorInterface
     * rejects at registration.
     */
    public function testExtendConnectorRejectsNonConnectorClass(): void
    {
        $manager = new DatabaseManager($this->sqliteMap());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(
            'Connector [DateTimeImmutable] must implement ' . \BlueprintAU\Radiant\Database\Connectors\ConnectorInterface::class . '.',
        );

        $manager->extendConnector('bogus', $this->asClassString(\DateTimeImmutable::class));
    }

    /**
     * Identity helper typed mixed so a non-connector class-string can
     * pass where the registration contract says class-string of the
     * interface — the runtime guard under test is what fires.
     *
     * @param  mixed  $value
     * @return mixed
     */
    private function asClassString(mixed $value): mixed
    {
        return $value;
    }

    /**
     * A config that is not an array rejects at construction. The
     * malformed entry flows through a mixed-typed helper — the boundary
     * PHPStan cannot see past, per the project's validation-at-boundary
     * convention.
     */
    public function testNonArrayConfigThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Each connection must be an array of settings; got string.');

        new DatabaseManager(['default' => $this->asString('not-an-array')]);
    }

    /**
     * Identity helper typed mixed so a string can pass where the map
     * contract says array — the runtime guard under test is what fires.
     *
     * @param  mixed  $value
     * @return mixed
     */
    private function asString(mixed $value): mixed
    {
        return $value;
    }
}

/**
 * Fixture connector: returns a stub connection without touching PDO.
 */
final class MemoryConnector implements ConnectorInterface
{
    /**
     * Build the stub connection.
     *
     * @param  array<string, mixed>  $config
     * @return ConnectionInterface
     */
    #[\Override]
    public function connect(array $config): ConnectionInterface
    {
        return new NullConnection();
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

