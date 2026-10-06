<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Exceptions;

/**
 * Thrown when a `#[RowHook]` method returns `false` — the hook vetoed a
 * bulk write before any statement ran, so the batch is all-or-nothing.
 * The message names the hooking trait and method.
 */
final class RowHookVetoException extends \RuntimeException
{
}
