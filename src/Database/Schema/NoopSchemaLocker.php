<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Schema;

/**
 * The default {@see SchemaLocker}: takes no lock and documents why.
 *
 * Used when the host has not chosen a locking strategy. Running `diff →
 * apply` without a cross-process lock is safe ONLY for single-instance
 * deployments — two concurrent instances race to destructive DDL. When a
 * host's deployment can overlap, it must supply a real adapter
 * (see {@see \BlueprintAU\Radiant\Database\Schema\Lockers\SqliteSchemaLocker}
 * and siblings) or its own implementation.
 */
final class NoopSchemaLocker implements SchemaLocker
{
    /**
     * Run the callback immediately — no lock is taken.
     *
     * @template TReturn
     *
     * @param callable(): TReturn $callback The schema work.
     * @return TReturn The callback's return value.
     * @throws \Throwable Whatever the callback throws.
     */
    #[\Override]
    public function withLock(callable $callback): mixed
    {
        return $callback();
    }
}
