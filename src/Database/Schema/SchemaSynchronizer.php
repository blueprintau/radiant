<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Schema;

use BlueprintAU\Radiant\Database\Connections\SqlConnection;

/**
 * The sync runner — diff → confirm → apply, under the schema lock.
 *
 * The orchestration layer above the differ and the connection: the
 * documented three-step host loop (plan → show → apply) becomes one call.
 * The connection stays thin (it executes statements); the differ stays
 * pure (it computes changes); THIS class owns the workflow — the lock,
 * the destructive-change gate, and the apply order.
 *
 * **The confirm callback is the applier's decision point.** The differ
 * returns suggestions as DATA (advisory flags, destructive flags,
 * descriptions); acceptance happens HERE — `$confirm` receives every
 * destructive change and returns whether to apply it. A null `$confirm`
 * means fail-fast: any destructive change throws instead of applying.
 * This is the "differ suggests, applier decides" doctrine — the same
 * `SchemaChange` data a CLI (e.g. Lucent's) would consume directly.
 *
 * @see SchemaDiffer The pure diff computation.
 * @see SqlConnection::apply() The per-change executor.
 */
final class SchemaSynchronizer
{
    /**
     * Create a synchronizer over a SQL connection.
     *
     * @param SqlConnection $connection The connection to sync on — the
     *        same one the differ's inspector reads and the apply path
     *        writes, so the lock and the work share one session.
     */
    public function __construct(
        private readonly SqlConnection $connection,
    ) {
    }

    /**
     * Sync the desired state to the live schema.
     *
     * Under the cross-process schema lock (`'radiant:schema'`): diff →
     * surface descriptions → gate destructive changes through `$confirm`
     * → apply in the differ's order (creates dependency-ordered, then
     * renames, alters, drops reverse-dependency-ordered) → return the
     * applied changes.
     *
     * With `$transactional = true` the WHOLE apply loop runs inside one
     * transaction — a failed multi-change apply rolls back cleanly. The
     * dialect must support transactional DDL (SQLite, Postgres); MySQL
     * performs an implicit commit on every DDL statement, so the option
     * throws there rather than silently appearing atomic. A change that
     * must run OUTSIDE a transaction (the SQLite rebuild when it needs
     * the foreign_keys PRAGMA toggle) also throws under a transactional
     * apply — the rebuild refuses rather than cascade-deleting child rows.
     *
     * **On a dialect WITHOUT transactional DDL there is no undo.** MySQL's
     * implicit commits make a mid-apply failure un-rollbackable — the
     * honest options are (a) run without `$transactional` and accept that
     * earlier applied changes stay applied (the returned list tells the
     * host exactly what landed; a re-diff converges the rest), or (b)
     * snapshot-restore outside Radiant (dump/restore, or a staging
     * database). Radiant deliberately does NOT emit compensating DROP
     * statements on failure: auto-rolling back DDL by guessing inverses
     * (drop the table it just created, re-add the column it just dropped)
     * is the data-corruption scenario — the compensating op for a failed
     * destructive change can itself be destructive.
     *
     * @param list<Blueprint> $desired The desired states.
     * @param callable(SchemaChange): bool|null $confirm The destructive-
     *        change gate — receives each destructive change, returns
     *        whether to apply it. Null means fail-fast: the first
     *        destructive change throws.
     * @param bool $transactional Whether the apply loop is atomic (one
     *        transaction around every applied change).
     * @return list<SchemaChange> The changes that were applied, in apply
     *         order.
     * @throws \LogicException When a destructive change exists and no
     *         `$confirm` was given, when `$transactional` is set on a
     *         dialect without transactional DDL, or when a change must
     *         run outside a transaction.
     * @throws \Throwable Whatever apply throws — under a transactional
     *         apply the whole loop rolls back; otherwise earlier applied
     *         changes stay applied.
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
