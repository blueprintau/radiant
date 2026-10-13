<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Model\Fixtures;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\ModelQueryBuilder;
use BlueprintAU\Radiant\Prunable;

/**
 * Fixture: a Prunable model with a STATIC veto switch — lifecycle
 * registrations are per-instance state, and the prune loop hydrates
 * FRESH instances that carry no registrations, so a test cannot veto a
 * loop prune by registering a listener on a seed instance. The static
 * switch is interposed as a REAL deleting listener inside pruning(),
 * which every hydrated instance runs.
 *
 * An added #[Table] pins the table name (the metadata layer rejects a
 * second table owner only for table-OWNING subclasses — a shared-table
 * rename is safe).
 */
class PrVetoPost extends Model
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
     * The row's creation instant — the expiry column.
     *
     * @var \Carbon\Carbon
     */
    #[Column(type: ColumnType::DateTime, name: 'created_at')]
    public \Carbon\Carbon $createdAt;

    /**
     * The expiry instant the fixture's prunable() compares against.
     *
     * @var \Carbon\Carbon
     */
    public static \Carbon\Carbon $expiry;

    /**
     * Whether deletes are vetoed — the test flips this before pruneAll().
     *
     * @var bool
     */
    public static bool $vetoDeletes = false;

    /**
     * When set, deletes are vetoed ONLY for rows with id >= this value —
     * lets a test land valid prunes BEFORE the failing row.
     *
     * @var int|null
     */
    public static ?int $vetoDeletesFromId = null;

    /**
     * The retention predicate: rows expired before the static cutoff.
     *
     * @return ModelQueryBuilder<static>
     */
    public static function prunable(): ModelQueryBuilder
    {
        return static::newQuery()->where('created_at', '<', self::$expiry);
    }

    /**
     * Interpose the static veto switch as a REAL deleting listener —
     * pruning() runs on every hydrated instance (the loop's included),
     * so the registration lands where the delete will honor it.
     *
     * @return void
     */
    protected function pruning(): void
    {
        $vetoFrom = self::$vetoDeletesFromId;

        if (self::$vetoDeletes || ($vetoFrom !== null && $this->id >= $vetoFrom)) {
            $this->deleting(function (PrVetoPost $model): bool {
                return false;
            });
        }

        // NO parent::pruning() here — trait methods flatten into the
        // class, so parent:: would resolve to Model::pruning() (which
        // does not exist). An override REPLACES the no-op default.
    }
}
