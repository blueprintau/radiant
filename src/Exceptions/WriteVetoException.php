<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Exceptions;

/**
 * Thrown when a write is vetoed — a lifecycle listener returning `false`,
 * an instance `#[WriteHook]` claimant reporting failure, or a bulk
 * `#[RowHook]` returning `false`.
 */
final class WriteVetoException extends \RuntimeException
{
    /**
     * The class-string of the model whose write was vetoed.
     *
     * @var class-string<\BlueprintAU\Radiant\Model>
     */
    public readonly string $model;

    /**
     * The vetoing source: the lifecycle event name (`saving`, `deleting`,
     * `restoring`) or the hook path a trait claimed (`insert`, `update`,
     * `delete`).
     *
     * @var string
     */
    public readonly string $event;

    /**
     * The trait whose hook vetoed — null when a listener vetoed.
     *
     * @var class-string|null
     */
    public readonly ?string $trait;

    /**
     * The vetoing hook method's name — null when a listener vetoed.
     *
     * @var string|null
     */
    public readonly ?string $method;

    /**
     * Create the lifecycle-veto exception.
     *
     * @param  class-string<\BlueprintAU\Radiant\Model>  $modelClass
     * @param  string  $event  The attempt event that carried the veto.
     * @return self
     */
    public static function listener(string $modelClass, string $event): self
    {
        return new self(
            "The `{$event}` listener vetoed the write on model [{$modelClass}].",
            $modelClass,
            $event,
        );
    }

    /**
     * Create the trait-hook veto exception.
     *
     * @param  class-string<\BlueprintAU\Radiant\Model>  $modelClass
     * @param  string  $event  The hook path that carried the veto.
     * @param  class-string  $trait
     * @param  string  $method
     * @return self
     */
    public static function hook(string $modelClass, string $event, string $trait, string $method): self
    {
        return new self(
            "The #[WriteHook] method [{$trait}::{$method}] vetoed the {$event} "
            . "on model [{$modelClass}].",
            $modelClass,
            $event,
            $trait,
            $method,
        );
    }

    /**
     * Create the bulk RowHook veto exception.
     *
     * @param  class-string<\BlueprintAU\Radiant\Model>  $modelClass
     * @param  string  $event  The hook path that carried the veto.
     * @param  class-string  $trait
     * @param  string  $method
     * @return self
     */
    public static function rowHook(string $modelClass, string $event, string $trait, string $method): self
    {
        return new self(
            "The #[RowHook(Hook::{$event})] method [{$trait}::{$method}] vetoed the write "
            . "on model [{$modelClass}].",
            $modelClass,
            $event,
            $trait,
            $method,
        );
    }

    /**
     * @param  string  $message
     * @param  class-string<\BlueprintAU\Radiant\Model>  $modelClass
     * @param  string  $event
     * @param  class-string|null  $trait
     * @param  string|null  $method
     */
    private function __construct(
        string $message,
        string $modelClass,
        string $event,
        ?string $trait = null,
        ?string $method = null,
    ) {
        parent::__construct($message);

        $this->model = $modelClass;
        $this->event = $event;
        $this->trait = $trait;
        $this->method = $method;
    }
}
