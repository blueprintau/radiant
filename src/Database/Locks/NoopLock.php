<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Database\Locks;

/**
 * The default {@see Lock}: takes no lock and documents why.
 *
 * Used when the host has not chosen a locking strategy. Running
 * serialized-sensitive work (e.g. the schema `diff → apply` loop) without a
 * cross-process lock is safe ONLY for single-instance deployments — two
 * concurrent instances race to destructive operations. When a host's
 * deployment can overlap, it must supply a real adapter
 * (see {@see \BlueprintAU\Radiant\Database\Locks\SqliteLock}
 * and siblings) or its own implementation.
 */
final class NoopLock implements Lock
{
    /**
     * Run the callback immediately — no lock is taken. The name is
     * accepted for signature parity with real adapters and ignored.
     *
     * @template TReturn
     *
     * @param callable(): TReturn $callback The work.
     * @param string $name Ignored — nothing is locked.
     * @return TReturn The callback's return value.
     * @throws \Throwable Whatever the callback throws.
     */
    #[\Override]
    public function withLock(callable $callback, string $name): mixed
    {
        return $callback();
    }
}
