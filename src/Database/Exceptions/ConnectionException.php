<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Exceptions;

/**
 * Thrown when a connection to the database cannot be established.
 *
 * Carries the underlying \PDOException as the previous exception so the
 * host framework can log the full driver-level context.

 * The package reports; the host logs.
 */
class ConnectionException extends \RuntimeException
{
    /**
     * @param string $message The exception message.
     * @param int $code The exception code.
     * @param \Throwable|null $previous The underlying driver exception, if any.
     */
    public function __construct(
        string $message,
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}