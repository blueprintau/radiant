<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Exceptions;

/**
 * Thrown when a feature that the active backend cannot support is requested.
 *
 * A connection that silently ignores a feature it can't support produces wrong
 * results — far worse than an error. This exception is the fail-fast
 * contract: never silently ignore a feature you can't support.
 */
class UnsupportedFeatureException extends \RuntimeException {}