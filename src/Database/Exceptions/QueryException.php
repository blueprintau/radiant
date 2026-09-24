<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Exceptions;

/**
 * Thrown when a SQL statement fails to execute.
 *
 * The message is log-safe by contract: it carries only a generic
 * SQLSTATE-level description of the failure — never the SQL text, and never
 * a bound value. Debuggability is opt-in via the public `$sql` property,
 * {@see getBindings()}, and {@see toContextString()}. The underlying
 * \PDOException is preserved as the previous exception.
 */
final class QueryException extends \RuntimeException
{
    /**
     * @param  string  $sql
     * @param  array<string|int, mixed>  $bindings
     * @param  \Throwable|null  $previous
     */
    public function __construct(
        public readonly string $sql,
        protected readonly array $bindings,
        ?\Throwable $previous = null,
    ) {
        parent::__construct('SQL error executing query: the driver rejected the statement.', 0, $previous);
    }

    /**
     * The bound values for the failed statement.
     *
     * Values may contain request-derived personal data — log only after
     * redaction or when the log sink is trusted.
     *
     * @return array<string|int, mixed>
     */
    public function getBindings(): array
    {
        return $this->bindings;
    }

    /**
     * A full debug rendering — SQL text plus the raw bound values.
     *
     * @return string
     */
    public function toContextString(): string
    {
        $rendered = [];
        foreach ($this->bindings as $key => $value) {
            $rendered[] = is_scalar($value) || $value === null
                ? var_export($value, true)
                : '[' . get_debug_type($value) . ']';
        }
        return $this->sql . "\nBindings: " . implode(', ', $rendered);
    }
}
