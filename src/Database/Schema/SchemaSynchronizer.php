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
     * Compute the changes needed to reach the desired state, touching
     * nothing.
     *
     * Takes no lock: the caller must hold the `radiant:schema` lock across
     * the whole plan → display → apply flow to guarantee the shown plan is
     * exactly what gets applied.
     *
     * @param  list<Blueprint>  $desired
     * @param  list<string>  $protected  Tables that must never be dropped or offered as a rename target.
     * @param  bool  $dropTables  Whether undeclared live tables are emitted as DropTable changes.
     * @return list<SchemaChange>
     * @throws \LogicException
     */
    public function plan(array $desired, array $protected = [], bool $dropTables = true): array
    {
        return (new SchemaDiffer($this->connection->schemaInspector))->diff($desired, $protected, $dropTables);
    }

    /**
     * Apply pre-computed changes — no re-diff.
     *
     * Takes no lock: the caller must hold the `radiant:schema` lock across
     * the whole plan → display → apply flow.
     *
     * @param  list<SchemaChange>  $plan
     * @param  (callable(SchemaChange): bool)|null  $confirm  The destructive-change gate; null means fail-fast.
     * @param  (callable(SchemaChange): void)|null  $onChange  Invoked after each change is applied successfully.
     * @param  bool  $transactional  Whether the apply loop is atomic.
     * @return list<SchemaChange>  The changes actually applied — declined changes are excluded.
     * @throws \LogicException
     * @throws \Throwable
     */
    public function apply(
        array $plan,
        callable|null $confirm = null,
        callable|null $onChange = null,
        bool $transactional = false,
    ): array {
        $this->assertTransactionalSupport($transactional);

        return $this->applyChanges($plan, $confirm, $onChange, $transactional);
    }

    /**
     * Sync the desired state to the live schema.
     *
     * @param  list<Blueprint>  $desired
     * @param  (callable(SchemaChange): bool)|null  $confirm  The destructive-change gate; null means fail-fast.
     * @param  (callable(SchemaChange): void)|null  $onChange  Invoked after each change is applied successfully.
     * @param  bool  $transactional  Whether the apply loop is atomic.
     * @return list<SchemaChange>
     * @throws \LogicException
     * @throws \Throwable
     */
    public function sync(
        array $desired,
        callable|null $confirm = null,
        callable|null $onChange = null,
        bool $transactional = false,
    ): array {
        $this->assertTransactionalSupport($transactional);

        return $this->connection->withLock(function () use ($desired, $confirm, $onChange, $transactional): array {
            $changes = $this->plan($desired);

            return $this->applyChanges($changes, $confirm, $onChange, $transactional);
        }, 'radiant:schema');
    }

    /**
     * Refuse a transactional apply on a dialect without transactional DDL.
     *
     * @param  bool  $transactional
     * @throws \LogicException
     */
    private function assertTransactionalSupport(bool $transactional): void
    {
        if ($transactional && !$this->connection->supportsTransactionalDdl()) {
            throw new \LogicException(sprintf(
                'The [%s] dialect does not support transactional DDL (every DDL statement performs an '
                . 'implicit commit); a transactional apply would silently commit changes one by one '
                . 'while appearing atomic. Run the apply loop without the transactional option.',
                $this->connection::class,
            ));
        }
    }

    /**
     * Apply the changes in order, gated by the confirm callback.
     *
     * @param  list<SchemaChange>  $changes
     * @param  (callable(SchemaChange): bool)|null  $confirm
     * @param  (callable(SchemaChange): void)|null  $onChange
     * @param  bool  $transactional
     * @return list<SchemaChange>
     * @throws \LogicException
     * @throws \Throwable
     */
    private function applyChanges(
        array $changes,
        callable|null $confirm,
        callable|null $onChange,
        bool $transactional,
    ): array {
        $applied = [];

        $apply = function () use ($changes, $confirm, $onChange, &$applied): void {
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

                if ($onChange !== null) {
                    $onChange($change);
                }

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
    }
}
