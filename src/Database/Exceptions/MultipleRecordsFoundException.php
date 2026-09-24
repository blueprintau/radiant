<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Exceptions;

/**
 * Thrown by `sole()` when a query that must produce EXACTLY ONE model row
 * produces more than one.
 *
 * This is deliberately a different exception from
 * {@see ModelNotFoundException}: "too many results" is not "not found",
 * and callers catching one should not silently swallow the other. The
 * row count is carried so the message can state how many rows matched.
 */
final class MultipleRecordsFoundException extends \RuntimeException
{
    /** @var int The number of rows the query matched (always >= 2). */
    public readonly int $count;

    /**
     * @param  int  $count
     * @param  class-string<\BlueprintAU\Radiant\Model>  $modelClass
     */
    public function __construct(int $count, string $modelClass)
    {
        $this->count = $count;

        parent::__construct('Query returned '.$count.' rows for model ['.$modelClass.'], expected exactly 1.');
    }
}
