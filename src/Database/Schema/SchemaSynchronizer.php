<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Schema;

use BlueprintAU\Radiant\Database\Connections\SqlConnection;

/**
 * The sync runner — diff → confirm → apply, under the schema lock.
 *
 * @see SchemaDiffer
 * @see SqlConnection::apply()
 */
final class SchemaSynchronizer
{
    /**
     * Create a synchronizer over a SQL connection.
     *
     * @param  SqlConnection  $connection
     */
    public function __construct(
        private readonly SqlConnection $connection,
    ) {
    }

    /**
     * Sync the desired state to the live schema.
     *
     * @param  list<Blueprint>  $desired
     * @param  callable(SchemaChange): bool|null  $confirm  The destructive-change gate; null means fail-fast.
     * @param  bool  $transactional  Whether the apply loop is atomic.
     * @return list<SchemaChange>
     * @throws \LogicException
     * @throws \Throwable
     */
    public function sync(
        array $desired,
        callable|null $confirm = null,
        bool $transactional = false,
    ): array {
        if ($transactional && !$this->connection->supportsTransactionalDdl()) {
            throw new \LogicException(sprintf(
                'The [%s] dialect does not support transactional DDL (every DDL statement performs an '
                . 'implicit commit); a transactional apply would silently commit changes one by one '
                . 'while appearing atomic. Run the apply loop without the transactional option.',
                $this->connection::class,
            ));
        }

        return $this->connection->withLock(function () use ($desired, $confirm, $transactional): array {
            $differ = new SchemaDiffer($this->connection->schemaInspector);
            $changes = $differ->diff($desired);

            $applied = [];

            $apply = function () use ($changes, $confirm, &$applied): void {
                foreach ($changes as $change) {
                    if ($change->destructive) {
                        if ($confirm === null) {
                            throw new \LogicException(sprintf(
                                'Refusing to apply the destructive change [%s] without confirmation: %s',
                                $change->operation->value,
                                $change->description,
                            ));
                        }

                        if (!$confirm($change)) {
                            continue; // declined — skipped, not applied.
                        }
                    }

                    $this->connection->apply($change);
                    $applied[] = $change;
                }
            };

            if ($transactional) {
                // The connection's transaction() helper: commit on success,
                // roll back on any exception (a failed rollback never
                // replaces the original exception).
                $this->connection->transaction($apply);
            } else {
                $apply();
            }

            return $applied;
        }, 'radiant:schema');
    }
}
