<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant;

use Carbon\Carbon;
use BlueprintAU\Radiant\Metadata\MetadataFactory;

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
     * Return `null` (the default) to use `deleted_at`. Override to rename —
     * the returned name must match a declared `#[Column]` on the model.
     *
     * @return string|null
     */
    public static function deletedAtColumn(): ?string
    {
        return null;
    }

    /**
     * The resolved soft-delete column name — the override when non-null,
     * the `deleted_at` default otherwise.
     *
     * @return string
     */
    private static function softDeleteColumn(): string
    {
        return self::deletedAtColumn() ?? 'deleted_at';
    }

    /**
     * Soft-delete the model — set the delete timestamp.
     *
     * A stale model returns false (the in-memory state is not mutated to
     * look deleted); re-deleting an already-soft-deleted row returns true;
     * an unsaved model returns false.
     *
     * @return bool
     */
    public function delete(): bool
    {
        if (!$this->exists) {
            // An unsaved (or already-deleted) model has no row to
            // soft-delete — report honestly instead of reporting success
            // for a write that never ran.
            return false;
        }

        $stamp = $this->freshTimestamp();

        $affected = $this->newQuery()
            ->withTrashed()
            ->whereKey($this->getKeyForRefresh())
            ->update([self::softDeleteColumn() => $stamp]);

        if ($affected === 0) {
            // The row is gone (stale instance) — report honestly.
            $this->exists = false;
            return false;
        }

        // The snapshot lives in the ENCODED (bindable) space — raw bytes,
        // not a Carbon object. Storing the raw timestamp here would make
        // the next getDirty() compare Carbon against the encoded string,
        // flag deleted_at dirty forever, and have every subsequent save()
        // re-write a column that did not change.
        $encodedStamp = MetadataFactory::for(static::class)
            ->mappingFor(self::softDeleteColumn())
            ->column
            ->encode($stamp, $this->softDeletePropertyType());

        $this->writeDeletedAtColumn($stamp);
        $this->original[self::softDeleteColumn()] = $encodedStamp;

        return true;
    }

    /**
     * Permanently delete the model — the real DELETE.
     *
     * @return bool
     */
    public function forceDelete(): bool
    {
        return $this->performDelete();
    }

    /**
     * Restore a soft-deleted model — clear the delete timestamp.
     *
     * Like {@see delete()}, this reflects the affected-row count: a stale
     * instance returns false; an unsaved model returns false.
     *
     * @return bool
     */
    public function restore(): bool
    {
        if (!$this->exists) {
            // An unsaved (or already-deleted) model has no row to restore.
            return false;
        }

        $affected = $this->newQuery()
            ->withTrashed()
            ->whereKey($this->getKeyForRefresh())
            ->update([self::softDeleteColumn() => null]);

        if ($affected === 0) {
            $this->exists = false;
            return false;
        }

        $this->writeDeletedAtColumn(null);
        $this->original[self::softDeleteColumn()] = null;

        return true;
    }

    /**
     * Whether the model is soft-deleted.
     *
     * @return bool
     */
    public function trashed(): bool
    {
        return ($this->original[self::softDeleteColumn()] ?? $this->attribute(self::softDeleteColumn())) !== null;
    }

    /**
     * A fresh timestamp for the delete column.
     *
     * @return Carbon
     */
    protected function freshTimestamp(): Carbon
    {
        return Carbon::now();
    }

    /**
     * The delete column's declared property type — the second argument to
     * the column's codec when encoding the snapshot stamp.
     *
     * @return string|null
     */
    private function softDeletePropertyType(): ?string
    {
        return MetadataFactory::for(static::class)
            ->mappingFor(self::softDeleteColumn())
            ->propertyType;
    }

    /**
     * Write the delete column's value — through the typed property when the
     * column is user-declared, through the synthetic store otherwise.
     *
     * @param  mixed  $value
     * @return void
     */
    private function writeDeletedAtColumn(mixed $value): void
    {
        $mapping = MetadataFactory::for(static::class)->mappingFor(self::softDeleteColumn());

        $property = $mapping->property;
        if ($property === null) {
            // Synthetic column — the runtime store is its only writable slot.
            $this->setAttribute(self::softDeleteColumn(), $value);
            return;
        }

        $propertyType = $mapping->propertyType;
        if (
            $value instanceof \DateTimeInterface
            && is_string($propertyType)
            && $value::class !== $propertyType
            && is_a($propertyType, \DateTimeInterface::class, true)
            && !$value instanceof $propertyType
        ) {
            $method = new \ReflectionMethod($propertyType, 'createFromInterface');
            $value = $method->invoke(null, $value);
        }

        $property->setValue($this, $value);
    }
}
