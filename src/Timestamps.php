<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant;

use BlueprintAU\Radiant\Attributes\Hook;
use BlueprintAU\Radiant\Attributes\RowHook;
use BlueprintAU\Radiant\Attributes\WriteHook;
use BlueprintAU\Radiant\Metadata\MetadataFactory;

/**
 * Opt-in auto-stamping of `created_at` / `updated_at` for a model.
 *
 * Undeclared stamp columns are auto-declared at metadata build — shaped
 * by the `timestamps()` blueprint helper (NOT NULL datetime), so schema
 * sync creates them. A user-declared `#[Column]` of the same name wins;
 * when a resolved column is absent the trait is a silent no-op for that
 * column, so the trait can sit on a shared base model safely.
 *
 * @mixin \BlueprintAU\Radiant\Model
 * @method static \Carbon\Carbon freshTimestamp() A fresh timestamp for the stamp columns.
 * @phpstan-require-extends \BlueprintAU\Radiant\Model
 */
trait Timestamps
{
    /**
     * The created-at column name.
     *
     * Return `null` (the default) to use `created_at`. Override to rename —
     * the returned name must match a declared `#[Column]` on the model.
     *
     * @return string|null
     */
    public static function createdAtColumn(): ?string
    {
        return null;
    }

    /**
     * The updated-at column name.
     *
     * Return `null` (the default) to use `updated_at`. Override to rename —
     * the returned name must match a declared `#[Column]` on the model.
     *
     * @return string|null
     */
    public static function updatedAtColumn(): ?string
    {
        return null;
    }

    /**
     * The resolved created-at column name — the override when non-null,
     * the `created_at` default otherwise.
     *
     * @return string
     */
    private static function createdAtColumnName(): string
    {
        /** @phpstan-ignore nullCoalesce.expr (the trait is re-analyzed per using class — overrides narrowing createdAtColumn() to non-nullable string make the left side look never-null there) */
        return self::createdAtColumn() ?? 'created_at';
    }

    /**
     * The resolved updated-at column name — the override when non-null,
     * the `updated_at` default otherwise.
     *
     * @return string
     */
    private static function updatedAtColumnName(): string
    {
        /** @phpstan-ignore nullCoalesce.expr (the trait is re-analyzed per using class — overrides narrowing updatedAtColumn() to non-nullable string make the left side look never-null there) */
        return self::updatedAtColumn() ?? 'updated_at';
    }

    /**
     * Stamp the timestamp columns before an INSERT — both columns, each
     * only when unset (a caller-set value always wins).
     *
     * @return null
     */
    #[WriteHook(Hook::Insert)]
    protected function stampOnInsert(): null
    {
        $metadata = MetadataFactory::for(static::class);

        $createdAt = self::createdAtColumnName();
        $updatedAt = self::updatedAtColumnName();

        // One clock read per save — both stamps carry the same instant.
        $now = $this->freshTimestamp();

        if ($metadata->hasColumn($createdAt) && !$this->isTimestampColumnSet($createdAt)) {
            $this->writeTimestampColumn($createdAt, $now);
        }

        if ($metadata->hasColumn($updatedAt) && !$this->isTimestampColumnSet($updatedAt)) {
            $this->writeTimestampColumn($updatedAt, $now);
        }

        return null;
    }

    /**
     * Bump `updated_at` before an UPDATE — always, even when the hydrated
     * property already holds a value: the stamp is the point of the
     * update. Runs before dirty computation so `getDirty()` sees it.
     *
     * @return null
     */
    #[WriteHook(Hook::Update)]
    protected function stampOnUpdate(): null
    {
        $metadata = MetadataFactory::for(static::class);
        $updatedAt = self::updatedAtColumnName();

        if ($metadata->hasColumn($updatedAt)) {
            $this->writeTimestampColumn($updatedAt, $this->freshTimestamp());
        }

        return null;
    }

    /**
     * Stamp the bulk-insert rows — both columns per row, each only when
     * the row does not carry it (a caller-set value always wins).
     *
     * @param  list<array<string, mixed>>  $rows
     */
    #[RowHook(Hook::Insert)]
    protected static function stampInsertRows(array &$rows): void
    {
        $metadata = MetadataFactory::for(static::class);

        $createdAt = self::createdAtColumnName();
        $updatedAt = self::updatedAtColumnName();

        $stampCreated = $metadata->hasColumn($createdAt);
        $stampUpdated = $metadata->hasColumn($updatedAt);

        if (!$stampCreated && !$stampUpdated) {
            return;
        }

        // One clock read per batch — only when a row actually needs it.
        $now = null;

        foreach ($rows as &$row) {
            if ($stampCreated && !array_key_exists($createdAt, $row)) {
                $now ??= static::freshTimestamp();
                $row[$createdAt] = $now;
            }

            if ($stampUpdated && !array_key_exists($updatedAt, $row)) {
                $now ??= static::freshTimestamp();
                $row[$updatedAt] = $now;
            }
        }
    }

    /**
     * Stamp the bulk-update values — `updated_at` only when the payload
     * does not carry it (a caller-set value always wins).
     *
     * @param  array<string, mixed>  $values
     */
    #[RowHook(Hook::Update)]
    protected static function stampUpdateValues(array &$values): void
    {
        $metadata = MetadataFactory::for(static::class);
        $updatedAt = self::updatedAtColumnName();

        if ($metadata->hasColumn($updatedAt) && !array_key_exists($updatedAt, $values)) {
            $values[$updatedAt] = static::freshTimestamp();
        }
    }

    /**
     * Whether the caller has already set a stamp column.
     *
     * @param  string  $columnName
     * @return bool
     */
    private function isTimestampColumnSet(string $columnName): bool
    {
        $mapping = MetadataFactory::for(static::class)->mappingFor($columnName);

        $property = $mapping->property;

        if ($property === null) {
            return array_key_exists($columnName, $this->syntheticValues)
                || array_key_exists($columnName, $this->original);
        }

        return $property->isInitialized($this);
    }

    /**
     * Write a stamp column — through the typed property when the column is
     * user-declared, through the synthetic store otherwise.
     *
     * @param  string  $columnName
     * @param  \Carbon\Carbon  $value
     * @return void
     */
    private function writeTimestampColumn(string $columnName, \Carbon\Carbon $value): void
    {
        $mapping = MetadataFactory::for(static::class)->mappingFor($columnName);

        $property = $mapping->property;
        if ($property === null) {
            // Synthetic column — the runtime store is its only writable slot.
            $this->setAttribute($columnName, $value);
            return;
        }

        $propertyType = $mapping->propertyType;
        if (
            is_string($propertyType)
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
