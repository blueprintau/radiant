<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant;

use BlueprintAU\Radiant\Concerns\PrunesModels;
use BlueprintAU\Radiant\Database\Query\Enums\SortDirection;
use BlueprintAU\Radiant\Exceptions\PruneException;

/**
 * Opt-in row-level pruning — delete expired rows one instance at a time,
 * honoring every hook and lifecycle listener.
 *
 * A user-facing trait — models apply it themselves and MUST implement
 * {@see Prunable::prunable()} (declared abstract, so an unimplemented
 * `prunable()` fails at class load, not at first prune). Deleting runs
 * the model's normal delete path — `forceDelete()` on a soft-deletable
 * model (so trashed rows are purged too), `delete()` otherwise — meaning
 * `#[WriteHook]` claims, veto listeners, and MTI leaf-first deletes all
 * apply exactly as they do for a hand-written `$model->delete()`.
 *
 * Override the {@see Prunable::pruning()} hook to observe (or veto via a
 * thrown exception) each row before its delete.
 *
 * {@see Prunable::pruneAll()} batches through the candidates with a
 * keyset cursor (`pk > last`, ordered ascending, chunked), so a vetoed or
 * failing row can never wedge the loop and memory stays bounded at one
 * chunk of hydrated models. Failures inside the loop throw
 * {@see PruneException} naming the failing row; the deletes that already
 * landed stay landed — a restarted run converges (pruning is
 * idempotent-by-predicate).
 *
 * @mixin \BlueprintAU\Radiant\Model
 * @phpstan-require-extends \BlueprintAU\Radiant\Model
 */
trait Prunable
{
    /** @use PrunesModels<static> */
    use PrunesModels;

    /**
     * The prunable query — the retention predicate.
     *
     * Every row matching this query is a prune candidate. Must return a
     * builder for the calling model, must not set a limit, and must not
     * order the primary key descending — the guards enforce all three.
     *
     * @return ModelQueryBuilder<static>
     */
    abstract public static function prunable(): ModelQueryBuilder;

    /**
     * Prepare one row for pruning — a per-row pre-delete hook.
     *
     * Runs before each candidate's delete on the hydrated instance.
     * Override to log, notify, or veto (throw) a specific row. The
     * default body does nothing.
     *
     * @return void
     */
    protected function pruning(): void
    {
        // no-op by default
    }

    /**
     * Prune this model — run its delete path honoring hooks.
     *
     * A soft-deletable model force-deletes (the row — trashed or not —
     * leaves the table); otherwise the normal delete runs. Fails by
     * THROWING, like every instance write: a vetoed delete throws
     * {@see \BlueprintAU\Radiant\Exceptions\WriteVetoException}, a stale
     * row throws {@see \BlueprintAU\Radiant\Exceptions\StaleRowException}.
     *
     * @return void
     *
     * @throws \BlueprintAU\Radiant\Exceptions\WriteVetoException
     * @throws \BlueprintAU\Radiant\Exceptions\StaleRowException
     */
    public function prune(): void
    {
        $this->pruning();

        if (self::prunesSoftDeletable()) {
            // PHPStan re-analyzes this trait per consuming class and
            // cannot see that a soft-deletable Prunable necessarily uses
            // SoftDeletes, whose forceDelete() this calls.
            //
            // @phpstan-ignore method.notFound (guarded by prunesSoftDeletable())
            $this->forceDelete();

            return;
        }

        $this->delete();
    }

    /**
     * Prune every row matching {@see Prunable::prunable()} in chunks.
     *
     * Walks the candidates with a keyset cursor (`pk > last` ascending,
     * one chunk of chunkSize hydrated models in memory), pruning each row
     * instance-by-instance — the full-semantics path. Returns the number
     * of rows pruned. Non-transactional by design: a mid-run failure
     * leaves earlier rows pruned (they matched the predicate; pruning
     * them again is impossible), and a restarted run converges.
     *
     * A failure while pruning one row — a veto, a stale row, a driver
     * error — aborts the run and throws {@see PruneException} naming the
     * failing row's model class and primary-key value, with the
     * underlying failure chained.
     *
     * @param  int  $chunkSize  Candidates per batch.
     * @return int The total number of rows pruned.
     *
     * @throws \InvalidArgumentException
     * @throws \LogicException
     * @throws PruneException
     */
    public static function pruneAll(int $chunkSize = 1000): int
    {
        $base = self::pruneGuards($chunkSize, checkOrdering: true);

        $keyColumn = self::prunedKeyColumn();
        $prunesSoftDeletable = self::prunesSoftDeletable();

        // A soft-deletable model prunes through forceDelete(), so the
        // candidate read must SEE trashed rows — the trait's auto-scope
        // would otherwise exclude them from pruning forever.
        if ($prunesSoftDeletable) {
            $base = $base->withTrashed();
        }

        // The final order is ALWAYS the PK ascending: the keyset cursor
        // needs a stable traversal key. Caller-declared orders stay ahead
        // of it (they only shape visitation order, never WHICH rows
        // prune — every match is visited eventually).
        $base = $base->orderBy($keyColumn, SortDirection::Asc);

        $pruned = 0;
        $lastKey = null; // null = no cursor yet (first pass is un-cursored)

        do {
            // Clone per iteration: limit() returns a clone, and stacking
            // chunked limits onto one builder would compound them.
            $batch = (clone $base);

            // The cursor clause is CONDITIONAL — `pk > NULL` matches
            // nothing in every dialect, so the FIRST pass must select from
            // the un-cursored base.
            if ($lastKey !== null) {
                $batch = $batch->where($keyColumn, '>', $lastKey);
            }

            $models = $batch->limit($chunkSize)->get();

            foreach ($models as $model) {
                $modelKey = $model->attribute($keyColumn);
                \assert(is_int($modelKey) || is_string($modelKey));

                try {
                    $model->prune();
                } catch (\Throwable $failure) {
                    throw new PruneException(static::class, $modelKey, $failure, $pruned);
                }

                $pruned++;
                $lastKey = $modelKey;
            }
        } while (count($models) === $chunkSize);

        return $pruned;
    }
}
