<?php

declare(strict_types=1);

namespace BlueprintAU\Radiant\Tests\Unit\Schema;

use BlueprintAU\Radiant\Database\Query\Expression;
use BlueprintAU\Radiant\Database\Schema\Blueprint;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use BlueprintAU\Radiant\Database\Schema\Grammars\MySqlSchemaGrammar;
use BlueprintAU\Radiant\Database\Schema\Grammars\PostgresSchemaGrammar;
use BlueprintAU\Radiant\Database\Schema\Grammars\SqliteSchemaGrammar;
use PHPUnit\Framework\TestCase;

/**
 * Grammar-level backfill compilation: the scalar temporary-DEFAULT
 * optimization stays guarded, and an Expression backfill never renders
 * inside a DEFAULT clause — it compiles as a post-add UPDATE (nullable
 * add) or the nullable-add → UPDATE → SET NOT NULL route (NOT NULL add).
 */
final class BackfillCompilationTest extends TestCase
{
    /**
     * MySQL: a scalar backfill keeps the temporary-DEFAULT path — the
     * no-scan optimization stays for constants.
     */
    public function testMySqlScalarBackfillKeepsTemporaryDefault(): void
    {
        $blueprint = (new Blueprint('medias'))
            ->column(ColumnType::String, 'label', length: 64)
            ->backfill('label', 'unknown');

        $statements = (new MySqlSchemaGrammar())->compileAddColumns($blueprint);

        self::assertCount(2, $statements);
        self::assertStringContainsString("ADD COLUMN `label` varchar(64) NOT NULL DEFAULT 'unknown'", $statements[0]);
        self::assertSame('ALTER TABLE `medias` ALTER COLUMN `label` DROP DEFAULT', $statements[1]);
    }

    /**
     * MySQL: an Expression backfill compiles as a post-add UPDATE with
     * the expression verbatim — never inside a DEFAULT (regression for
     * the silent-wrong-DEFAULT case; a DEFAULT cannot reference other
     * columns).
     */
    public function testMySqlExpressionBackfillCompilesPostAddUpdate(): void
    {
        $blueprint = (new Blueprint('medias'))
            ->column(ColumnType::String, 'slug', length: 128, nullable: true)
            ->backfill('slug', new Expression("`title` || '-suffix'"));

        $statements = (new MySqlSchemaGrammar())->compileAddColumns($blueprint);

        self::assertCount(2, $statements);
        self::assertStringContainsString('ADD COLUMN `slug` varchar(128)', $statements[0]);
        self::assertStringNotContainsString('DEFAULT', $statements[0]);
        self::assertSame("UPDATE `medias` SET `slug` = `title` || '-suffix'", $statements[1]);
    }

    /**
     * MySQL: a NOT NULL add with an Expression backfill takes the
     * nullable-add → unscoped UPDATE → SET NOT NULL route — a bare NOT
     * NULL add would fail on a non-empty table in Postgres and strict-
     * mode MySQL, so the portable route never relies on an implicit fill.
     */
    public function testMySqlExpressionBackfillOnNotNullAddTightensAfterFill(): void
    {
        $blueprint = (new Blueprint('medias'))
            ->column(ColumnType::String, 'title', length: 255)
            ->column(ColumnType::String, 'slug', length: 128)
            ->backfill('slug', new Expression("`title` || '-suffix'"));

        $statements = (new MySqlSchemaGrammar())->compileAddColumns($blueprint);

        self::assertCount(3, $statements);
        self::assertStringContainsString('ADD COLUMN `slug` varchar(128)', $statements[0]);
        self::assertStringNotContainsString('NOT NULL DEFAULT', $statements[0]);
        self::assertSame("UPDATE `medias` SET `slug` = `title` || '-suffix'", $statements[1]);
        self::assertSame('ALTER TABLE `medias` ALTER COLUMN `slug` SET NOT NULL', $statements[2]);
    }

    /**
     * Postgres: a scalar backfill keeps the temporary-DEFAULT path.
     */
    public function testPostgresScalarBackfillKeepsTemporaryDefault(): void
    {
        $blueprint = (new Blueprint('medias'))
            ->column(ColumnType::String, 'label', length: 64)
            ->backfill('label', 'unknown');

        $statements = (new PostgresSchemaGrammar())->compileAddColumns($blueprint);

        self::assertCount(2, $statements);
        self::assertStringContainsString("ADD COLUMN \"label\" varchar(64) NOT NULL DEFAULT 'unknown'", $statements[0]);
        self::assertSame('ALTER TABLE "medias" ALTER COLUMN "label" DROP DEFAULT', $statements[1]);
    }

    /**
     * Postgres: an Expression backfill compiles as a post-add UPDATE —
     * the silent-wrong-DEFAULT regression case.
     */
    public function testPostgresExpressionBackfillCompilesPostAddUpdate(): void
    {
        $blueprint = (new Blueprint('medias'))
            ->column(ColumnType::String, 'slug', length: 128, nullable: true)
            ->backfill('slug', new Expression("\"title\" || '-suffix'"));

        $statements = (new PostgresSchemaGrammar())->compileAddColumns($blueprint);

        self::assertCount(2, $statements);
        self::assertStringContainsString('ADD COLUMN "slug" varchar(128)', $statements[0]);
        self::assertStringNotContainsString('DEFAULT', $statements[0]);
        self::assertSame("UPDATE \"medias\" SET \"slug\" = \"title\" || '-suffix'", $statements[1]);
    }

    /**
     * Postgres: NOT NULL + Expression takes the fill-then-tighten route —
     * the bare NOT NULL add would error on a non-empty table before any
     * fill could run.
     */
    public function testPostgresExpressionBackfillOnNotNullAddTightensAfterFill(): void
    {
        $blueprint = (new Blueprint('medias'))
            ->column(ColumnType::String, 'title', length: 255)
            ->column(ColumnType::String, 'slug', length: 128)
            ->backfill('slug', new Expression("\"title\" || '-suffix'"));

        $statements = (new PostgresSchemaGrammar())->compileAddColumns($blueprint);

        self::assertCount(3, $statements);
        self::assertSame("UPDATE \"medias\" SET \"slug\" = \"title\" || '-suffix'", $statements[1]);
        self::assertSame('ALTER TABLE "medias" ALTER COLUMN "slug" SET NOT NULL', $statements[2]);
    }

    /**
     * SQLite: the in-place nullable add renders the Expression backfill
     * in the guarded UPDATE — row-wise, like the scalar.
     */
    public function testSqliteNullableAddExpressionBackfillCompilesGuardedUpdate(): void
    {
        $blueprint = (new Blueprint('medias'))
            ->column(ColumnType::String, 'slug', length: 128, nullable: true)
            ->backfill('slug', new Expression("\"title\" || '-suffix'"));

        $statements = (new SqliteSchemaGrammar())->compileAddColumns($blueprint);

        self::assertCount(2, $statements);
        self::assertStringContainsString('ADD COLUMN "slug" varchar(128)', $statements[0]);
        self::assertSame("UPDATE \"medias\" SET \"slug\" = \"title\" || '-suffix' WHERE \"slug\" IS NULL", $statements[1]);
    }

    /**
     * SQLite: the rebuild's copy projection inlines an Expression
     * backfill row-wise in the SELECT list — never as a (non-constant,
     * invalid) DEFAULT in the CREATE TABLE.
     */
    public function testSqliteRebuildInlinesExpressionBackfillRowWise(): void
    {
        $desired = (new Blueprint('medias'))
            ->column(ColumnType::String, 'title', length: 255)
            ->column(ColumnType::String, 'slug', length: 128)
            ->backfill('slug', new Expression("\"title\" || '-suffix'"));

        $statements = (new SqliteSchemaGrammar())->compileRebuildTable($desired, 'medias_new', ['title'], true);

        $create = $statements[1];
        $copy = $statements[2];

        self::assertStringContainsString('CREATE TABLE "medias_new"', $create);
        // The CREATE carries no DEFAULT for slug; the copy projects the
        // expression per row.
        self::assertStringNotContainsString('DEFAULT', $create);
        self::assertStringContainsString("SELECT \"title\", \"title\" || '-suffix' FROM \"medias\"", $copy);
    }

    /**
     * A tightening MODIFY with a scalar backfill emits the guarded
     * UPDATE ahead of the ALTER — the rows get filled before the shape
     * rejects them.
     */
    public function testMySqlTightenModifyEmitsGuardedUpdateFirst(): void
    {
        $blueprint = (new Blueprint('medias'))
            ->column(ColumnType::String, 'slug', length: 128)
            ->backfill('slug', 'unknown');

        $statements = (new MySqlSchemaGrammar())->compileModifyColumn($blueprint);

        self::assertCount(2, $statements);
        self::assertSame("UPDATE `medias` SET `slug` = 'unknown' WHERE `slug` IS NULL", $statements[0]);
        self::assertStringContainsString('ALTER TABLE `medias` MODIFY `slug`', $statements[1]);
    }

    /**
     * A tightening MODIFY with an Expression backfill emits the guarded
     * row-wise UPDATE ahead of the ALTER.
     */
    public function testPostgresTightenModifyEmitsExpressionUpdateFirst(): void
    {
        $blueprint = (new Blueprint('medias'))
            ->column(ColumnType::String, 'slug', length: 128)
            ->backfill('slug', new Expression("\"title\" || '-suffix'"));

        $statements = (new PostgresSchemaGrammar())->compileModifyColumn($blueprint);

        self::assertSame("UPDATE \"medias\" SET \"slug\" = \"title\" || '-suffix' WHERE \"slug\" IS NULL", $statements[0]);
        self::assertStringContainsString('ALTER COLUMN "slug" TYPE', $statements[1]);
        self::assertStringContainsString('SET NOT NULL', $statements[2]);
    }

    /**
     * The SQLite rebuild folds a tighten backfill into the copy
     * projection as a COALESCE — the rebuilt table holds no NULL in the
     * tightened column.
     */
    public function testSqliteRebuildCoalescesTightenBackfill(): void
    {
        $desired = (new Blueprint('medias'))
            ->column(ColumnType::String, 'title', length: 255)
            ->column(ColumnType::String, 'slug', length: 128)
            ->backfill('slug', 'unknown');

        // `slug` exists live — a tighten, not an add.
        $statements = (new SqliteSchemaGrammar())->compileRebuildTable($desired, 'medias_new', ['title', 'slug'], true);

        $copy = $statements[2];

        self::assertStringContainsString("SELECT \"title\", COALESCE(\"slug\", 'unknown') FROM \"medias\"", $copy);
    }
}
