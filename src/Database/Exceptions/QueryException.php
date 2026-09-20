<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Exceptions;

/**
 * Thrown when a SQL statement fails to execute.
 *
 * The message is LOG-SAFE BY CONTRACT: it carries only a generic
 * SQLSTATE-level description of the failure — never the SQL text, and
 * never a bound value. Hosts can log `getMessage()` unfiltered without
 * risking durable PII or credential spill (emails, tokens, passwords are
 * routine query bindings).
 *
 * Debuggability is opt-in, not default:
 *
 * - `$sql` — the failing statement (placeholders intact). Public readonly:
 *   a host's error reporter may include it deliberately.
 * - `getBindings()` — the bound values, behind a method so they never
 *   leak through `getMessage()` rendering, and so a crash dump only
 *   contains them when the caller explicitly asks.
 * - `toContextString()` — the full SQL + bindings rendering, for
 *   developer-facing output the host explicitly assembles.
 *
 * The underlying \PDOException is preserved as the previous exception.
 *
 * The package reports; the host logs — but what the package emits by
 * default is safe to log.
 */
final class QueryException extends \RuntimeException
{
    /**
     * @param string $sql The SQL that failed.
     * @param array<string|int, mixed> $bindings The bindings bound to the SQL.
     * @param \Throwable|null $previous The underlying driver exception, if any.
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
     * Opt-in access: values may contain request-derived personal data, so
     * they are deliberately NOT part of the message. Log only after
     * redaction or when the log sink is trusted.
     *
     * @return array<string|int, mixed> The bindings bound to the SQL.
     */
    public function getBindings(): array
    {
        return $this->bindings;
    }

    /**
     * A full debug rendering — SQL text plus the raw bound values.
     *
     * Never include in logs by default; assemble developer-facing output
     * with it explicitly.
     *
     * @return string The SQL and its bindings.
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
