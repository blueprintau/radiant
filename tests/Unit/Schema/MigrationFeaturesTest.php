<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Schema;

use BlueprintAU\Radiant\Database\Schema\Blueprint;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Database\Schema\Grammars\MySqlSchemaGrammar;
use BlueprintAU\Radiant\Database\Schema\Grammars\PostgresSchemaGrammar;
use BlueprintAU\Radiant\Database\Schema\Grammars\SqliteSchemaGrammar;
use BlueprintAU\Radiant\Tests\Support\DatabaseTestCase;
use BlueprintAU\Radiant\Tests\Support\Expectation;

/**
 * Compile-surface tests for the migration features: renames, content
 * drift (modify), FK/CHECK constraint operations, and the SQLite rebuild
 * sequence — one compile test per dialect capability, mirroring the
 * existing CHECK/deferrable test patterns.
 */
final class MigrationFeaturesTest extends DatabaseTestCase
{
    /**
     * The SQLite schema grammar under test.
     *
     * @var SqliteSchemaGrammar
     */
    private SqliteSchemaGrammar $sqlite;

    /**
     * The MySQL schema grammar under test.
     *
     * @var MySqlSchemaGrammar
     */
    private MySqlSchemaGrammar $mysql;

    /**
     * The Postgres schema grammar under test.
     *
     * @var PostgresSchemaGrammar
     */
    private PostgresSchemaGrammar $postgres;

    /**
     * Build the grammars once.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->sqlite = new SqliteSchemaGrammar();
        $this->mysql = new MySqlSchemaGrammar();
        $this->postgres = new PostgresSchemaGrammar();
    }

    /**
     * compileRenameTable renders the portable form per dialect.
     */
    public function testCompileRenameTablePerDialect(): void
    {
        self::assertSame(
            'ALTER TABLE "sync_posts" RENAME TO "sync_articles"',
            $this->sqlite->compileRenameTable('sync_posts', 'sync_articles'),
        );
        self::assertSame(
            'ALTER TABLE `sync_posts` RENAME TO `sync_articles`',
            $this->mysql->compileRenameTable('sync_posts', 'sync_articles'),
        );
        self::assertSame(
            'ALTER TABLE "sync_posts" RENAME TO "sync_articles"',
            $this->postgres->compileRenameTable('sync_posts', 'sync_articles'),
        );
    }

    /**
     * compileRenameColumn renders the shared RENAME COLUMN form.
     */
    public function testCompileRenameColumnPerDialect(): void
    {
        self::assertSame(
            'ALTER TABLE "users" RENAME COLUMN "name" TO "full_name"',
            $this->sqlite->compileRenameColumn('users', 'name', 'full_name'),
        );
        self::assertSame(
            'ALTER TABLE `users` RENAME COLUMN `name` TO `full_name`',
            $this->mysql->compileRenameColumn('users', 'name', 'full_name'),
        );
        self::assertSame(
            'ALTER TABLE "users" RENAME COLUMN "name" TO "full_name"',
            $this->postgres->compileRenameColumn('users', 'name', 'full_name'),
        );
    }

    /**
     * compileCopyTable renders the INSERT..SELECT projection.
     */
    public function testCompileCopyTable(): void
    {
        self::assertSame(
            'INSERT INTO "users__radiant_new" ("id", "name") SELECT "id", "name" FROM "users"',
            $this->sqlite->compileCopyTable('users', 'users__radiant_new', ['id', 'name']),
        );
    }

    /**
     * compileCopyTable rejects an empty column list.
     */
    public function testCompileCopyTableRejectsEmptyColumns(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('requires at least one column');
        $this->sqlite->compileCopyTable('users', 'users__radiant_new', []);
    }

    /**
     * MySQL MODIFY renders the full desired definition per column.
     */
    public function testCompileModifyColumnMySql(): void
    {
        $blueprint = (new Blueprint('users'))
            ->column(ColumnType::String, 'name', length: 100, nullable: false, default: 'unknown');

        self::assertSame(
            ['ALTER TABLE `users` MODIFY `name` varchar(100) NOT NULL DEFAULT \'unknown\''],
            $this->mysql->compileModifyColumn($blueprint),
        );
    }

    /**
     * Postgres splits the facets into separate ALTER COLUMN statements,
     * in the fixed order TYPE → NOT NULL → DEFAULT. The TYPE carries a
     * USING clause so non-implicit casts convert the existing values.
     */
    public function testCompileModifyColumnPostgres(): void
    {
        $blueprint = (new Blueprint('users'))
            ->column(ColumnType::String, 'name', length: 100, nullable: false, default: 'unknown');

        self::assertSame(
            [
                'ALTER TABLE "users" ALTER COLUMN "name" TYPE varchar(100) USING "name"::varchar(100)',
                'ALTER TABLE "users" ALTER COLUMN "name" SET NOT NULL',
                'ALTER TABLE "users" ALTER COLUMN "name" SET DEFAULT \'unknown\'',
            ],
            $this->postgres->compileModifyColumn($blueprint),
        );
    }

    /**
     * A Postgres Decimal modify renders its full shape — precision and
     * scale — so the live column matches the declared type and the next
     * diff converges (no perpetual drift).
     */
    public function testCompileModifyColumnPostgresDecimalKeepsShape(): void
    {
        $blueprint = (new Blueprint('orders'))
            ->column(ColumnType::Decimal, 'total_amount', precision: 10, scale: 2);

        self::assertSame(
            [
                'ALTER TABLE "orders" ALTER COLUMN "total_amount" TYPE numeric(10,2) USING "total_amount"::numeric(10,2)',
                'ALTER TABLE "orders" ALTER COLUMN "total_amount" SET NOT NULL',
                'ALTER TABLE "orders" ALTER COLUMN "total_amount" DROP DEFAULT',
            ],
            $this->postgres->compileModifyColumn($blueprint),
        );
    }

    /**
     * SQLite has no in-place modify — the base throw stands (the
     * connection routes the change through the rebuild instead).
     */
    public function testCompileModifyColumnSqliteThrows(): void
    {
        $blueprint = (new Blueprint('users'))
            ->column(ColumnType::String, 'name', length: 100);

        $this->expectException(\BlueprintAU\Radiant\Database\Exceptions\UnsupportedFeatureException::class);
        $this->expectExceptionMessageIsOrContains('requires a table rebuild');
        $this->sqlite->compileModifyColumn($blueprint);
    }

    /**
     * The SQLite rebuild sequence: statement-by-statement, in order,
     * with the PRAGMAs conditional on the FK state.
     */
    public function testCompileRebuildTableSequence(): void
    {
        $desired = (new Blueprint('users'))
            ->id()
            ->column(ColumnType::String, 'name', length: 100, nullable: false);

        $statements = $this->sqlite->compileRebuildTable(
            $desired,
            'users__radiant_new',
            ['id', 'name'],
            foreignKeyConstraintsEnabled: true,
        );

        self::assertSame([
            'PRAGMA foreign_keys = OFF',
            'CREATE TABLE "users__radiant_new" ("id" integer NOT NULL PRIMARY KEY AUTOINCREMENT, '
                . '"name" varchar(100) NOT NULL)',
            'INSERT INTO "users__radiant_new" ("id", "name") SELECT "id", "name" FROM "users"',
            'DROP TABLE "users"',
            'ALTER TABLE "users__radiant_new" RENAME TO "users"',
            'PRAGMA foreign_keys = ON',
        ], $statements);
    }

    /**
     * With FKs uninvolved the PRAGMAs are omitted entirely.
     */
    public function testCompileRebuildTableSkipsPragmasWhenFksOff(): void
    {
        $desired = (new Blueprint('users'))
            ->id()
            ->column(ColumnType::String, 'name', length: 100, nullable: false);

        $statements = $this->sqlite->compileRebuildTable(
            $desired,
            'users__radiant_new',
            ['id', 'name'],
            foreignKeyConstraintsEnabled: false,
        );

        self::assertSame([
            'CREATE TABLE "users__radiant_new" ("id" integer NOT NULL PRIMARY KEY AUTOINCREMENT, '
                . '"name" varchar(100) NOT NULL)',
            'INSERT INTO "users__radiant_new" ("id", "name") SELECT "id", "name" FROM "users"',
            'DROP TABLE "users"',
            'ALTER TABLE "users__radiant_new" RENAME TO "users"',
        ], $statements);
    }

    /**
     * The rebuild's temp CREATE carries the desired FKs and CHECKs (that
     * is how FK/CHECK changes apply on SQLite) but NOT the indexes.
     */
    public function testCompileRebuildTableCarriesConstraintsNotIndexes(): void
    {
        $desired = (new Blueprint('users'))
            ->id()
            ->column(ColumnType::BigInt, 'roleId', foreign: 'roles.id')
            ->check('id > 0', 'positive')
            ->index(null, ['roleId']);

        $statements = $this->sqlite->compileRebuildTable(
            $desired,
            'users__radiant_new',
            ['id', 'roleId'],
            foreignKeyConstraintsEnabled: false,
        );

        $create = $statements[0];

        self::assertStringContainsString('FOREIGN KEY ("roleId") REFERENCES "roles" ("id")', $create);
        // The CHECK name is VERBATIM (check() takes the name as the whole
        // final name — same rule as index()), so the temp CREATE carries
        // the declared name unchanged.
        self::assertStringContainsString('CONSTRAINT "positive" CHECK (id > 0)', $create);
        self::assertStringNotContainsString('CREATE INDEX', $create);
    }

    /**
     * The rebuild's copy projects the INTERSECTION of live and desired
     * columns — a dropped column has nowhere to go in the temp table, an
     * added column has nothing to copy.
     */
    public function testCompileRebuildTableCopiesOnlyTheIntersection(): void
    {
        $desired = (new Blueprint('users'))
            ->id()
            ->column(ColumnType::String, 'name', length: 100);
        $desired->column(ColumnType::BigInt, 'extra', nullable: true); // added

        $statements = $this->sqlite->compileRebuildTable(
            $desired,
            'users__radiant_new',
            ['id', 'name', 'dropped'], // dropped column live
            foreignKeyConstraintsEnabled: false,
        );

        self::assertStringContainsString(
            'INSERT INTO "users__radiant_new" ("id", "name") SELECT "id", "name" FROM "users"',
            $statements[1],
        );
    }

    /**
     * A rebuild whose intersection is empty is refused — it would destroy
     * the table's data.
     */
    public function testCompileRebuildTableRefusesEmptyIntersection(): void
    {
        $desired = (new Blueprint('users'))
            ->id()
            ->column(ColumnType::String, 'brand_new', length: 100);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('would copy no columns');
        $this->sqlite->compileRebuildTable(
            $desired,
            'users__radiant_new',
            ['old_id', 'old_name'],
            foreignKeyConstraintsEnabled: false,
        );
    }

    /**
     * MySQL ADD CONSTRAINT FOREIGN KEY / DROP FOREIGN KEY.
     */
    public function testCompileForeignKeyOpsMySql(): void
    {
        $foreignKey = [
            'columns' => ['author_id'],
            'references' => ['users', 'id'],
            'onDelete' => \BlueprintAU\Radiant\Database\Schema\Enums\ForeignKeyAction::Cascade,
            'onUpdate' => null,
            'deferrable' => false,
            'initiallyDeferred' => false,
        ];

        self::assertSame(
            'ALTER TABLE `posts` ADD CONSTRAINT `posts_author_id_foreign` '
                . 'FOREIGN KEY (`author_id`) REFERENCES `users` (`id`) ON DELETE CASCADE',
            $this->mysql->compileAddForeignKey('posts', $foreignKey, 'posts_author_id_foreign'),
        );
        self::assertSame(
            'ALTER TABLE `posts` DROP FOREIGN KEY `posts_author_id_foreign`',
            $this->mysql->compileDropForeignKey('posts', 'posts_author_id_foreign'),
        );
    }

    /**
     * Postgres ADD CONSTRAINT / DROP CONSTRAINT (covers FKs and CHECKs).
     */
    public function testCompileConstraintOpsPostgres(): void
    {
        $foreignKey = [
            'columns' => ['author_id'],
            'references' => ['users', 'id'],
            'onDelete' => null,
            'onUpdate' => null,
            'deferrable' => false,
            'initiallyDeferred' => false,
        ];

        self::assertSame(
            'ALTER TABLE "posts" ADD CONSTRAINT "posts_author_id_foreign" '
                . 'FOREIGN KEY ("author_id") REFERENCES "users" ("id")',
            $this->postgres->compileAddForeignKey('posts', $foreignKey, 'posts_author_id_foreign'),
        );
        self::assertSame(
            'ALTER TABLE "posts" DROP CONSTRAINT "posts_author_id_foreign"',
            $this->postgres->compileDropForeignKey('posts', 'posts_author_id_foreign'),
        );
        self::assertSame(
            'ALTER TABLE "users" ADD CONSTRAINT "users_age_check" CHECK (age >= 18)',
            $this->postgres->compileAddCheck('users', 'users_age_check', 'age >= 18'),
        );
        self::assertSame(
            'ALTER TABLE "users" DROP CONSTRAINT "users_age_check"',
            $this->postgres->compileDropCheck('users', 'users_age_check'),
        );
    }

    /**
     * SQLite has no in-place constraint ALTER — all four ops throw at
     * the grammar level (the connection routes through the rebuild).
     */
    public function testCompileConstraintOpsSqliteThrow(): void
    {
        $foreignKey = [
            'columns' => ['author_id'],
            'references' => ['users', 'id'],
            'onDelete' => null,
            'onUpdate' => null,
            'deferrable' => false,
            'initiallyDeferred' => false,
        ];

        foreach (
            [
                fn() => $this->sqlite->compileAddForeignKey('posts', $foreignKey, 'posts_author_id_foreign'),
                fn() => $this->sqlite->compileDropForeignKey('posts', 'posts_author_id_foreign'),
                fn() => $this->sqlite->compileAddCheck('users', 'users_age_check', 'age >= 18'),
                fn() => $this->sqlite->compileDropCheck('users', 'users_age_check'),
            ] as $compile
        ) {
            Expectation::throwsWithMessage(
                $compile,
                \BlueprintAU\Radiant\Database\Exceptions\UnsupportedFeatureException::class,
                'table rebuild',
            );
        }
    }

    /**
     * MySQL has no named-CHECK drop — it inherits the base throw.
     */
    public function testCompileDropCheckMySqlThrows(): void
    {
        $this->expectException(\BlueprintAU\Radiant\Database\Exceptions\UnsupportedFeatureException::class);
        $this->expectExceptionMessageIsOrContains('table rebuild');
        $this->mysql->compileDropCheck('users', 'users_age_check');
    }
}
