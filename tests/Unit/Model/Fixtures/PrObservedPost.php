<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Model\Fixtures;

use BlueprintAU\Radiant\ModelQueryBuilder;
use BlueprintAU\Radiant\Prunable;

/**
 * Fixture: a Prunable observer subclass — pruning() records each
 * candidate title it sees, proving the per-row hook fires on the
 * instance path. Shares PrPost's table (behavior-only subclass — no
 * columns, no #[Table]): its builder reads the SAME rows, and prune()
 * hydrates through this class so the override fires.
 */
class PrObservedPost extends PrPost
{
    /**
     * Titles seen by pruning(), in visitation order.
     *
     * @var list<string>
     */
    public static array $seenTitles = [];

    /**
     * Reset the observer log.
     *
     * @return void
     */
    public static function resetSeenTitles(): void
    {
        self::$seenTitles = [];
    }

    /**
     * Record the candidate. No parent::pruning() — trait methods flatten
     * into the class, so parent:: would resolve to Model::pruning()
     * (which does not exist); the override REPLACES the no-op default.
     *
     * @return void
     */
    protected function pruning(): void
    {
        self::$seenTitles[] = $this->title;
    }

    /**
     * Re-point the predicate at THIS class (an inherited prunable()
     * would bind `static::` to PrPost through its own body's static).
     *
     * @return ModelQueryBuilder<static>
     */
    public static function prunable(): ModelQueryBuilder
    {
        return static::newQuery()->where('created_at', '<', PrPost::$expiry);
    }
}
