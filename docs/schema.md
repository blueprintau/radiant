# Schema & sync

Tables, indexes, and keeping the live database in step with your models.
Everything here is SQL-only — narrow to a `SqlConnection` first.

- [Blueprints](#blueprints)
- [Creating, altering, dropping](#creating-altering-dropping)
- [Schema sync](#schema-sync)
- [Locking](#locking)

## Blueprints

`Blueprint` describes a table's desired state. `Blueprint::fromMetadata()`
folds every `#[Column]` and class-level constraint attribute of a model
into a blueprint (a `SoftDeletes` model's delete column is included), so
your models are the single source of truth:

```php
use BlueprintAU\Radiant\Database\Schema\Blueprint;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;

/** @var SqlConnection $conn — schema is SQL-only */

$blueprint = (new Blueprint('users'))
    ->id()
    ->column(ColumnType::String, 'email', length: 255, unique: true)
    ->column(ColumnType::String, 'country', length: 2, index: true)
    ->timestamp('created_at')
    ->index('users_country_created', ['country', 'created_at']); // composite
```

Indexes: `Blueprint::index($name, $columns, $unique)` compiles to
`CREATE INDEX` per dialect, and single-column indexes come from a
column's `index:` flag.

### Index options

Two options upgrade an index's *semantics*, and each dialect compiles
what it can — an option the active dialect cannot render fails fast with
an `UnsupportedFeatureException` at compile time, never silently weaker:

```php
// NULLS NOT DISTINCT (Postgres 15+): at most one NULL in a unique index —
// the classic "one active row per user" constraint. MySQL and SQLite
// refuse it (their unique indexes always allow multiple NULLs).
$blueprint->index(null, ['user_id'], unique: true, nullsNotDistinct: true);

// Partial (filtered) index (Postgres, SQLite): only rows matching the
// predicate are indexed. MySQL has no partial indexes and refuses.
$blueprint->index(null, ['email'], unique: true, where: 'accepted_at IS NULL');
```

On Postgres the NULLS clause is rendered **explicitly in both directions**
— a unique index without the option compiles `NULLS DISTINCT`, pinning the
SQL default in the DDL so dumps are self-documenting.

Foreign keys take `deferrable:` / `initiallyDeferred:` (Postgres only —
circular-FK seeding within one transaction), and tables take portable
`CHECK` constraints:

```php
$blueprint->foreignKey(['account_id'], 'accounts', ['id'], deferrable: true, initiallyDeferred: true);
$blueprint->check('price >= 0', 'price_positive'); // named → {table}_{name}_check
```

## Creating, altering, dropping

```php
use BlueprintAU\Radiant\Database\Connections\SqlConnection;
use BlueprintAU\Radiant\Database\Schema\Enums\SchemaOperation;

/** @var SqlConnection $conn */

$conn->create($blueprint);          // CREATE TABLE + every declared index
$conn->alter(SchemaOperation::AddColumn, $blueprint);
$conn->alter(SchemaOperation::DropColumn, $blueprint);
$conn->drop('users');
```

Under the hood each statement has its own compile function on the
dialect's schema grammar (`compileCreate`, `compileAddColumns`,
`compileDropColumns`, `compileDrop`, `compileIndexes`, `compileDropIndex`)
— a feature the dialect cannot express throws
`UnsupportedFeatureException` at compile time, never silently ignored.

## Schema sync

The schema layer ships a **differ**: desired state vs. live schema →
ordered, classified changes. Radiant computes and reports; the host
command (plan → show → apply) decides and acts. The apply loop executes
DDL up to `DROP TABLE` — **always run it under a cross-process lock**
(see [Locking](#locking)) unless your deployment is genuinely
single-instance.

```php
use BlueprintAU\Radiant\Database\Schema\Blueprint;
use BlueprintAU\Radiant\Database\Schema\SchemaDiffer;
use BlueprintAU\Radiant\Database\Connections\SqlConnection;

/** @var SqlConnection $conn */

$desired = [
    Blueprint::fromMetadata(User::class),
    Blueprint::fromMetadata(Post::class),
];

$differ = new SchemaDiffer($conn->schemaInspector); // never construct an inspector yourself

// The whole diff → apply loop runs under a cross-process lock: it reads
// the live schema, then executes DDL including DROP TABLE — two
// overlapping instances would race on stale snapshots. See "Locking"
// below. Single-instance deployments may omit the lock.
$conn->withLock(function () use ($differ, $desired, $conn): void {
    foreach ($differ->diff($desired) as $change) {
        echo $change->description, $change->destructive ? '  [DESTRUCTIVE]' : '', "\n";
        if (!$change->destructive) {
            $conn->apply($change); // show → apply; gate destructive changes behind a confirmation
        }
    }
}, 'radiant:schema');
```

Each returned `SchemaChange` carries the table, the operation
(`CreateTable`/`AddColumn`/`DropColumn`/`DropTable`/`AlterIndexes`/
`RenameTable`/`RenameColumn`/`ModifyColumn`/`AddForeignKey`/
`DropForeignKey`/`AddCheck`/`DropCheck`), a `destructive` flag (anything
that can lose data), and a human-readable `description` for dry-run
output. Changes are ordered **creates → renames → alters → drops**;
creates are **dependency-ordered** (referenced tables first — `posts`
with an FK to `users` is created after `users` even when declared
first), and drops are **reverse-dependency-ordered** (children before
parents). A circular FK dependency fails fast with the cycle path named.

### Renames: declared, not guessed

Rename-shaped diffs are **flagged, never rewritten**: a column add+drop
pair on one table, or a create+drop table pair sharing at least half their
columns, is marked `possibleRename` / `renameOf` so the host can ask "is
this a rename?" — a wrong guess executing `RENAME COLUMN` between
unrelated columns would corrupt data.

To get an **executable** rename, declare it — the declaration is the
decision:

```php
$blueprint = new Blueprint('users');
$blueprint->renamedFrom('legacy_users');   // table rename
$blueprint->renameColumn('name', 'full_name'); // column rename
```

The differ verifies the declaration against the live schema (old exists,
new absent) and emits a real `RenameTable` / `RenameColumn` change —
non-destructive, data travels with the rename. A declaration that does
not match reality falls through to the usual create/drop handling with
the rename reported as a suggestion. A column rename plus a shape change
sequences two changes: `RenameColumn` first, then `ModifyColumn`.

### Content drift: ModifyColumn

A column present on both sides with a changed type, nullability, or
default is detected as a `ModifyColumn` change — destructive when the
change tightens nullability (existing rows may violate the new shape),
non-destructive for a default-only change. MySQL compiles `ALTER TABLE
... MODIFY`; Postgres compiles the split clauses (`TYPE` / `SET NOT
NULL` / `SET DEFAULT`); **SQLite has no in-place form**, so the change
routes through a **table rebuild** — the data-preserving sequence
(create temp → copy rows → drop old → rename → re-create indexes →
`foreign_key_check` gate), executed inside a transaction so a failure
rolls the whole rebuild back.

### FK and CHECK drift

Foreign keys are matched **shape-first**: a live constraint with an
identical shape (columns + references + actions) is in sync regardless
of its name — pre-existing auto-named constraints never churn as
drop+re-add. A declared FK missing live is an `AddForeignKey`; an extra
live FK is a `DropForeignKey` (the live constraint name is the drop
handle). MySQL and Postgres compile in-place `ADD CONSTRAINT` /
`DROP CONSTRAINT`; SQLite routes through the table rebuild.

CHECK constraints diff by name; a same-name different-expression
mismatch is **reported only** — it appears in the description, never
auto-executed.

### The synchronizer

The three-step loop is available as one call —
`BlueprintAU\Radiant\Database\Schema\SchemaSynchronizer`:

```php
use BlueprintAU\Radiant\Database\Schema\SchemaSynchronizer;

$synchronizer = new SchemaSynchronizer($conn);

$applied = $synchronizer->sync(
    $desired,
    confirm: fn (SchemaChange $change) => confirmWithUser($change->description),
);
```

Under the `'radiant:schema'` lock: diff → gate destructive changes
through `$confirm` (null means fail-fast: the first destructive change
throws) → apply in order → return the applied changes. The confirm
callback is the applier's decision point — the same `SchemaChange` data
a CLI consumes directly.

### Remaining limits

A declared index absent from the live table is a deployment gap the
differ does not create. A CHECK whose expression changed but kept its
name is reported as a suggestion, not an executable change. The SQLite
rebuild loses triggers and views on the table (Radiant does not manage
them) and re-seeds `AUTOINCREMENT` from the max rowid. Review the plan
before applying.

## Locking

Any work that must not run twice concurrently needs a cross-process lock —
the schema `diff → apply` loop is the prime case: it reads the live
schema, then executes DDL including `DROP TABLE`, and two overlapping
instances race on stale snapshots.

`SqlConnection::withLock()` takes the dialect's native lock — MySQL
`GET_LOCK` · Postgres advisory lock · SQLite `BEGIN IMMEDIATE` — with the
mutual-exclusion *name* supplied at call time: one name is one lock
domain, so distinct jobs use distinct names and never wait on each
other. The schema-sync convention is the name `'radiant:schema'`:

```php
$conn->withLock(function () use ($differ, $desired, $conn): void {
    foreach ($differ->diff($desired) as $change) {
        $conn->apply($change);
    }
}, 'radiant:schema');
```

The same gate covers any other serialized work — cron jobs that must not
overlap, cache warmups:

```php
$conn->withLock(fn () => $this->buildNightlyReport(), 'report:nightly');
```

For custom locking (a locker service, file locks across machines),
implement `BlueprintAU\Radiant\Database\Locks\Lock` and call
`->withLock($callback, $name)` around the critical section. Without any
lock, schema sync is safe only for single-instance deployments.
