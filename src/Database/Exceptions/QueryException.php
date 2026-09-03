<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Exceptions;

/**
 * Thrown when a SQL statement fails to execute.
 *
 * Carries the full context — the SQL and its bindings — so the host
 * framework can log exactly what failed. The underlying \PDOException
 * is preserved as the previous exception.

 * The package reports; the host logs.
 */
class QueryException extends \RuntimeException
{
    /**
     * @param string $sql The SQL that failed.
     * @param array<string|int, mixed> $bindings The bindings bound to the SQL.
     * @param \Throwable|null $previous The underlying driver exception, if any.
     */
    public function __construct(
        public readonly string $sql,
        public readonly array $bindings,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            "SQLSTATE error executing query: {$this->sql}",
            0,
            $previous,
        );
    }
}
