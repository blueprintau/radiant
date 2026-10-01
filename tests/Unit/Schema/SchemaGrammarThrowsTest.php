<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Schema;

use BlueprintAU\Radiant\Database\Schema\Blueprint;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Database\Schema\Grammars\MySqlSchemaGrammar;
use BlueprintAU\Radiant\Tests\Support\Expectation;
use BlueprintAU\Radiant\Database\Schema\Grammars\PostgresSchemaGrammar;
use PHPUnit\Framework\TestCase;

/**
 * The schema grammar throw paths and Blueprint residuals the main grammar
 * suite never reaches: the empty drop/modify guards, the CHECK-add
 * compilers, the per-dialect identifier limits, the rename validation,
 * the deferrability guard, and the drop-handle accessors.
 */
final class SchemaGrammarThrowsTest extends TestCase
{
    /**
     * MySQL's compileDropColumn rejects an empty drop list — the ALTER
     * would be unbuildable.
     */
    public function testMySqlDropColumnEmptyThrows(): void
    {
        $blueprint = new Blueprint('users');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Cannot drop columns with no columns defined');

        (new MySqlSchemaGrammar())->compileDropColumns($blueprint);
    }

    /**
     * Postgres's compileDropColumn rejects an empty drop list.
     */
    public function testPostgresDropColumnEmptyThrows(): void
    {
        $blueprint = new Blueprint('users');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Cannot drop columns with no columns defined');

        (new PostgresSchemaGrammar())->compileDropColumns($blueprint);
    }

    /**
     * MySQL's compileModifyColumn rejects a blueprint with no columns.
     */
    public function testMySqlModifyColumnEmptyThrows(): void
    {
        $blueprint = new Blueprint('users');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Cannot modify columns with no columns defined');

        (new MySqlSchemaGrammar())->compileModifyColumn($blueprint);
    }

    /**
     * Postgres's compileModifyColumn rejects a blueprint with no columns.
     */
    public function testPostgresModifyColumnEmptyThrows(): void
    {
        $blueprint = new Blueprint('users');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Cannot modify columns with no columns defined');

        (new PostgresSchemaGrammar())->compileModifyColumn($blueprint);
    }

    /**
     * MySQL's compileAddCheck renders the in-place CHECK-add form.
     */
    public function testMySqlCompileAddCheck(): void
    {
        $sql = (new MySqlSchemaGrammar())->compileAddCheck('users', 'users_age_check', 'age >= 0');

        self::assertSame(
            'ALTER TABLE `users` ADD CONSTRAINT `users_age_check` CHECK (age >= 0)',
            $sql,
        );
    }

    /**
     * Postgres's compileAddCheck renders the in-place CHECK-add form.
     */
    public function testPostgresCompileAddCheck(): void
    {
        $sql = (new PostgresSchemaGrammar())->compileAddCheck('users', 'users_age_check', 'age >= 0');

        self::assertSame(
            'ALTER TABLE "users" ADD CONSTRAINT "users_age_check" CHECK (age >= 0)',
            $sql,
        );
    }

    /**
     * MySQL accepts an identifier AT the 64-character limit.
     */
    #[\PHPUnit\Framework\Attributes\DoesNotPerformAssertions]
    public function testMySqlIdentifierAtLimitPasses(): void
    {
        (new MySqlSchemaGrammar())->assertValidIdentifier(str_repeat('a', 64));
    }

    /**
     * MySQL caps identifiers at 64 characters — 65 throws.
     */
    public function testMySqlIdentifierOverLimitThrows(): void
    {
        $grammar = new MySqlSchemaGrammar();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains("exceeds MySQL's 64-character limit");

        $grammar->assertValidIdentifier(str_repeat('a', 65));
    }

    /**
     * Postgres accepts an identifier AT the 63-byte limit.
     */
    #[\PHPUnit\Framework\Attributes\DoesNotPerformAssertions]
    public function testPostgresIdentifierAtLimitPasses(): void
    {
        (new PostgresSchemaGrammar())->assertValidIdentifier(str_repeat('a', 63));
    }

    /**
     * Postgres caps identifiers at 63 bytes — 64 throws.
     */
    public function testPostgresIdentifierOverLimitThrows(): void
    {
        $grammar = new PostgresSchemaGrammar();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains("exceeds Postgres's 63-byte limit");

        $grammar->assertValidIdentifier(str_repeat('a', 64));
    }

    // ---- Blueprint residuals ----

    /**
     * A rename with an empty source or target fails fast.
     */
    public function testRenameColumnRejectsEmptyNames(): void
    {
        $blueprint = new Blueprint('users');

        Expectation::throwsWithMessage(
            fn () => $blueprint->renameColumn('', 'other'),
            \InvalidArgumentException::class,
            'requires non-empty column names',
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('requires non-empty column names');

        $blueprint->renameColumn('name', '  ');
    }

    /**
     * A rename to the SAME name fails fast — a no-op rename is a bug.
     */
    public function testRenameColumnRejectsSameName(): void
    {
        $blueprint = new Blueprint('users');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('requires different names; got [name] -> [name]');

        $blueprint->renameColumn('name', 'name');
    }

    /**
     * initiallyDeferred without deferrable fails fast — INITIALLY
     * DEFERRED implies DEFERRABLE in Postgres.
     */
    public function testInitiallyDeferredWithoutDeferrableThrows(): void
    {
        $blueprint = new Blueprint('orders');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('initiallyDeferred without deferrable');

        $blueprint->foreignKey(['customer_id'], 'customers', ['id'], initiallyDeferred: true);
    }

    /**
     * initiallyDeferred WITH deferrable is accepted and recorded.
     */
    public function testInitiallyDeferredWithDeferrableIsAccepted(): void
    {
        $blueprint = (new Blueprint('orders'))
            ->foreignKey(['customer_id'], 'customers', ['id'], deferrable: true, initiallyDeferred: true);

        $foreignKey = $blueprint->getForeignKeys()[0];
        self::assertTrue($foreignKey['deferrable']);
        self::assertTrue($foreignKey['initiallyDeferred']);
    }

    /**
     * dropForeignKey()/dropCheck() record the live constraint names —
     * the diff-driven drop handles.
     */
    public function testDropHandlesRoundTrip(): void
    {
        $blueprint = (new Blueprint('users'))
            ->dropForeignKey('users_role_id_foreign')
            ->dropCheck('users_age_check');

        self::assertSame(['users_role_id_foreign'], $blueprint->getDropForeignKeys());
        self::assertSame(['users_age_check'], $blueprint->getDropChecks());
    }

    /**
     * datetime() is an explicit alias of timestamp() — identical column
     * shapes.
     */
    public function testDatetimeAliasMatchesTimestamp(): void
    {
        $viaDatetime = (new Blueprint('events'))->datetime('started_at', 3);
        $viaTimestamp = (new Blueprint('events'))->timestamp('started_at', 3);

        self::assertSame($viaTimestamp->getColumns(), $viaDatetime->getColumns());
    }

    /**
     * A CHECK with no declared column referenced derives a POSITIONAL
     * name — unique per declaration order.
     */
    public function testDeriveCheckNamePositionalSuffix(): void
    {
        $blueprint = (new Blueprint('events'))
            ->id()
            ->string('name', 64)
            ->check('1 = 1')
            ->check('2 = 2');

        $checks = $blueprint->getChecks();

        self::assertSame('events_1_check', $checks[0]['name']);
        self::assertSame('events_2_check', $checks[1]['name']);
    }

    /**
     * A CHECK referencing a declared column derives its name from the
     * LONGEST matching column — `user_id` wins over `id`.
     */
    public function testDeriveCheckNamePrefersLongestColumn(): void
    {
        $blueprint = (new Blueprint('orders'))
            ->id()
            ->column(ColumnType::BigInt, 'user_id')
            ->check('user_id > 0');

        self::assertSame('orders_user_id_check', $blueprint->getChecks()[0]['name']);
    }
}
