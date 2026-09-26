<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant;

use BlueprintAU\Radiant\Metadata\MetadataFactory;

/**
 * Opt-in auto-stamping of `created_at` / `updated_at` for a model.
 *
 * Undeclared stamp columns are auto-declared at metadata build — shaped
 * by the `timestamps()` blueprint helper (NOT NULL datetime), so schema
 * sync creates them. A user-declared `#[Column]` of the same name wins;
 * when a resolved column is absent the trait is a silent no-op for that
 * column, so the trait can sit on a shared base model safely.
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
     * Stamp the timestamp columns onto the model before a write.
     *
     * INSERT fills both columns (each only when unset); UPDATE bumps
     * `updated_at` only. Runs before dirty computation so `getDirty()`
     * sees the stamped values.
     *
     * @param  bool  $insert  Whether the write is an INSERT (both columns) or an UPDATE (`updated_at` only).
     * @return void
     */
    protected function stampTimestamps(bool $insert): void
    {
        $metadata = MetadataFactory::for(static::class);

        $createdAt = self::createdAtColumnName();
        $updatedAt = self::updatedAtColumnName();

        // One clock read per save — both stamps carry the same instant.
        $now = $this->freshTimestamp();

        if ($insert && $metadata->hasColumn($createdAt) && !$this->isTimestampColumnSet($createdAt)) {
            $this->writeTimestampColumn($createdAt, $now);
        }

        // INSERT: fill updated_at only when the caller has not set it.
        // UPDATE: always bump — the stamp is the point of the update, even
        // when the hydrated property already holds a value.
        if ($metadata->hasColumn($updatedAt) && ($insert ? !$this->isTimestampColumnSet($updatedAt) : true)) {
            $this->writeTimestampColumn($updatedAt, $now);
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
