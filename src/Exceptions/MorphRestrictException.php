<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Exceptions;

/**
 * Thrown when a parent delete is refused because a `#[MorphDelete(action: Restrict)]`
 * declaration still has children referencing it.
 *
 * The morph-pair analogue of a SQL `RESTRICT` foreign key: the parent row
 * is untouched — the check runs BEFORE the delete — and the referencing
 * children ride typed readonly properties so the blocker is actionable
 * without parsing the message.
 */
final class MorphRestrictException extends \RuntimeException
{
    /**
     * The class-string of the parent model whose delete was refused.
     *
     * @var class-string<\BlueprintAU\Radiant\Model>
     */
    public readonly string $model;

    /**
     * The parent row's primary-key value.
     *
     * @var int|string
     */
    public readonly int|string $key;

    /**
     * The class-string of the child model whose rows block the delete.
     *
     * @var class-string<\BlueprintAU\Radiant\Model>
     */
    public readonly string $related;

    /**
     * The morph name the pair shares (`{name}_type`/`{name}_id`).
     *
     * @var string
     */
    public readonly string $morphName;

    /**
     * How many child rows reference the parent.
     *
     * @var int
     */
    public readonly int $count;

    /**
     * @param  class-string<\BlueprintAU\Radiant\Model>  $modelClass
     * @param  int|string  $key
     * @param  class-string<\BlueprintAU\Radiant\Model>  $related
     * @param  string  $morphName
     * @param  int  $count
     */
    public function __construct(
        string $modelClass,
        int|string $key,
        string $related,
        string $morphName,
        int $count,
    ) {
        parent::__construct(
            "Deleting [{$key}] of model [{$modelClass}] is restricted — {$count} row(s) of "
            . "[{$related}] still reference it through the [{$morphName}] morph pair. "
            . 'Detach or delete the children first.'
        );

        $this->model = $modelClass;
        $this->key = $key;
        $this->related = $related;
        $this->morphName = $morphName;
        $this->count = $count;
    }
}
