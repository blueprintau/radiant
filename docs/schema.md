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
into a blueprint (a `SoftDeletes` model's delete column and a
`Timestamps` model's stamp columns are included), so your models are the
single source of truth:

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

### Datetime precision

Datetime columns accept a fractional-seconds precision (1–6 digits).
Without one, the column stores whole seconds and any sub-second part of
a written value is truncated:

```php
$blueprint = (new Blueprint('api_calls'))
    ->id()
    ->timestamp('started_at', 3)   // datetime(3) on MySQL — milliseconds
    ->timestamps(3)                // NOT NULL created_at + updated_at, precision 3
    ->timestamps(null, nullable: true)   // nullable stamps, whole seconds
    ->softDeletes(3);              // nullable deleted_at, precision 3
```

The helpers: `timestamp($name, $precision)` / `datetime($name,
$precision)` (aliases), `timestamps($precision, $nullable)` (the
`created_at`/`updated_at` pair — NOT NULL by default, pass
`nullable: true` for nullable stamps), and `softDeletes($precision)`
(a nullable `deleted_at` — a row exists before it is deleted, so it is
always nullable). The same `precision:` is accepted by the `#[Column]`
attribute; precision on an int Unix-timestamp column is a fail-fast
error (Unix timestamps are whole seconds).

Per dialect the precision renders as `datetime(3)` (MySQL),
`timestamp(3)` (Postgres — a display hint; microseconds are always
stored natively), and `datetime(3)` (SQLite — rendered for DDL parity;
type affinity ignores it). Values on a precision column are written in
UTC with exactly the declared number of fractional digits, so a
`datetime(3)` column round-trips milliseconds losslessly. Schema sync
detects a precision change (`datetime` → `datetime(3)`) as a column
modify.

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

A declared **table** rename is the starting point of the table's diff,
not the end of it: the columns the rename carries over (the old table's
live shape) are diffed against the desired shape, so any column drift —
adds, modifies, drops — is emitted in the **same plan**, after the
rename and targeting the new table name. Applying the plan leaves the
schema fully in sync; a second `plan()` with a fresh blueprint is empty.
Table and column renames compose too: `renamedFrom()` + `renameColumn()`
+ a shape change sequences `RenameTable` → `RenameColumn` → `ModifyColumn`.

### Content drift: ModifyColumn

A column present on both sides with a changed type, nullability, or
default is detected as a `ModifyColumn` change — destructive when the
change tightens nullability (existing rows may violate the new shape),
non-destructive for a default-only change. The change carries the
**drifted subset** as its `subject` blueprint: MySQL compiles `ALTER
TABLE ... MODIFY` and Postgres the split clauses (`TYPE` / `SET NOT
NULL` / `SET DEFAULT`) for exactly those columns — never the full
desired shape (a full-shape compile would restate the primary key and
re-ALTER unchanged columns). **SQLite has no in-place form**, so the
change routes through a **table rebuild** — the data-preserving
sequence (create temp → copy rows → drop old → rename → re-create
indexes → `foreign_key_check` gate), executed inside a transaction so
a failure rolls the whole rebuild back.

### Adds and drops: separate changes, never merged

A diff that adds some columns and drops others on one table emits
**two changes** — an `AddColumn` then a `DropColumn` — never one
merged alter. A merged record would dispatch only its dominant
operation and silently lose the other side. Both halves carry the
`possibleRename` advisory when the add+drop shape looks like a rename.

On SQLite an `AddColumn` normally applies in place (one `ADD COLUMN`
statement per column — SQLite's `ALTER TABLE` accepts a single
clause). A **NOT NULL column without a default** cannot be added in
place to a non-empty table, so that add routes through the table
rebuild, which backfills the existing rows. SQLite 3.35+ drops
columns natively, so a `DropColumn` applies in place.

#### Backfilling existing rows

An added NOT NULL column needs a value for the rows that already
exist. The framework never guesses one: it comes from the blueprint's
explicit `$blueprint->backfill('column', $value)`, else the column's
declared default — a NOT NULL added column with **neither** fails
fast at compile time. When both are set, `backfill()` wins for the
**existing** rows while the declared default applies to **new** rows.

How the backfill lands depends on the dialect:

- **MySQL / Postgres** — the column is added with the backfill as a
  temporary `DEFAULT` (existing rows get it), then the declared
  default is restored (`SET DEFAULT`) or the temporary one dropped
  (`DROP DEFAULT`). No full-table scan.
- **SQLite (rebuild)** — a NOT NULL add via `apply()` routes through
  the table rebuild, which backfills in the copy projection and
  produces the no-final-default shape in one apply.
- **SQLite (in-place nullable add)** — the column is added, then an
  `UPDATE ... SET col = value WHERE col IS NULL` fills the existing
  rows (SQLite cannot `SET`/`DROP DEFAULT` in place).
- **SQLite (in-place NOT NULL add, no default)** — a direct `alter()`
  renders the backfill as a temporary inline `DEFAULT` so the `ADD
  COLUMN` succeeds and fills the existing rows (no separate `UPDATE`).

#### The #[Backfill] attribute

A backfill is a one-time instruction for the rows that exist when the
column is added — not part of the column's permanent shape. So it gets
its own attribute instead of a `#[Column]` param, which would put a
dead value in the model forever:

```php
use BlueprintAU\Radiant\Attributes\Backfill;
use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;

class User extends Model
{
    #[Column(type: ColumnType::String, length: 255, name: 'display_name')]
    #[Backfill('unknown')]
    public string $displayName;
}
```

`#[Backfill]` must ride a property that also declares `#[Column]` (an
orphan fails fast at metadata build — it could never be consumed), and
its value is a scalar or an SQL `Expression`, the same vocabulary
`backfill()` takes. `Blueprint::fromMetadata()` attaches it to the
column, so a model-driven sync (`fromMetadata()` per model →
`plan()` → `apply()`) backfills without hand-building blueprints. The
attribute is consulted only when the column is being added — on a
fresh create it is inert.

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

An optional `onChange` callback fires after each change is applied
successfully — for progress bars and live log lines:

```php
$applied = $synchronizer->sync(
    $desired,
    confirm: fn (SchemaChange $change) => confirmWithUser($change->description),
    onChange: function (SchemaChange $change): void {
        $progress->advance(message: $change->description);
    },
);
```

A throwing `onChange` aborts the run (and rolls it back when
`transactional: true`).

### Two-phase sync: plan() + apply()

A host that wants to *display* the plan before applying it can split the
loop into two phases — the shown plan is then exactly what gets applied,
with no second diff pass:

```php
$conn->withLock(function () use ($synchronizer, $desired): void {
    $plan = $synchronizer->plan($desired);

    foreach ($plan as $change) {
        render($change->description, destructive: $change->destructive);
    }

    if (!confirmDestructive()) {
        return;
    }

    $synchronizer->apply($plan, confirm: ...);
}, 'radiant:schema');
```

`plan()` computes the changes and touches nothing; `apply()` applies
exactly the given changes — no re-diff — with the same confirm gate and
transactional semantics as `sync()`. Neither takes a lock itself: the
caller holds the `'radiant:schema'` lock across the whole flow, which is
also what guarantees no drift between the shown and applied plan.
`sync()` remains the one-shot form (plan + apply under its own lock).

### Protected tables

Both `plan()` and `diff()` accept a `protected` list — tables the host
forbids the plan from ever touching:

```php
$plan = $synchronizer->plan($desired, protected: ['legacy_archive']);
```

Protection lives in the diff, not in a post-plan filter: a protected
table never receives a `DropTable`, and it never participates in the
rename tie — a create with heavy column overlap over a protected table
stays a **plain create** with no `renameOf` link and no "possible
rename" advisory, so a confirm flow can never be invited to rename a
table that must keep its name. A drop-cycle among the *unprotected*
tables orders normally.

Protection is not a freeze: a protected table the desired state
*declares* still diffs its columns, indexes, and constraints —
declaring the table is an explicit statement about its shape, and
protection only forbids removing it or renaming it by inference.

### Additive-only plans

`dropTables: false` widens protection to *every* undeclared live table:

```php
$plan = $synchronizer->plan($desired, dropTables: false);
```

The default is `dropTables: true` — plans converge: applying them
leaves the schema fully in sync, so a forgotten table is *surfaced*
(as a gated drop) rather than silently lingering. Safety lives at the
apply layer — destructive changes throw unless a `confirm` callback
approves them — not in hiding the plan's content. Choose
`dropTables: false` when additive-only *is* the deployment posture.

An additive-only plan emits creates, renames, and alters — never a
`DropTable`. With no drop list the rename tie has nothing to pair
against, so every create stays a plain create; combining
`dropTables: false` with `protected:` is legal but redundant. Column,
index, and foreign-key drops inside alters are untouched shape
correction and still flow (they remain gated by `confirm`), and a
declared `renamedFrom()` rename still applies — a rename is a
decision, not a drop.

An additive-only plan is deliberately **out of sync**: tables the
desired state no longer declares are left in place, not forgotten —
a later `plan()` with drops enabled will surface them again. Use it
for the "create what's missing, touch nothing I didn't declare"
deployment posture; run a full plan when you actually want the
convergence check.

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
