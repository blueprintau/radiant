<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Support;

use BlueprintAU\Radiant\Model;
use PHPUnit\Framework\Assert;

/**
 * Shared save → re-fetch round-trip machinery for the cast matrix.
 *
 * Compares the saved and re-fetched models COLUMN BY COLUMN in the
 * decoded space via `attribute()` — whole-model equality cannot hold,
 * because hydration re-bases a DateTime property onto Carbon,
 * uninitialized nullable properties differ, and the internal
 * snapshot/exists state is hydration bookkeeping, not cast behavior.
 * PHPUnit's assertEquals compares DateTimeInterface values by instant
 * (a saved DateTime equals its re-hydrated Carbon re-base), arrays
 * key-order-insensitively and backed-enum cases by identity — which is
 * exactly the cast contract: the re-fetched model must hold what was
 * saved.
 *
 * Unit suites run this on :memory: SQLite; the integration suites run
 * it on every live driver — the per-dialect seam the in-memory engine
 * cannot expose.
 */
trait CastRoundTrips
{
    /**
     * Save a model, re-fetch it by primary key, and compare every named
     * column against its pre-save value.
     *
     * @template TModel of Model
     *
     * @param  TModel  $model  The model to save.
     * @param  list<string>  $columns  The column names (DB names) to compare.
     * @return array{0: TModel, 1: TModel} [saved, refetched].
     */
    protected function roundTrip(Model $model, array $columns = []): array
    {
        $model->save();

        /** @var TModel|null $refetched */
        $refetched = $model::class::find($model->getKeyForRefresh());

        Assert::assertNotNull($refetched, 'the saved row must re-fetch by primary key');

        foreach ($columns as $column) {
            Assert::assertEquals(
                $model->attribute($column),
                $refetched->attribute($column),
                "column [{$column}] must round-trip through the cast",
            );
        }

        return [$model, $refetched];
    }
}
