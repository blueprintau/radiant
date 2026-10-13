<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Concerns;

use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\ModelQueryBuilder;
use BlueprintAU\Radiant\Metadata\MetadataFactory;

/**
 * The shared pruning internals — ONE definition of the guard set and the
 * batch scaffolding both {@see \BlueprintAU\Radiant\Prunable} and
 * {@see \BlueprintAU\Radiant\MassPrunable} compose.
 *
 * NOT a user-facing trait: models apply Prunable or MassPrunable, never
 * this trait directly. Its helpers flatten onto the consuming model as
 * protected members (the same way {@see \BlueprintAU\Radiant\SoftDeletes}'s
 * private helpers do), which is harmless — they are implementation noise
 * on the protected surface only.
 *
 * The guards run at pruneAll() entry and fail fast on every shape that
 * would corrupt a prune silently: a chunked loop with no chunk, a keyset
 * walk with no single key, a prunable() that returned another model's
 * builder (wrong table, wrong key), a prunable() whose limit the loop's
 * chunkSize would silently override, and a prunable() that orders the
 * primary key DESC (the Prunable cursor would match nothing after the
 * first batch).
 *
 * @mixin \BlueprintAU\Radiant\Model
 *
 * @template TModel of Model
 *
 * @phpstan-require-extends \BlueprintAU\Radiant\Model
 */
trait PrunesModels
{
    /**
     * Assert the pruning contract holds before any batch runs.
     *
     * Every check throws before a statement runs.
     *
     * @param  int  $chunkSize  Candidates per batch.
     * @param  bool  $checkOrdering  Whether the PK-ordering guard runs (Prunable's cursor needs it).
     * @return ModelQueryBuilder<static> The validated prunable() builder.
     *
     * @throws \InvalidArgumentException
     * @throws \LogicException
     */
    protected static function pruneGuards(int $chunkSize, bool $checkOrdering): ModelQueryBuilder
    {
        if ($chunkSize < 1) {
            throw new \InvalidArgumentException(
                'Chunk size must be at least 1; got ' . $chunkSize . '.'
            );
        }

        $builder = static::prunable();

        // A composite PK cannot key the traversal: the keyset cursor
        // compares one scalar, and the mass path deletes by one IN list.
        // (Tuple IN lists are avoided by design across the codebase.)
        $primaryKeys = MetadataFactory::for(static::class)->primaryKeys;

        if (count($primaryKeys) !== 1 || $primaryKeys[0]->name === null) {
            throw new \LogicException(
                'Pruning requires a single named primary key; model [' . static::class . '] has '
                . (count($primaryKeys) === 0 ? 'none' : 'a composite') . '.'
            );
        }

        // A copy-pasted prunable() that returns ANOTHER model's builder
        // would keyset the wrong table with this model's key column and
        // delete mismatched ids. $modelClass is a public readonly ctor
        // property, so the identity check is free.
        if ($builder->modelClass !== static::class) {
            throw new \LogicException(
                'prunable() must return a builder for [' . static::class . ']; it returned one for ['
                    . $builder->modelClass . '].'
            );
        }

        // The loop applies limit(chunkSize) itself. A caller-set limit
        // would be silently REPLACED — "prune the oldest 100" would prune
        // everything matching — so reject it instead of guessing.
        if ($builder->getLimit() !== null) {
            throw new \LogicException(
                'prunable() must not set a limit — pruneAll() bounds every batch itself '
                    . '(chunkSize ' . $chunkSize . '). Pass your row cap through the WHERE predicate instead.'
            );
        }

        if ($checkOrdering) {
            self::assertNoDescendingPrimaryKey($builder);
        }

        return $builder;
    }

    /**
     * Reject a prunable() that orders the primary key descending.
     *
     * Prunable's keyset cursor walks `pk > last` ascending: with a DESC
     * pk order the cursor matches nothing after the first batch, and the
     * run would silently prune only its first chunk. Detection is
     * best-effort on string columns (table qualifiers stripped); an
     * `Expression` or raw order escapes this walk — that escape hatch is
     * documented, and raw-order callers have left the typed contract.
     *
     * @param  ModelQueryBuilder<Model>  $builder
     * @return void
     *
     * @throws \LogicException
     */
    private static function assertNoDescendingPrimaryKey(ModelQueryBuilder $builder): void
    {
        $pkName = MetadataFactory::for(static::class)->primaryKeys[0]->name;
        \assert($pkName !== null);

        $orders = $builder->getOrders();

        foreach ($orders as $order) {
            $column = $order['column'];

            if (!$column instanceof \BlueprintAU\Radiant\Database\Query\Expression) {
                // Strip an own-table qualifier — `posts.id` and `id` name
                // the same column to a non-join builder.
                $bare = $column;
                $prefix = $builder->table . '.';

                if (str_starts_with($bare, $prefix)) {
                    $bare = substr($bare, strlen($prefix));
                }

                $descending = $order['direction'] === \BlueprintAU\Radiant\Database\Query\Enums\SortDirection::Desc;

                if ($bare === $pkName && $descending) {
                    throw new \LogicException(
                        'prunable() must not order the primary key descending — the keyset cursor '
                            . 'walks ascending and a DESC order matches nothing after the first batch. '
                            . 'Drop the DESC order (pruning visits every matching row regardless of order).'
                    );
                }
            }
        }
    }

    /**
     * The single named primary-key column of the pruning model.
     *
     * @return string
     */
    private static function prunedKeyColumn(): string
    {
        $pkName = MetadataFactory::for(static::class)->primaryKeys[0]->name;
        \assert($pkName !== null);

        return $pkName;
    }

    /**
     * Whether the pruning model soft-deletes — the probe both paths use
     * to decide trashed-row handling.
     *
     * @return bool
     */
    private static function prunesSoftDeletable(): bool
    {
        return MetadataFactory::for(static::class)->softDeleteColumn !== null;
    }
}
