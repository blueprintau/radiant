<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Support;

use BlueprintAU\Collections\Collection;
use BlueprintAU\Radiant\Database\Query\QueryBuilder;

/**
 * A connection stub whose select() returns ARRAY rows — the malformed
 * row shape the model layer's hydration guards defend against.
 */
final class ArrayRowConnection extends NullConnection
{
    /**
     * The canned rows to return from every select.
     *
     * @var list<array<string, mixed>>|list<object>
     */
    public static array $rows = [];

    /**
     * Run the query and return the canned rows AS ARRAYS — the
     * non-stdClass shape the guards defend against.
     *
     * @param QueryBuilder $query The query to run.
     * @return Collection<int, mixed> The rows.
     */
    #[\Override]
    public function select(QueryBuilder $query): Collection
    {
        return Collection::make(self::$rows);
    }
}
