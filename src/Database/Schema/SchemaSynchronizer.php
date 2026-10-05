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
     * When the plan carries a change whose apply needs a transaction-free
     * connection (an FK-involved SQLite table rebuild), a `transactional:
     * true` apply degrades to a non-transactional loop — each such change
     * keeps its own internal atomicity. Run the apply OUTSIDE a held lock
     * transaction in that case: release the lock between planning and
     * applying.
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
     * Plans and applies under the `radiant:schema` lock — one
     * cross-process section. When the plan carries a change whose apply
     * needs a transaction-free connection (an FK-involved SQLite table
     * rebuild), the apply runs after the lock transaction closes: the
     * lock is itself a transaction on SQLite, and the change cannot run
     * inside any transaction.
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

        ['applied' => $applied, 'deferred' => $deferred] = $this->connection->withLock(
            function () use ($desired, $confirm, $onChange, $transactional): array {
                $changes = $this->plan($desired);

                // A change the dialect cannot apply inside a transaction
                // (an FK-involved SQLite rebuild needs the foreign_keys
                // PRAGMA toggle outside one) defers the apply past the
                // lock transaction — the lock IS a transaction on SQLite.
                // The race window is fenced by the rebuild itself: it
                // re-reads the live table per change and its
                // foreign_key_check gate fails loud on drift it cannot
                // reconcile.
                if (array_any(
                    $changes,
                    fn (SchemaChange $change): bool => $this->connection->changeRequiresStandaloneTransaction($change, $changes),
                )) {
                    return ['applied' => [], 'deferred' => $changes];
                }

                return [
                    'applied' => $this->applyChanges($changes, $confirm, $onChange, $transactional),
                    'deferred' => null,
                ];
            },
            'radiant:schema',
        );

        if ($deferred !== null) {
            return $this->applyChanges($deferred, $confirm, $onChange, $transactional);
        }

        return $applied;
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

        // A change the dialect cannot apply inside a transaction (a SQLite
        // table rebuild needs the foreign_keys PRAGMA toggle outside one)
        // skips the wrapper: each such change stays internally atomic on
        // its own. Checking once up front keeps the loop's per-change
        // atomicity boundary uniform for the whole plan. The deferred leg
        // of sync() re-derives the verdict here — the plan-aware predicate
        // answers rename-led batches on its own.
        $degrade = $transactional
            && array_any($changes, fn (SchemaChange $change): bool => $this->connection->changeRequiresStandaloneTransaction($change, $changes));

        if ($transactional && !$degrade) {
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
