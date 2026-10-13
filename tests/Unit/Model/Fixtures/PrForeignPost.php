<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Model\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\ModelQueryBuilder;
use BlueprintAU\Radiant\Prunable;

/**
 * Fixture: a Prunable model whose prunable() returns ANOTHER model's
 * builder — the builder-identity guard must reject it (the keyset would
 * walk the wrong table with this model's key column).
 */
class PrForeignPost extends Model
{
    use Prunable;

    /**
     * The post's id.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * The post title.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 255)]
    public string $title;

    /**
     * A prunable() returning PrPost's builder — the identity guard's
     * failure shape. PHPStan flags the return-type mismatch; the RUNTIME
     * guard is the subject, so the mismatch is deliberate.
     *
     * @return ModelQueryBuilder<static>
     */
    public static function prunable(): ModelQueryBuilder
    {
        /** @phpstan-ignore return.type (deliberate contract violation — the runtime guard is the test subject) */
        return PrPost::newQuery();
    }
}
