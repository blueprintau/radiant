<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Exceptions;

/**
 * Thrown when {@see \BlueprintAU\Radiant\Prunable::pruneAll()} fails to
 * prune one row inside its batch loop.
 *
 * The failing row's identity rides typed readonly properties — `$model`
 * names the model class and `$key` carries the row's primary-key value —
 * so a failed retention run points at the exact row instead of a message
 * to parse. `$pruned` carries how many rows the run successfully pruned
 * before the failure (a partial sweep's progress). The underlying failure
 * (a vetoed delete, a stale row, a driver error) is chained as
 * `$previous`.
 */
final class PruneException extends \RuntimeException
{
    /**
     * The class-string of the model whose row failed to prune.
     *
     * @var class-string<\BlueprintAU\Radiant\Model>
     */
    public readonly string $model;

    /**
     * The failing row's primary-key value.
     *
     * @var int|string
     */
    public readonly int|string $key;

    /**
     * @param  class-string<\BlueprintAU\Radiant\Model>  $modelClass
     * @param  int|string  $key
     * @param  \Throwable  $previous  The underlying failure.
     * @param  int  $pruned  Rows pruned before the failure.
     */
    public function __construct(
        string $modelClass,
        int|string $key,
        \Throwable $previous,
        public readonly int $pruned,
    ) {
        parent::__construct(
            "Pruning row [{$key}] of model [{$modelClass}] failed: "
                . $previous->getMessage(),
            0,
            $previous,
        );

        $this->model = $modelClass;
        $this->key = $key;
    }
}
