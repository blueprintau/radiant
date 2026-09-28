<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Metadata\Fixtures;

use BlueprintAU\Radiant\Tests\Support\ProbesDirty;

/**
 * A probe exposing the protected dirty-tracking surface of
 * {@see DefaultedModel} for materialization assertions.
 *
 * A behavior-only subclass (rule 1) — same table, same columns, no new
 * declarations — so its metadata resolves to `defaulted_models`.
 */
class DefaultedModelProbe extends DefaultedModel
{
    use ProbesDirty;

    /**
     * Whether the model exists (persisted), exposed for tests.
     *
     * @return bool True after a successful save().
     */
    public function existsExposed(): bool
    {
        return $this->exists;
    }

    /**
     * Whether a column property is initialized, exposed for tests.
     *
     * Reads through reflection so an uninitialized typed property can be
     * detected without triggering the "not initialized" error.
     *
     * @param string $name The property name.
     * @return bool True when the property has been initialized.
     */
    public function propertyIsInitialized(string $name): bool
    {
        $property = new \ReflectionProperty(DefaultedModel::class, $name);

        return $property->isInitialized($this);
    }
}
