<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant;

use Carbon\Carbon;

/**
 * Opt-in soft-delete behaviour for a model.
 *
 * A user-facing trait — models apply it themselves
 * (`class User extends Model { use SoftDeletes; }`). `delete()` becomes an
 * UPDATE setting the delete timestamp; `forceDelete()` performs the real
 * DELETE. The backing column is guaranteed by the {@see \BlueprintAU\Radiant\Metadata\MetadataFactory},
 * which auto-declares it from {@see SoftDeletes::deletedAtColumn()} when
 * the class uses the trait (a user-declared column of that name wins).
 *
 * Soft deletes are portable: the trait only uses `update()` and `whereKey()`
 * — both in the portable subset — so it works on any
 * `ConnectionInterface` (CSV included).
 */
trait SoftDeletes
{
    /**
     * The column holding the soft-delete timestamp.
     *
     * Override to rename (e.g. `'removed_at'`) — the {@see \BlueprintAU\Radiant\Metadata\MetadataFactory}
     * calls this, so a renamed column gets the right metadata and no unused
     * `deleted_at` phantom is created.
     *
     * @return string The soft-delete column name.
     */
    public static function deletedAtColumn(): string
    {
        return 'deleted_at';
    }

    /**
     * Soft-delete the model — set the delete timestamp.
     *
     * Returns true when at least one row was updated. A stale model (the
     * row was deleted — soft- or hard — by another connection while this
     * instance was alive) matches 0 rows: the in-memory state is NOT
     * mutated to look deleted, `$this->exists` is cleared, and false is
     * returned — the caller's compensation logic (cascade cleanup, queue
     * bookkeeping) must not fire for a row that is not there.
     *
     * Re-deleting an already-soft-deleted row DOES return true: the
     * UPDATE matches the row (soft-deleted rows are invisible to the
     * scoped builder but whereKey + update targets the row directly) and
     * refreshing the timestamp is a legitimate, successful soft delete.
     *
     * @return bool True when the row was soft-deleted; false when the row
     *         no longer exists.
     */
    public function delete(): bool
    {
        if ($this->exists) {
            $affected = $this->newQuery()
                ->whereKey($this->getKeyForRefresh())
                ->update([static::deletedAtColumn() => $this->freshTimestamp()]);

            if ($affected === 0) {
                // The row is gone (stale instance) — report honestly.
                $this->exists = false;
                return false;
            }

            $this->setAttribute(static::deletedAtColumn(), $this->freshTimestamp());
            $this->original[static::deletedAtColumn()] = $this->freshTimestamp();
        }

        return true;
    }

    /**
     * Permanently delete the model — the real DELETE.
     *
     * @return bool Always true.
     */
    public function forceDelete(): bool
    {
        return $this->performDelete();
    }

    /**
     * Restore a soft-deleted model — clear the delete timestamp.
     *
     * The scope-free `withTrashed()` builder is required: the auto-applied
     * `whereNull` scope would exclude the very rows restore() targets
     * (they have `deleted_at` SET).
     *
     * Like {@see delete()}, this reflects the affected-row count: restoring
     * a stale instance (row hard-deleted elsewhere) touches 0 rows, clears
     * `$this->exists`, and returns false instead of reporting success.
     *
     * @return bool True when the row was restored; false when the row no
     *         longer exists.
     */
    public function restore(): bool
    {
        if ($this->exists) {
            $affected = $this->newQuery()
                ->withTrashed()
                ->whereKey($this->getKeyForRefresh())
                ->update([static::deletedAtColumn() => null]);

            if ($affected === 0) {
                $this->exists = false;
                return false;
            }

            $this->setAttribute(static::deletedAtColumn(), null);
            $this->original[static::deletedAtColumn()] = null;
        }

        return true;
    }

    /**
     * Whether the model is soft-deleted.
     *
     * Reads the loaded (`original`) value — the in-memory truth at
     * hydration time.
     *
     * @return bool True when the delete timestamp is set.
     */
    public function trashed(): bool
    {
        return ($this->original[static::deletedAtColumn()] ?? $this->attribute(static::deletedAtColumn())) !== null;
    }

    /**
     * A fresh timestamp for the delete column.
     *
     * @return Carbon The current time.
     */
    protected function freshTimestamp(): Carbon
    {
        return Carbon::now();
    }
}
