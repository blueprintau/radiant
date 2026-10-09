<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Exceptions;

/**
 * Thrown when an instance write matched no rows because the row was
 * already removed by another connection.
 */
final class StaleRowException extends \RuntimeException
{
    /**
     * The class-string of the model whose row was already gone.
     *
     * @var class-string<\BlueprintAU\Radiant\Model>
     */
    public readonly string $model;

    /**
     * The operation that matched no rows (`delete`, `restore`).
     *
     * @var string
     */
    public readonly string $operation;

    /**
     * @param  class-string<\BlueprintAU\Radiant\Model>  $modelClass
     * @param  string  $operation
     */
    public function __construct(string $modelClass, string $operation)
    {
        parent::__construct(
            "The {$operation} on model [{$modelClass}] matched no rows — the row was "
            . 'already removed by another connection (a stale instance). Re-fetch the '
            . 'model before writing again.'
        );

        $this->model = $modelClass;
        $this->operation = $operation;
    }
}
