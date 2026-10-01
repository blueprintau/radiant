<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant;

use BlueprintAU\Radiant\Attributes\Hook;
use BlueprintAU\Radiant\Attributes\ModelScope;
use BlueprintAU\Radiant\Attributes\WriteHook;
use BlueprintAU\Radiant\Database\Query\Enums\WhereOperator;
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
 *
 * The API boundary is deliberate: this trait owns ROW behavior (the
 * scope, the delete hook, forceDelete/restore/trashed). The QUERY-side
 * vocabulary (withTrashed/onlyTrashed/withoutScope) lives on
 * {@see ModelQueryBuilder} — those methods must return a builder to stay
 * fluent and manipulate the builder's traitScope where-markers, which a
 * model-side trait method cannot do.
 *
 * @phpstan-require-extends \BlueprintAU\Radiant\Model
 */
trait SoftDeletes
{
    /**
     * The trait's read scope: exclude soft-deleted rows from every query.
     *
     * @return list<ScopeCondition>
     */
    #[ModelScope]
    public static function excludeTrashed(): array
    {
        return [new ScopeCondition(self::softDeleteColumn(), WhereOperator::Null)];
    }

    /**
     * The trait's write hook: claim the delete() path and perform the
     * soft delete — an UPDATE setting the delete timestamp.
     *
     * The `?bool` return IS the delete's outcome: `true` = soft-deleted,
     * `false` = nothing to delete (unsaved or stale instance).
     *
     * @return bool|null
     */
    #[WriteHook(Hook::Delete)]
    protected function softDelete(): ?bool
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
        /** @phpstan-ignore nullCoalesce.expr (the trait is re-analyzed per using class — overrides narrowing deletedAtColumn() to non-nullable string make the left side look never-null there) */
        return self::deletedAtColumn() ?? 'deleted_at';
    }

    /**
     * Permanently delete the model — the real DELETE.
     *
     * A `deleting` listener returning false vetoes the delete. The
     * `#[WriteHook(Hook::Destroy)]` observers (audit traits) run before
     * the DELETE; the hard DELETE itself is unclaimable.
     *
     * @return bool
     */
    public function forceDelete(): bool
    {
        if (!$this->fireLifecycle('deleting')) {
            return false;
        }

        $deleted = $this->performDelete();

        if ($deleted) {
            $this->fireLifecycle('deleted');
        }

        return $deleted;
    }

    /**
     * Restore a soft-deleted model — clear the delete timestamp.
     *
     * A `restoring` listener returning false vetoes the restore. Like
     * {@see delete()}, this reflects the affected-row count: a stale
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

        if (!$this->fireLifecycle('restoring')) {
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

        $this->fireLifecycle('restored');

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
