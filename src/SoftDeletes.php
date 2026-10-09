<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant;

use BlueprintAU\Radiant\Attributes\Hook;
use BlueprintAU\Radiant\Attributes\ModelScope;
use BlueprintAU\Radiant\Attributes\WriteHook;
use BlueprintAU\Radiant\Database\Query\Enums\WhereOperator;
use BlueprintAU\Radiant\Exceptions\StaleRowException;
use BlueprintAU\Radiant\Exceptions\WriteVetoException;
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
 * @mixin \BlueprintAU\Radiant\Model
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
     * Claim the delete() path and perform the soft delete.
     *
     * The `?bool` return is the delete's outcome: `true` = soft-deleted,
     * `null` = never claims failure. An unsaved model throws
     * {@see \LogicException}; 0 affected rows throws a
     * {@see StaleRowException}.
     *
     * @return bool|null
     * @throws \LogicException
     * @throws StaleRowException
     */
    #[WriteHook(Hook::Delete)]
    protected function softDelete(): ?bool
    {
        if (!$this->exists) {
            // An unsaved model has no row to soft-delete — a caller
            // logic error, not a veto. Fail fast the same way an UPDATE
            // with an unresolved key would.
            throw new \LogicException(
                'The model [' . static::class . '] was never saved — there is no row to delete.'
            );
        }

        $stamp = $this->freshTimestamp();

        $affected = $this->newQuery()
            ->withTrashed()
            ->whereKey($this->getKeyForRefresh())
            ->update([self::softDeleteColumn() => $stamp]);

        if ($affected === 0) {
            // The row is gone (stale instance) — the delete cannot
            // silently no-op.
            throw new StaleRowException(static::class, 'delete');
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
     * A `deleting` listener returning false vetoes via a thrown
     * {@see WriteVetoException}; 0 affected rows throws a
     * {@see StaleRowException}. The `#[WriteHook(Hook::Destroy)]`
     * observers run before the DELETE; the hard DELETE itself is
     * unclaimable.
     *
     * @return void
     * @throws \LogicException
     * @throws WriteVetoException
     * @throws StaleRowException
     */
    public function forceDelete(): void
    {
        if (!$this->fireLifecycle('deleting')) {
            throw WriteVetoException::listener(static::class, 'deleting');
        }

        $this->performDelete();

        $this->fireLifecycle('deleted');
    }

    /**
     * Restore a soft-deleted model — clear the delete timestamp.
     *
     * A `restoring` listener returning false vetoes via a thrown
     * {@see WriteVetoException}; 0 affected rows throws a
     * {@see StaleRowException}.
     *
     * @return void
     * @throws \LogicException
     * @throws WriteVetoException
     * @throws StaleRowException
     */
    public function restore(): void
    {
        if (!$this->exists) {
            // An unsaved model has no row to restore.
            throw new \LogicException(
                'The model [' . static::class . '] was never saved — there is no row to restore.'
            );
        }

        if (!$this->fireLifecycle('restoring')) {
            throw WriteVetoException::listener(static::class, 'restoring');
        }

        $affected = $this->newQuery()
            ->withTrashed()
            ->whereKey($this->getKeyForRefresh())
            ->update([self::softDeleteColumn() => null]);

        if ($affected === 0) {
            // The row is gone (stale instance) — the restore cannot
            // silently no-op.
            throw new StaleRowException(static::class, 'restore');
        }

        $this->writeDeletedAtColumn(null);
        $this->original[self::softDeleteColumn()] = null;

        $this->fireLifecycle('restored');
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
