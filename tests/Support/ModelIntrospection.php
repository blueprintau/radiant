<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Support;

/**
 * Reflection-based access to {@see \BlueprintAU\Radiant\Model}'s protected
 * internals for snapshot-space assertions — the established pattern for
 * testing dirty tracking without widening the production API.
 *
 * Unlike a probe subclass, these helpers work on ANY model instance —
 * production models and fixtures alike — with no extra class per model.
 */
final class ModelIntrospection
{
    /**
     * The model's dirty columns (encoded space), read through reflection.
     *
     * @param \BlueprintAU\Radiant\Model $model The model to inspect.
     * @return array<string, mixed> column => encoded value
     */
    public static function dirtyOf(\BlueprintAU\Radiant\Model $model): array
    {
        $getDirty = new \ReflectionMethod(\BlueprintAU\Radiant\Model::class, 'getDirty');

        return $getDirty->invoke($model);
    }

    /**
     * Whether the model exists (persisted), read through reflection.
     *
     * @param \BlueprintAU\Radiant\Model $model The model to inspect.
     * @return bool True after a successful save().
     */
    public static function existsOf(\BlueprintAU\Radiant\Model $model): bool
    {
        $property = new \ReflectionProperty(\BlueprintAU\Radiant\Model::class, 'exists');

        return $property->getValue($model);
    }

    /**
     * Whether a column property is initialized on the model.
     *
     * Reads through reflection so an uninitialized typed property can be
     * detected without triggering the "not initialized" error. The
     * declaring class is resolved from the instance, so inherited
     * properties are found without naming their class.
     *
     * @param \BlueprintAU\Radiant\Model $model The model to inspect.
     * @param string $name The property name.
     * @return bool True when the property has been initialized.
     */
    public static function propertyInitialized(
        \BlueprintAU\Radiant\Model $model,
        string $name,
    ): bool {
        $property = new \ReflectionObject($model)->getProperty($name);

        return $property->isInitialized($model);
    }
}
