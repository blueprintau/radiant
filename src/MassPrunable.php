<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant;

use BlueprintAU\Radiant\Concerns\PrunesModels;
use BlueprintAU\Radiant\Database\Query\Enums\SortDirection;
use BlueprintAU\Radiant\Metadata\MetadataFactory;

/**
 * Opt-in bulk pruning — delete expired rows with chunked builder DELETEs,
 * no hydration, no per-row hooks.
 *
 * The throughput twin of {@see Prunable}: the same retention contract
 * ({@see MassPrunable::prunable()} must be implemented — declared
 * abstract) with the bulk-write trade-off the ORM documents everywhere —
 * builder writes bypass per-model behavior. Deletes run as
 * `DELETE ... WHERE pk IN (batch ids)` statements: no instances are
 * hydrated, so `#[WriteHook]` claims, veto listeners, and lifecycle
 * callbacks NEVER fire — audit traits see nothing, a veto listener
 * cannot stop it. Use {@see Prunable} when per-row semantics matter;
 * use this trait for high-volume retention jobs where they do not.
 *
 * Not supported on MTI children — a bulk DELETE would remove only the
 * leaf table's row and orphan the ancestor partitions (per-instance
 * deletes walk the chain leaf-first). {@see MassPrunable::pruneAll()}
 * fails fast naming {@see Prunable} as the alternative. Composite-PK
 * models fail fast for the same single-key reason as Prunable.
 *
 * @mixin \BlueprintAU\Radiant\Model
 * @phpstan-require-extends \BlueprintAU\Radiant\Model
 */
trait MassPrunable
{
    /** @use PrunesModels<static> */
    use PrunesModels;

    /**
     * The prunable query — the retention predicate.
     *
     * Every row matching this query is a prune candidate. Must return a
     * builder for the calling model and must not set a limit — the
     * guards enforce both. Ordering inside prunable() is allowed; the
     * loop orders the PK ascending itself for determinism.
     *
     * @return ModelQueryBuilder<static>
     */
    abstract public static function prunable(): ModelQueryBuilder;

    /**
     * Prune every row matching {@see MassPrunable::prunable()} in bulk.
     *
     * Each round reads one chunk of candidate PKs (limit chunkSize,
     * ordered by PK for determinism) and deletes exactly those rows with
     * one `DELETE ... WHERE pk IN (...)` statement. Because every batch
     * deletes what it reads, the next round's read sees only fresh
     * candidates — the loop terminates by draining, no cursor needed.
     *
     * Loop control rides the READ count, not the delete's affected-row
     * count: a concurrently-removed row makes the DELETE match fewer
     * rows, and a `delete()` returning less than the batch size says
     * nothing about whether candidates remain. Rounds stop when a read
     * returns fewer than chunkSize rows.
     *
     * Non-transactional by design: one DELETE statement per chunk is
     * atomic on its own, and a restarted run converges (pruning is
     * idempotent-by-predicate). A soft-deletable model reads candidates
     * through `withTrashed()` so trashed rows are purged too.
     *
     * @param  int  $chunkSize  Rows deleted per statement.
     * @return int The total number of rows pruned.
     *
     * @throws \InvalidArgumentException
     * @throws \LogicException
     */
    public static function pruneAll(int $chunkSize = 1000): int
    {
        $base = self::pruneGuards($chunkSize, checkOrdering: false);

        if (MetadataFactory::for(static::class)->isMtiChild()) {
            throw new \LogicException(
                'MassPrunable cannot prune MTI children — a bulk DELETE would orphan the ancestor '
                    . 'table rows. Use Prunable, whose per-instance deletes walk the table chain leaf-first.'
            );
        }

        $keyColumn = self::prunedKeyColumn();

        if (self::prunesSoftDeletable()) {
            $base = $base->withTrashed();
        }

        // Determinism only — every matching row prunes regardless of
        // order, but a stable order makes runs (and tests) reproducible.
        $base = $base->orderBy($keyColumn, SortDirection::Asc);

        $pruned = 0;

        do {
            // Clone per round: stacking chunked limits onto one builder
            // would compound them.
            $batch = (clone $base)->limit($chunkSize);

            $keys = $batch->pluck($keyColumn)->all();

            if ($keys === []) {
                break;
            }

            $pruned += (clone $base)->whereIn($keyColumn, $keys)->delete();
        } while (count($keys) === $chunkSize);

        return $pruned;
    }
}
