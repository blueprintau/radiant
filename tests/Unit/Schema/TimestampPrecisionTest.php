<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Schema;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Attributes\Table;
use BlueprintAU\Radiant\Database\Schema\Blueprint;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Database\Schema\Grammars\MySqlSchemaGrammar;
use BlueprintAU\Radiant\Database\Schema\Grammars\PostgresSchemaGrammar;
use BlueprintAU\Radiant\Database\Schema\Grammars\SqliteSchemaGrammar;
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\SoftDeletes;
use BlueprintAU\Radiant\Tests\Support\DatabaseTestCase;
use BlueprintAU\Radiant\Timestamps;
use Carbon\Carbon;

/**
 * Timestamp precision + auto-stamping: grammar rendering, differ
 * detection, precision-aware encoding, and the Timestamps trait
 * lifecycle.
 */
final class TimestampPrecisionTest extends DatabaseTestCase
{
    /**
     * Create the fixture tables: a precision(3) stamped model, a plain
     * unstamped model, a stamped model with renamed stamp columns, and a
     * model using both SoftDeletes and Timestamps.
     */
    protected function setUpDatabase(): void
    {
        $this->createTables(
            (new Blueprint('tp_events'))
                ->id()
                ->string('name', 64)
                ->timestamp('started_at', 3)
                ->timestamps(3, nullable: true),
            (new Blueprint('tp_plain'))
                ->id()
                ->string('name', 64)
                ->timestamp('started_at'),
            (new Blueprint('tp_renamed'))
                ->id()
                ->string('name', 64)
                ->timestamp('began_at', 3)
                ->column(ColumnType::DateTime, 'modified_at', nullable: true, precision: 3),
            (new Blueprint('tp_posts'))
                ->id()
                ->string('name', 64)
                ->timestamps(3, nullable: true)
                ->softDeletes(3),
            TpTraitNoColumns::class, // built from metadata — the auto-declared stamps become real columns
        );
    }

    // ---- Grammar rendering ----

    /**
     * MySQL renders fractional-seconds precision on datetime columns.
     */
    public function testMySqlRendersPrecision(): void
    {
        $blueprint = (new Blueprint('t'))
            ->timestamp('started_at', 3)
            ->column(ColumnType::DateTime, 'ended_at', nullable: true, precision: 6);

        $sql = (new MySqlSchemaGrammar())->compileCreate($blueprint);

        self::assertSame(
            'CREATE TABLE `t` (`started_at` datetime(3), `ended_at` datetime(6))',
            $sql,
        );
    }

    /**
     * Postgres renders declared precision on timestamp columns.
     */
    public function testPostgresRendersPrecision(): void
    {
        $blueprint = (new Blueprint('t'))->timestamp('started_at', 3);

        $sql = (new PostgresSchemaGrammar())->compileCreate($blueprint);

        self::assertSame('CREATE TABLE "t" ("started_at" timestamp(3))', $sql);
    }

    /**
     * SQLite renders precision for cross-dialect DDL parity (type affinity
     * ignores it).
     */
    public function testSqliteRendersPrecision(): void
    {
        $blueprint = (new Blueprint('t'))->timestamp('started_at', 3);

        $sql = (new SqliteSchemaGrammar())->compileCreate($blueprint);

        self::assertSame('CREATE TABLE "t" ("started_at" datetime(3))', $sql);
    }

    /**
     * The timestamps() helper declares the created_at/updated_at pair with
     * the given precision — NOT NULL by default.
     */
    public function testTimestampsHelperDeclaresPair(): void
    {
        $blueprint = (new Blueprint('t'))->id()->timestamps(3);
        $columns = $blueprint->getColumns();

        self::assertSame('created_at', $columns[1]['name']);
        self::assertSame(3, $columns[1]['precision']);
        self::assertFalse($columns[1]['nullable']);
        self::assertSame('updated_at', $columns[2]['name']);
        self::assertSame(3, $columns[2]['precision']);
    }

    /**
     * The timestamps() helper's nullable flag renders nullable stamps.
     */
    public function testTimestampsNullableFlag(): void
    {
        $blueprint = (new Blueprint('t'))->id()->timestamps(null, nullable: true);
        $columns = $blueprint->getColumns();

        self::assertTrue($columns[1]['nullable']);
        self::assertTrue($columns[2]['nullable']);
        self::assertNull($columns[1]['precision']);
    }

    /**
     * The softDeletes() helper declares a nullable deleted_at column with
     * the given precision.
     */
    public function testSoftDeletesHelperDeclaresColumn(): void
    {
        $blueprint = (new Blueprint('t'))->softDeletes(3);
        $columns = $blueprint->getColumns();

        self::assertSame('deleted_at', $columns[0]['name']);
        self::assertSame(3, $columns[0]['precision']);
        self::assertTrue($columns[0]['nullable']);
    }

    /**
     * Precision outside 1–6 fails fast at declaration.
     */
    public function testPrecisionOutOfRangeFailsFast(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Blueprint('t'))->timestamp('started_at', 7);
    }

    /**
     * Precision zero is rejected — use null for whole seconds.
     */
    public function testPrecisionZeroFailsFast(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Blueprint('t'))->timestamp('started_at', 0);
    }

    // ---- Precision-aware encoding ----

    /**
     * A precision column encodes a datetime with exactly the declared
     * number of fractional digits, in UTC.
     */
    public function testEncodeWritesFractionalSeconds(): void
    {
        $column = new Column(type: ColumnType::DateTime, name: 'started_at', precision: 3);

        $encoded = $column->encode(Carbon::parse('2026-09-18 12:15:42.318514', 'UTC'), 'datetime');

        self::assertSame('2026-09-18 12:15:42.318', $encoded);
    }

    /**
     * The fraction is truncated (not rounded) to the declared precision.
     */
    public function testEncodeTruncatesFraction(): void
    {
        $column = new Column(type: ColumnType::DateTime, name: 'started_at', precision: 3);

        $encoded = $column->encode(Carbon::parse('2026-09-18 12:15:42.999999', 'UTC'), 'datetime');

        self::assertSame('2026-09-18 12:15:42.999', $encoded);
    }

    /**
     * A timezone-aware value is normalized to UTC before formatting,
     * mirroring the codec's behavior.
     */
    public function testEncodeNormalizesTimezone(): void
    {
        $column = new Column(type: ColumnType::DateTime, name: 'started_at', precision: 3);

        $encoded = $column->encode(Carbon::parse('2026-09-18 14:15:42.318', 'Europe/Paris'), 'datetime');

        self::assertSame('2026-09-18 12:15:42.318', $encoded);
    }

    /**
     * A non-precision column still passes DateTimeInterface through
     * untouched — the codec formats it.
     */
    public function testEncodeWithoutPrecisionPassesThrough(): void
    {
        $column = new Column(type: ColumnType::DateTime, name: 'started_at');
        $value = Carbon::parse('2026-09-18 12:15:42.318', 'UTC');

        self::assertSame($value, $column->encode($value, 'datetime'));
    }

    // ---- Round-trip through the live database ----

    /**
     * A model save on a precision(3) column truncates a microsecond input
     * to the declared digits, and the round-trip preserves them.
     */
    public function testPrecisionColumnRoundTripsMilliseconds(): void
    {
        $event = new TpEvent();
        $event->name = 'Deploy';
        $event->started_at = Carbon::parse('2026-09-18 12:15:42.318514', 'UTC');
        $event->save();

        $raw = $this->connection->table('tp_events')->where('name', '=', 'Deploy')->first();
        self::assertNotNull($raw);
        self::assertSame('2026-09-18 12:15:42.318', $raw->started_at);

        $fresh = TpEvent::find($event->id);
        self::assertNotNull($fresh);
        self::assertNotNull($fresh->started_at);
        self::assertSame('2026-09-18 12:15:42.318000', $fresh->started_at->format('Y-m-d H:i:s.u'));
    }

    // ---- Auto-stamping lifecycle ----

    /**
     * An INSERT stamps both columns; an UPDATE bumps updated_at only.
     */
    public function testInsertStampsBothUpdateBumpsUpdatedAt(): void
    {
        $event = new TpEvent();
        $event->name = 'Deploy';
        $event->save();

        $raw = $this->connection->table('tp_events')->where('id', '=', $event->id)->first();
        self::assertNotNull($raw);
        self::assertNotNull($raw->created_at);
        self::assertNotNull($raw->updated_at);

        $createdAt = $raw->created_at;
        $updatedAt = $raw->updated_at;

        // Advance the clock deterministically: a same-second update would
        // make the bump invisible.
        sleep(1);

        $event->name = 'Deploy-edited';
        $event->save();

        $raw = $this->connection->table('tp_events')->where('id', '=', $event->id)->first();
        self::assertNotNull($raw);
        self::assertSame($createdAt, $raw->created_at, 'created_at never changes after insert');
        self::assertNotSame($updatedAt, $raw->updated_at, 'updated_at bumps on update');
    }

    /**
     * A caller-set created_at is never overwritten by the stamper.
     */
    public function testCallerSetCreatedAtWins(): void
    {
        $event = new TpEvent();
        $event->name = 'Backdated';
        $event->created_at = Carbon::parse('2020-01-01 00:00:00.000', 'UTC');
        $event->save();

        $raw = $this->connection->table('tp_events')->where('id', '=', $event->id)->first();
        self::assertNotNull($raw);
        self::assertSame('2020-01-01 00:00:00.000', $raw->created_at);
    }

    /**
     * A model without the Timestamps trait is never stamped.
     */
    public function testModelWithoutTraitIsUnaffected(): void
    {
        $plain = new TpPlain();
        $plain->name = 'Untouched';
        $plain->save();

        $raw = $this->connection->table('tp_plain')->where('id', '=', $plain->id)->first();
        self::assertNotNull($raw);
        self::assertNull($raw->created_at ?? null);
        self::assertNull($raw->updated_at ?? null);
    }

    /**
     * Renamed stamp columns (via the column-name overrides) are stamped.
     */
    public function testRenamedStampColumnsAreStamped(): void
    {
        $row = new TpRenamed();
        $row->name = 'Renamed';
        $row->save();

        $raw = $this->connection->table('tp_renamed')->where('id', '=', $row->id)->first();
        self::assertNotNull($raw);
        self::assertNotNull($raw->began_at);
        self::assertNotNull($raw->modified_at);
    }

    // ---- Lifecycle callbacks ----

    /**
     * saving fires before the write (its mutation lands in the row); saved
     * fires after; multiple listeners run in registration order.
     */
    public function testSavingAndSavedFireInOrder(): void
    {
        $event = new TpEvent();
        $calls = [];

        $event->saving(function (TpEvent $m) use (&$calls): void {
            $calls[] = 'saving-1';
            $m->name = 'Set by listener'; // mutation must land in the row
        });
        $event->saving(function () use (&$calls): void {
            $calls[] = 'saving-2';
        });
        $event->saved(function () use (&$calls): void {
            $calls[] = 'saved';
        });

        $event->save();

        self::assertSame(['saving-1', 'saving-2', 'saved'], $calls);

        $raw = $this->connection->table('tp_events')->where('id', '=', $event->id)->first();
        self::assertNotNull($raw);
        self::assertSame('Set by listener', $raw->name);
    }

    /**
     * saved fires on UPDATE too, and saving mutations land in the update.
     */
    public function testSavedFiresOnUpdate(): void
    {
        $event = new TpEvent();
        $event->name = 'First';
        $event->save();

        $fired = 0;
        $event->saved(function () use (&$fired): void {
            $fired++;
        });
        $event->saving(function (TpEvent $m): void {
            $m->name = 'Second';
        });

        $event->save();

        self::assertSame(1, $fired);

        $raw = $this->connection->table('tp_events')->where('id', '=', $event->id)->first();
        self::assertNotNull($raw);
        self::assertSame('Second', $raw->name);
    }

    /**
     * deleted fires after a successful soft delete, and restored after
     * restore() — neither fires when the write reports failure.
     */
    public function testDeletedAndRestoredFire(): void
    {
        $post = new TpPost();
        $post->name = 'Lifecycle';
        $post->save();

        $deleted = 0;
        $restored = 0;
        $post->deleted(function () use (&$deleted): void {
            $deleted++;
        });
        $post->restored(function () use (&$restored): void {
            $restored++;
        });

        self::assertTrue($post->delete());
        self::assertSame(1, $deleted);

        self::assertTrue($post->restore());
        self::assertSame(1, $restored);

        // An unsaved model's delete reports false — no event.
        $unsaved = new TpPost();
        $unsaved->deleted(function () use (&$deleted): void {
            $deleted++;
        });
        self::assertFalse($unsaved->delete());
        self::assertSame(1, $deleted, 'a failed delete fires nothing');
    }

    /**
     * deleted fires after a hard delete on a model without SoftDeletes.
     */
    public function testDeletedFiresOnHardDelete(): void
    {
        $plain = new TpTraitNoColumns();
        $plain->name = 'Hard';
        $plain->save();

        $deleted = 0;
        $plain->deleted(function () use (&$deleted): void {
            $deleted++;
        });

        self::assertTrue($plain->delete());
        self::assertSame(1, $deleted);
    }

    /**
     * The trait auto-declares its stamp columns — a model using it with no
     * declared #[Column] stamps still saves, and the columns exist in the
     * metadata (so schema sync creates them).
     */
    public function testTraitWithoutColumnsAutoDeclares(): void
    {
        $plain = new TpTraitNoColumns();
        $plain->name = 'Guarded';
        self::assertTrue($plain->save());

        $raw = $this->connection->table('tp_auto')->where('id', '=', $plain->id)->first();
        self::assertNotNull($raw);
        self::assertSame('Guarded', $raw->name);
        self::assertNotNull($raw->created_at, 'the auto-declared created_at is stamped');
        self::assertNotNull($raw->updated_at, 'the auto-declared updated_at is stamped');

        $blueprint = Blueprint::fromMetadata(TpTraitNoColumns::class);
        $names = array_map(fn (array $column) => $column['name'], $blueprint->getColumns());

        self::assertContains('created_at', $names);
        self::assertContains('updated_at', $names);
    }

    /**
     * Auto-declared stamp columns are NOT NULL datetime synthetics — the
     * trait guarantees a stamp on every model insert.
     */
    public function testAutoDeclaredStampsAreNotNullDatetime(): void
    {
        $blueprint = Blueprint::fromMetadata(TpTraitNoColumns::class);

        foreach ($blueprint->getColumns() as $column) {
            if (in_array($column['name'], ['created_at', 'updated_at'], true)) {
                self::assertSame(ColumnType::DateTime, $column['type']);
                self::assertFalse($column['nullable']);
                self::assertNull($column['precision']);
            }
        }
    }

    /**
     * The trait's auto-declared stamps match Blueprint::timestamps()'s
     * default shape exactly — one contract, two entry points.
     */
    public function testTraitStampsMatchBlueprintTimestampsShape(): void
    {
        $traitShape = array_values(array_filter(
            Blueprint::fromMetadata(TpTraitNoColumns::class)->getColumns(),
            fn (array $column) => in_array($column['name'], ['created_at', 'updated_at'], true),
        ));
        $blueprintShape = array_values(array_filter(
            (new Blueprint('t'))->id()->timestamps()->getColumns(),
            fn (array $column) => in_array($column['name'], ['created_at', 'updated_at'], true),
        ));

        self::assertCount(2, $traitShape);
        self::assertCount(2, $blueprintShape);

        foreach ($traitShape as $index => $column) {
            self::assertSame($blueprintShape[$index]['type'], $column['type']);
            self::assertSame($blueprintShape[$index]['nullable'], $column['nullable']);
            self::assertSame($blueprintShape[$index]['precision'], $column['precision']);
        }
    }

    /**
     * Precision on an int Unix-timestamp column fails fast at metadata
     * build.
     */
    public function testPrecisionOnIntTimestampColumnFailsFast(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        MetadataProbe::trigger();
    }

    /**
     * A model using BOTH SoftDeletes and Timestamps composes cleanly —
     * freshTimestamp() lives on the Model base, so the two traits never
     * collide, and both behaviors fire on one save.
     */
    public function testSoftDeletesAndTimestampsCompose(): void
    {
        $post = new TpPost();
        $post->name = 'Composed';
        self::assertTrue($post->save());

        $raw = $this->connection->table('tp_posts')->where('id', '=', $post->id)->first();
        self::assertNotNull($raw);
        self::assertNotNull($raw->created_at);
        self::assertNotNull($raw->updated_at);
        self::assertNull($raw->deleted_at);

        self::assertTrue($post->delete());
        self::assertTrue($post->trashed());

        $raw = $this->connection->table('tp_posts')->where('id', '=', $post->id)->first();
        self::assertNotNull($raw);
        self::assertNotNull($raw->deleted_at);
    }
}

/**
 * A stamped model with millisecond-precision datetime columns.
 */
#[Table('tp_events')]
final class TpEvent extends Model
{
    use Timestamps;

    /**
     * The auto-increment primary key.
     *
     * @var int
     */
    #[Column(ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * The event name.
     *
     * @var string
     */
    #[Column(ColumnType::String, length: 64)]
    public string $name;

    /**
     * When the event started — millisecond precision.
     *
     * @var \Carbon\Carbon|null
     */
    #[Column(ColumnType::DateTime, nullable: true, precision: 3)]
    public ?Carbon $started_at;

    /**
     * The insert stamp — millisecond precision.
     *
     * @var \Carbon\Carbon|null
     */
    #[Column(ColumnType::DateTime, nullable: true, precision: 3)]
    public ?Carbon $created_at;

    /**
     * The update stamp — millisecond precision.
     *
     * @var \Carbon\Carbon|null
     */
    #[Column(ColumnType::DateTime, nullable: true, precision: 3)]
    public ?Carbon $updated_at;
}

/**
 * An unstamped model with a second-precision datetime column.
 */
#[Table('tp_plain')]
final class TpPlain extends Model
{
    /**
     * The auto-increment primary key.
     *
     * @var int
     */
    #[Column(ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * The row name.
     *
     * @var string
     */
    #[Column(ColumnType::String, length: 64)]
    public string $name;

    /**
     * When the row started — whole seconds.
     *
     * @var \Carbon\Carbon|null
     */
    #[Column(ColumnType::DateTime, nullable: true)]
    public ?Carbon $started_at;
}

/**
 * A stamped model whose stamp columns carry non-default names.
 */
#[Table('tp_renamed')]
final class TpRenamed extends Model
{
    use Timestamps;

    /**
     * The auto-increment primary key.
     *
     * @var int
     */
    #[Column(ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * The row name.
     *
     * @var string
     */
    #[Column(ColumnType::String, length: 64)]
    public string $name;

    /**
     * The renamed created-at stamp.
     *
     * @var \Carbon\Carbon|null
     */
    #[Column(ColumnType::DateTime, nullable: true, precision: 3)]
    public ?Carbon $began_at;

    /**
     * The renamed updated-at stamp.
     *
     * @var \Carbon\Carbon|null
     */
    #[Column(ColumnType::DateTime, nullable: true, precision: 3)]
    public ?Carbon $modified_at;

    /**
     * The renamed created-at column.
     *
     * @return string
     */
    public static function createdAtColumn(): string
    {
        return 'began_at';
    }

    /**
     * The renamed updated-at column.
     *
     * @return string
     */
    public static function updatedAtColumn(): string
    {
        return 'modified_at';
    }
}

/**
 * A stamped model with NO declared stamp columns — the trait auto-declares
 * them as synthetic NOT NULL datetime mappings.
 */
#[Table('tp_auto')]
final class TpTraitNoColumns extends Model
{
    use Timestamps;

    /**
     * The auto-increment primary key.
     *
     * @var int
     */
    #[Column(ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * The row name.
     *
     * @var string
     */
    #[Column(ColumnType::String, length: 64)]
    public string $name;
}

/**
 * A model whose int property declares precision on a Timestamp column —
 * the metadata build must fail fast.
 */
/**
 * A model using BOTH SoftDeletes and Timestamps — the composition must
 * work without a trait-method collision.
 */
#[Table('tp_posts')]
final class TpPost extends Model
{
    use SoftDeletes;
    use Timestamps;

    /**
     * The auto-increment primary key.
     *
     * @var int
     */
    #[Column(ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * The post name.
     *
     * @var string
     */
    #[Column(ColumnType::String, length: 64)]
    public string $name;

    /**
     * The insert stamp.
     *
     * @var \Carbon\Carbon|null
     */
    #[Column(ColumnType::DateTime, nullable: true, precision: 3)]
    public ?Carbon $created_at;

    /**
     * The update stamp.
     *
     * @var \Carbon\Carbon|null
     */
    #[Column(ColumnType::DateTime, nullable: true, precision: 3)]
    public ?Carbon $updated_at;

    /**
     * The soft-delete stamp.
     *
     * @var \Carbon\Carbon|null
     */
    #[Column(ColumnType::DateTime, nullable: true, precision: 3)]
    public ?Carbon $deleted_at;
}

/**
 * Entry point for the fail-fast metadata probe — the exception must fire
 * during the metadata build, not at class load.
 */
final class MetadataProbe
{
    /**
     * Force a metadata build on the invalid model.
     *
     * @return void
     */
    public static function trigger(): void
    {
        MetadataProbeModel::buildForTest();
    }
}

/**
 * A model whose int property declares precision on a Timestamp column —
 * the metadata build must fail fast.
 */
#[Table('tp_bad')]
final class MetadataProbeModel extends Model
{
    /**
     * Precision on an int Unix-timestamp column is meaningless.
     *
     * @var int
     */
    #[Column(ColumnType::Timestamp, precision: 3)]
    public int $occurred_at;

    /**
     * Force a metadata build so the fail-fast fires.
     *
     * @return void
     */
    public static function buildForTest(): void
    {
        \BlueprintAU\Radiant\Metadata\MetadataFactory::for(self::class);
    }
}
