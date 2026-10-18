<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Schema;

use BlueprintAU\Radiant\Database\Connections\SqlConnection;
use BlueprintAU\Radiant\Database\Schema\Blueprint;
use BlueprintAU\Radiant\Database\Schema\DataStep;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Database\Schema\Enums\SchemaOperation;
use BlueprintAU\Radiant\Database\Schema\SchemaChange;
use BlueprintAU\Radiant\Database\Schema\SchemaSynchronizer;
use BlueprintAU\Radiant\Tests\Support\DatabaseTestCase;
use BlueprintAU\Radiant\Tests\Support\Expectation;

/**
 * Callable data steps — the {@see DataStep} sibling of the SQL DataStep
 * change: applies through the same dispatch, rides the same confirm gate
 * and transaction discipline, and vetoes by throwing.
 */
final class CallableDataStepTest extends DatabaseTestCase
{
    /**
     * A callable step applies between the ADD and DROP phases in authored
     * order — positionally identical to a SQL DataStep change.
     */
    public function testCallableStepRunsBetweenChangesPositionally(): void
    {
        $this->connection->create(
            (new Blueprint('cds_order'))
                ->id()
                ->column(ColumnType::BigInt, 'post_id', nullable: true)
                ->column(ColumnType::String, 'title', length: 255),
        );
        $this->connection->table('cds_order')->insert([
            ['post_id' => 7, 'title' => 'first'],
            ['post_id' => null, 'title' => 'orphan'],
        ]);

        // Phase 1: add the morph pair.
        $add = (new Blueprint('cds_order'))
            ->column(ColumnType::String, 'attachable_type', length: 255, nullable: true)
            ->column(ColumnType::BigInt, 'attachable_id', nullable: true);
        $this->connection->alter(SchemaOperation::AddColumn, $add);

        // The callable step: the class-string is a PHP constant — bound,
        // never inlined into SQL, refactor-safe.
        $step = new DataStep(
            'cds_order',
            'morph backfill: legacy post_id → attachable pair',
            true,
            function (SqlConnection $conn): void {
                $conn->statement(
                    'UPDATE cds_order SET attachable_type = ?, attachable_id = post_id WHERE post_id IS NOT NULL',
                    [\DateTimeImmutable::class],
                );
            },
        );

        $this->connection->apply($step);

        // Phase 2: drop the source.
        $this->connection->alter(SchemaOperation::DropColumn, (new Blueprint('cds_order'))->dropColumn('post_id'));

        $rows = $this->connection->selectSql('SELECT attachable_type, attachable_id FROM cds_order ORDER BY id')->all();
        self::assertSame(
            [\DateTimeImmutable::class, '7'],
            [$rows[0]->attachable_type, (string) $rows[0]->attachable_id],
        );
        self::assertSame([null, null], [$rows[1]->attachable_type, $rows[1]->attachable_id]);
    }

    /**
     * The destructive gate covers callable steps with no new gate code —
     * the label reads `data_step` and the description flows through.
     */
    public function testCallableStepIsDestructiveGated(): void
    {
        $this->connection->create(
            (new Blueprint('cds_gate'))
                ->id()
                ->column(ColumnType::String, 'email', length: 255),
        );

        $step = new DataStep(
            'cds_gate',
            'callable redaction: rewrite every email',
            true,
            function (SqlConnection $conn): void {
                $conn->statement("UPDATE cds_gate SET email = 'redacted@x.io'");
            },
        );

        $caught = Expectation::throwsWithMessage(
            fn (): array => (new SchemaSynchronizer($this->connection))->apply([$step]),
            \LogicException::class,
            'without confirmation',
        );

        self::assertStringContainsString('data_step', $caught->getMessage());
        self::assertStringContainsString('callable redaction', $caught->getMessage());

        // The gate refused BEFORE the closure ran.
        $rows = $this->connection->selectSql('SELECT email FROM cds_gate')->all();
        self::assertSame([], array_map(fn ($row) => $row->email, $rows));
    }

    /**
     * A closure throw is a veto — under a transactional apply the prior
     * statements roll back with the step, exactly as a failing SQL
     * statement rolls back a DataStep change.
     */
    public function testCallableStepVetoRollsBackInsideTransaction(): void
    {
        $this->connection->create(
            (new Blueprint('cds_veto'))
                ->id()
                ->column(ColumnType::String, 'email', length: 255),
        );
        $this->connection->table('cds_veto')->insert(['email' => 'a@x.io']);

        // Gate-as-PHP: count violations, veto with a real exception — the
        // portable replacement for RAISE/SIGNAL dialect forks.
        $step = new DataStep(
            'cds_veto',
            'rewrites emails, then gates on unmigrated rows',
            true,
            function (SqlConnection $conn): void {
                $conn->statement("UPDATE cds_veto SET email = 'changed@x.io'");

                $violations = $conn->selectSql(
                    "SELECT count(*) AS violations FROM cds_veto WHERE email NOT LIKE '%@kept.io'",
                )->first();

                if ($violations !== null && (int) $violations->violations > 0) {
                    throw new \DomainException('morph backfill gate: violating rows remain');
                }
            },
        );

        Expectation::throwsWithMessage(
            fn (): array => (new SchemaSynchronizer($this->connection))->apply(
                [$step],
                confirm: fn (DataStep|SchemaChange $change) => true,
                transactional: true,
            ),
            \DomainException::class,
            'violating rows remain',
        );

        $rows = $this->connection->selectSql('SELECT email FROM cds_veto')->all();
        self::assertSame(['a@x.io'], array_map(fn ($row) => $row->email, $rows));
    }

    /**
     * Destructiveness is explicit: a non-destructive callable skips the
     * gate, and a declined confirm leaves the step unapplied.
     */
    public function testCallableStepOptOutAndDeclinedConfirm(): void
    {
        $this->connection->create(
            (new Blueprint('cds_opt'))
                ->id()
                ->column(ColumnType::String, 'email', length: 255),
        );

        $insertOnly = new DataStep(
            'cds_opt',
            'seed rows — insert-only',
            false,
            function (SqlConnection $conn): void {
                $conn->statement("INSERT INTO cds_opt (email) VALUES ('seed@x.io')");
            },
        );

        $declined = new DataStep(
            'cds_opt',
            'redaction the reviewer declines',
            true,
            function (SqlConnection $conn): void {
                $conn->statement("UPDATE cds_opt SET email = 'nope@x.io'");
            },
        );

        $synchronizer = new SchemaSynchronizer($this->connection);
        $applied = $synchronizer->apply(
            [$declined, $insertOnly],
            confirm: fn (DataStep|SchemaChange $change) => $change === $insertOnly,
        );

        self::assertCount(1, $applied);
        self::assertSame($insertOnly, $applied[0]);

        $rows = $this->connection->selectSql('SELECT email FROM cds_opt')->all();
        self::assertSame(['seed@x.io'], array_map(fn ($row) => $row->email, $rows));
    }

    /**
     * The confirm and onChange callbacks receive the step instance itself
     * — the host branches on the type for rendering.
     */
    public function testCallbacksReceiveTheStepInstance(): void
    {
        $this->connection->create(
            (new Blueprint('cds_cb'))
                ->id()
                ->column(ColumnType::String, 'email', length: 255),
        );

        $step = new DataStep('cds_cb', 'callback plumbing', true, function (SqlConnection $conn): void {
            $conn->statement("INSERT INTO cds_cb (email) VALUES ('seed@x.io')");
        });

        $seen = [];
        /** @var array<string, DataStep|SchemaChange> $seen */
        $synchronizer = new SchemaSynchronizer($this->connection);
        $synchronizer->apply(
            [$step],
            confirm: function (DataStep|SchemaChange $change) use (&$seen): bool {
                $seen['confirm'] = $change;

                return true;
            },
            onChange: function (DataStep|SchemaChange $change) use (&$seen): void {
                $seen['onChange'] = $change;
            },
        );

        self::assertSame($step, $seen['confirm']);
        self::assertSame($step, $seen['onChange']);
    }
}
