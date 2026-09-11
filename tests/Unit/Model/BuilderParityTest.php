<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Model;

use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Attributes\Table;
use BlueprintAU\Radiant\Database\Connections\SqlConnection;
use BlueprintAU\Radiant\Database\DatabaseManager;
use BlueprintAU\Radiant\Database\Schema\Blueprint;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Model;
use PHPUnit\Framework\TestCase;

/**
 * Fixture: builder-parity parent model with a datetime + JSON column.
 */
#[Table(name: 'bp_users')]
class BpUser extends Model
{
    /**
     * The auto-increment primary key.
     *
     * @var int
     */
    #[Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    /**
     * The user's name.
     *
     * @var string
     */
    #[Column(type: ColumnType::String, length: 64)]
    public string $name;

    /**
     * The user's signup timestamp — the decode-path probe column.
     *
     * @var \Carbon\Carbon
     */
    #[Column(type: ColumnType::DateTime, nullable: true, name: 'signed_up_at')]
    public \Carbon\Carbon|null $signedUpAt;

    /**
     * The user's preferences — the JSON encode/decode probe column.
     *
     * @var array<string, mixed>
     */
    #[Column(type: ColumnType::Json, nullable: true)]
    public array|null $meta;
}

/**
 * The ModelQueryBuilder parity contract: every builder helper behaves
 * like its model-level sibling — column validation fails fast on typos,
 * scalar reads decode through the casts, writes encode through them.
 */
final class BuilderParityTest extends TestCase
{
    /**
     * The live SQLite connection.
     *
     * @var SqlConnection
     */
    private SqlConnection $connection;

    /**
     * Build a :memory: SQLite manager, create the fixture table, seed rows.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $manager = new DatabaseManager([
            'default' => ['driver' => 'sqlite', 'database' => ':memory:'],
        ]);
        \BlueprintAU\Radiant\Database::setManager($manager);
        $this->connection = $manager->sqlConnection();

        $this->connection->create((new Blueprint('bp_users'))
            ->column(ColumnType::BigInt, 'id', primaryKey: true, autoIncrement: true)
            ->column(ColumnType::String, 'name', length: 64)
            ->column(ColumnType::DateTime, 'signed_up_at', nullable: true)
            ->column(ColumnType::Json, 'meta', nullable: true));

        $user = new BpUser();
        $user->name = 'ada';
        $user->signedUpAt = \Carbon\Carbon::parse('2026-01-15 10:00:00');
        $user->meta = ['theme' => 'dark'];
        $user->save();

        $second = new BpUser();
        $second->name = 'ben';
        $second->meta = ['theme' => 'light'];
        $second->save();
    }

    /**
     * Tear down the static facade so other tests are unaffected.
     */
    protected function tearDown(): void
    {
        \BlueprintAU\Radiant\Database::setManager(new DatabaseManager([
            'default' => ['driver' => 'sqlite', 'database' => ':memory:'],
        ]));
        parent::tearDown();
    }

    // ---- Column validation on the previously-unvalidated helpers ----

    /**
     * whereColumn() must fail fast on an unknown column.
     */
    public function testWhereColumnRejectsUnknownColumn(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown column [not_a_column]');

        $this->runInvalid(function (): void {
            BpUser::newQuery()->whereColumn('name', '=', 'not_a_column');
        });
    }

    /**
     * A valid whereColumn() still runs end to end.
     */
    public function testWhereColumnAcceptsDeclaredColumns(): void
    {
        $rows = BpUser::newQuery()->whereColumn('name', '=', 'name')->orderBy('id')->get();

        self::assertCount(2, $rows);
    }

    /**
     * Nested where groups validate columns — the group must be built on
     * the MODEL builder, not a plain query builder.
     */
    public function testWhereNestedRejectsUnknownColumnInCallback(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown column [typo_column]');

        $this->runInvalid(function (): void {
            BpUser::newQuery()->whereNested(function ($nested): void {
                $nested->where('typo_column', '=', 1);
            });
        });
    }

    /**
     * The empty-group guard still fires through the model-aware override.
     */
    public function testWhereNestedRejectsEmptyGroup(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must contain at least one clause');

        $this->runInvalid(function (): void {
            BpUser::newQuery()->whereNested(function ($nested): void {
            });
        });
    }

    /**
     * on()/orOn() validate both columns against the model + its joins.
     */
    public function testOnRejectsUnknownColumn(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown column [not_a_column]');

        $this->runInvalid(function (): void {
            BpUser::newQuery()
                ->join('bp_users as other', 'bp_users.id', '=', 'other.id')
                ->on('bp_users.name', '=', 'not_a_column');
        });
    }

    // ---- Scalar reads decode through the casts ----

    /**
     * value() on a declared datetime column decodes to Carbon.
     */
    public function testValueDecodesDatetime(): void
    {
        $value = BpUser::newQuery()->where('name', '=', 'ada')->value('signed_up_at');

        self::assertInstanceOf(\Carbon\Carbon::class, $value);
        self::assertSame('2026-01-15 10:00:00', $value->format('Y-m-d H:i:s'));
    }

    /**
     * value() on an undeclared expression passes through raw.
     */
    public function testValuePassesThroughUnknownExpressions(): void
    {
        $value = BpUser::newQuery()->value('count(*)');

        self::assertSame(2, (int) $value);
    }

    /**
     * pluck() decodes declared columns — datetimes come back as Carbons.
     */
    public function testPluckDecodesDatetime(): void
    {
        $values = BpUser::newQuery()->whereNotNull('signed_up_at')->orderBy('id')->pluck('signed_up_at');

        self::assertCount(1, $values);
        self::assertInstanceOf(\Carbon\Carbon::class, $values[0]);
    }

    /**
     * max() on a datetime column decodes to Carbon, not a raw string.
     */
    public function testMaxDecodesDatetime(): void
    {
        $max = BpUser::newQuery()->max('signed_up_at');

        self::assertInstanceOf(\Carbon\Carbon::class, $max);
        self::assertSame('2026-01-15 10:00:00', $max->format('Y-m-d H:i:s'));
    }

    /**
     * aggregates() decodes each aggregate's column cast.
     */
    public function testAggregatesDecodeColumns(): void
    {
        $result = BpUser::newQuery()->aggregates([
            'total' => ['count', '*'],
            'latest' => ['max', 'signed_up_at'],
        ]);

        self::assertSame(2, (int) $result['total']);
        self::assertInstanceOf(\Carbon\Carbon::class, $result['latest']);
    }

    // ---- Writes encode through the casts ----

    /**
     * Builder-level insert encodes DateTime/array values like save() does.
     */
    public function testInsertEncodesThroughCasts(): void
    {
        BpUser::newQuery()->insert([
            'name' => 'grace',
            'signed_up_at' => \Carbon\Carbon::parse('2026-03-01 08:30:00'),
            'meta' => ['lang' => 'en'],
        ]);

        $raw = $this->connection->table('bp_users')->where('name', '=', 'grace')->first();

        self::assertNotNull($raw);
        self::assertSame('2026-03-01 08:30:00', $raw->signed_up_at);
        self::assertNotNull($raw->meta);

        $decoded = json_decode((string) $raw->meta, true);
        self::assertSame('en', $decoded['lang']);
    }

    /**
     * Builder-level update encodes DateTime values through the cast.
     */
    public function testUpdateEncodesThroughCasts(): void
    {
        $affected = BpUser::newQuery()->where('name', '=', 'ben')
            ->update(['signed_up_at' => \Carbon\Carbon::parse('2026-02-02 09:00:00')]);

        self::assertSame(1, $affected);

        $fresh = BpUser::newQuery()->where('name', '=', 'ben')->first();
        self::assertNotNull($fresh);
        self::assertInstanceOf(\Carbon\Carbon::class, $fresh->signedUpAt);
        self::assertSame('2026-02-02 09:00:00', $fresh->signedUpAt->format('Y-m-d H:i:s'));
    }

    /**
     * Builder-level writes reject unknown columns — the write path
     * validates as hard as the read path.
     */
    public function testInsertRejectsUnknownColumn(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown column [not_a_column]');

        $this->runInvalid(function (): void {
            BpUser::newQuery()->insert(['name' => 'x', 'not_a_column' => 1]);
        });
    }

    /**
     * Bulk insert encodes every row and validates every key.
     */
    public function testBulkInsertEncodesThroughCasts(): void
    {
        BpUser::newQuery()->insert([
            ['name' => 'h1', 'signed_up_at' => \Carbon\Carbon::parse('2026-04-01 00:00:00'), 'meta' => ['a' => 1]],
            ['name' => 'h2', 'signed_up_at' => null, 'meta' => ['b' => 2]],
        ]);

        $rows = BpUser::newQuery()->whereIn('name', ['h1', 'h2'])->orderBy('name')->get();

        self::assertCount(2, $rows);
        self::assertInstanceOf(\Carbon\Carbon::class, $rows[0]->signedUpAt);
        self::assertSame(['b' => 2], $rows[1]->meta);
    }

    // ---- Streaming hydrates ----

    /**
     * cursor() yields hydrated models — the streaming counterpart of get().
     */
    public function testCursorYieldsHydratedModels(): void
    {
        $models = [];

        foreach (BpUser::newQuery()->orderBy('id')->cursor() as $model) {
            $models[] = $model;
        }

        self::assertCount(2, $models);
        self::assertContainsOnlyInstancesOf(BpUser::class, $models);
        self::assertSame('ada', $models[0]->name);
        self::assertInstanceOf(\Carbon\Carbon::class, $models[0]->signedUpAt);
    }

    // ---- The JSON round-trip guard ----

    /**
     * A decoded JSON array written back through update() must round-trip
     * — NOT double-encode into a quoted JSON string.
     */
    public function testJsonRoundTripDoesNotDoubleEncode(): void
    {
        $user = BpUser::newQuery()->where('name', '=', 'ada')->first();
        self::assertNotNull($user);
        self::assertSame('dark', $user->meta['theme'] ?? null);

        // Write the DECODED array back via the builder — the encode must
        // produce the same cell content, not `"{\"theme\":\"dark\"}"`.
        BpUser::newQuery()->where('name', '=', 'ada')->update(['meta' => ['theme' => 'light']]);

        $fresh = BpUser::newQuery()->where('name', '=', 'ada')->first();
        self::assertNotNull($fresh);
        self::assertSame('light', $fresh->meta['theme'] ?? null);
    }

    // ---- Helpers ----

    /**
     * Run a callable expected to throw — the mixed-typed boundary so
     * PHPStan cannot flag the invalid argument at the call site.
     *
     * @param callable(): void $callback The invalid invocation.
     * @return void
     */
    private function runInvalid(callable $callback): void
    {
        $callback();
    }
}
