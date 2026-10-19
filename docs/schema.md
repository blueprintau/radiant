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
$blueprint->check('price >= 0', 'price_positive'); // named — used verbatim
$blueprint->check('stock >= 0'); // unnamed — derived: {table}_{columns}_check
```

Omitting `onDelete:` / `onUpdate:` compiles no action clause, so the SQL
standard applies: **`NO ACTION`** — a delete or update touching referenced
rows fails (MySQL's InnoDB enforces it identically to `RESTRICT`). This is
the deliberate fail-safe posture, mirroring `#[MorphDelete]`'s default:
referential cleanup must be declared, never assumed.

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
(`CreateTable`/`AddColumn`/`DropColumn`/`DropTable`/`AddIndex`/
`AlterIndexes`/`RenameTable`/`RenameColumn`/`ModifyColumn`/`AddForeignKey`/
`DropForeignKey`/`AddCheck`/`DropCheck`), a `destructive` flag (anything
that can lose data), and a human-readable `description` for dry-run
output. Modify and index-drift descriptions carry the drift **detail** —
per column, the facets that changed with old → new values (type,
nullability, default, enum values), e.g.
`modify column(s) on [users]: [age (int -> bigint), status (nullable -> not null), role (enum values changed: ['admin', 'member'])]`;
per index, the option that changed (uniqueness / partial predicate /
`NULLS NOT DISTINCT`), e.g. `rebuild [idx (where: none -> 'email IS NOT NULL')]`.
Changes are ordered **creates → renames → alters → drops**;
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
non-destructive, data travels with the rename. A declared **table**
rename that does not match reality fails fast — the differ throws rather
than falling through to a create/drop plan. A declared **column** rename
whose declaration does not match reality is silently ignored for the
diff (the rename simply does not apply). A column rename plus a shape
change sequences two changes: `RenameColumn` first, then
`ModifyColumn`.

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
indexes → `foreign_key_check` gate), executed inside its own
transaction so a failure rolls the whole rebuild back. A rebuild whose
table is FK-involved (the table declares foreign keys, or other tables
reference it) needs the `PRAGMA foreign_keys` toggle around that
transaction — and that toggle is a no-op inside a transaction, so a
rebuild that needs it must run as its own standalone transaction; see
"Atomicity vs SQLite rebuilds" below.

### Adds and drops: separate changes, never merged

A diff that adds some columns and drops others on one table emits
**two changes** — an `AddColumn` then a `DropColumn` — never one
merged alter. A merged record would dispatch only its dominant
operation and silently lose the other side. Both halves carry the
`possibleRename` advisory when the add+drop shape looks like a rename;
the destructive drop half also appends a **draft data-move skeleton** —
the guarded UPDATEs (one per dropped column, every added column an
`'<EDIT>'` placeholder) and the verification query the user edits,
runs between the ADD and the DROP, and verifies before applying. It is
output-only guidance: the framework cannot verify the transform's
semantics, so it drafts and never executes.

On SQLite an `AddColumn` normally applies in place (one `ADD COLUMN`
statement per column — SQLite's `ALTER TABLE` accepts a single
clause). A **NOT NULL column without a default** cannot be added in
place to a non-empty table, so that add routes through the table
rebuild, which backfills the existing rows. SQLite 3.35+ drops
columns natively, so a `DropColumn` applies in place — **unless a
foreign key on the table references one of the dropped columns**.
SQLite's native `DROP COLUMN` validates the remaining schema after the
drop and fails when any surviving FK definition still names the column
(regardless of `foreign_keys` enforcement), so an FK-referenced drop
routes through the table rebuild instead; the rebuild renders the full
desired shape and any FK declared on a dropped column vanishes with it.

#### Backfilling existing rows

An added NOT NULL column needs a value for the rows that already
exist. The framework never guesses one: it comes from the blueprint's
explicit `$blueprint->backfill('column', $value)`, else the column's
declared default — a NOT NULL added column with **neither** fails
fast at compile time. When both are set, `backfill()` wins for the
**existing** rows while the declared default applies to **new** rows.

How the backfill lands depends on the dialect and the value's kind — a
**scalar** can ride a `DEFAULT`, an SQL `Expression` **cannot** (a
`DEFAULT` never references other columns in any dialect, and
referencing them is the entire reason to use an Expression):

- **MySQL / Postgres, scalar** — the column is added with the backfill
  as a temporary `DEFAULT` (existing rows get it), then the declared
  default is restored (`SET DEFAULT`) or the temporary one dropped
  (`DROP DEFAULT`). No full-table scan.
- **MySQL / Postgres, Expression** — never a `DEFAULT`. The column is
  added nullable, an `UPDATE ... SET col = <expr>` fills every row
  (unscoped — the column is brand-new), then the declared shape is
  restored with `SET NOT NULL`. A bare `ADD COLUMN ... NOT NULL` would
  fail on a non-empty table in Postgres and strict-mode MySQL, so the
  fill must run before the tighten, not after the add.
- **SQLite (rebuild)** — a NOT NULL add via `apply()` routes through
  the table rebuild, which inlines the backfill (scalar or Expression)
  row-wise in the copy projection and produces the no-final-default
  shape in one apply.
- **SQLite (in-place nullable add)** — the column is added, then an
  `UPDATE ... SET col = value WHERE col IS NULL` fills the existing
  rows (SQLite cannot `SET`/`DROP DEFAULT` in place). Scalar and
  Expression values alike evaluate row-wise here.
- **SQLite (in-place NOT NULL add, no default)** — a direct `alter()`
  renders the scalar backfill as a temporary inline `DEFAULT` so the
  `ADD COLUMN` succeeds and fills the existing rows (no separate
  `UPDATE`). An Expression takes the rebuild route instead — a
  non-constant DEFAULT is invalid.

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
`plan()` → `apply()`) backfills without hand-building blueprints. On a
fresh create it is inert.

#### Backfilling a nullability tighten

A `nullable → NOT NULL` tighten is a `ModifyColumn` — exactly the
"existing rows may violate the new shape" case the differ flags
DESTRUCTIVE. A declared backfill fills those rows first: the compiled
change emits `UPDATE <table> SET col = <value> WHERE col IS NULL`
BEFORE the alter (scalar or Expression — row-wise by nature on this
path). On SQLite the fold happens inside the rebuild's copy projection
as `COALESCE(col, <value>)`.

The change **stays flagged destructive** — rows may violate other
constraints, and the fill itself mutates data — so the confirmation
gate is not bypassed; the differ's description names the escape hatch:

```
modify column(s) on [users]: [status (nullable -> not null)] — DESTRUCTIVE:
existing rows may violate the new shape; a #[Backfill] /
Blueprint::backfill() on the column fills the violating rows before the tighten
```

### Data steps

A backfill fills every row with **one value**. A real migration often
needs a *transform* — the motivating case: legacy FK columns
(`post_id`/`event_id`/`profile_id`) become a polymorphic pair, and each
row's `attachable_type` depends on which FK was set. That is undecidable
for a framework, so the framework never guesses it — the transform is
yours, and it has a first-class home:

```
plan the ADD  →  run your data step  →  plan the DROP
```

The step is a `SchemaOperation::DataStep` change — hand-authored, never
differ-generated, never auto-ordered:

```php
use BlueprintAU\Radiant\Database\Schema\Enums\SchemaOperation;
use BlueprintAU\Radiant\Database\Schema\SchemaChange;

$step = new SchemaChange(
    'medias',
    SchemaOperation::DataStep,
    new Blueprint('medias'),
    true, // destructive by default — a data step mutates rows.
    'data step [morph-fill]: 3 statement(s)',
    statements: [
        "UPDATE medias SET attachable_type = 'App\\\\Models\\\\Post', attachable_id = post_id WHERE post_id IS NOT NULL",
        "UPDATE medias SET attachable_type = 'App\\\\Models\\\\Event', attachable_id = event_id WHERE event_id IS NOT NULL",
        "UPDATE medias SET attachable_type = 'App\\\\Models\\\\Profile', attachable_id = profile_id WHERE profile_id IS NOT NULL",
    ],
);
```

The contract:

- Each element of `statements` is **one statement** — the framework
  never splits strings. (Lucent's `sync --step <file>` splits a file on
  `;\n` before building the change; that is the command's job, not the
  connection's.)
- Destructive by default, so the confirmation gate covers it; an
  insert-only step passes `destructive: false` to opt out.
- Applied by `SqlConnection::apply()` like any other change — inside
  whatever lock/transaction discipline the plan runs under.
- The differ **never** emits a `DataStep`, and nothing orders them for
  you: you author, place, and own every data step between the plan's
  ADD and its DROP.

#### Callable data steps

The SQL step carries its transform as **plan data** — printable,
diffable, reviewable without reading any other file. That visibility is
the default for a reason, and it stays the default. But some transforms
have their intelligence in PHP, and forcing it into SQL strings buys
four problems: morph aliases are full PHP class-strings whose inlined
backslashes are dialect-dependent (MySQL eats one unless
`NO_BACKSLASH_ESCAPES`; SQLite keeps it), hand-built SQL interpolates
everything (every user-derived value becomes a quoting bug or an
injection surface), veto gates need dialect forks (`RAISE` is
SQLite-trigger-only, `SIGNAL` is MySQL-only, division-by-zero is
silently NULL on SQLite), and a `.sql` step is only ever diff-reviewed —
never unit-tested.

For those, the callable sibling:

```php
use BlueprintAU\Radiant\Database\Schema\DataStep;

$step = new DataStep(
    'medias',
    'morph backfill: legacy FK columns → attachable pair, gated on orphans',
    true, // explicit — no default-true magic.
    function (\BlueprintAU\Radiant\Database\Connections\SqlConnection $conn): void {
        foreach ([Post::class => 'post_id', Event::class => 'event_id'] as $class => $legacy) {
            $conn->statement(
                "UPDATE medias SET attachable_type = ?, attachable_id = {$legacy}
                 WHERE {$legacy} IS NOT NULL AND attachable_id IS NULL",
                [$class], // bound — the codec owns encoding; the class-string is never inlined.
            );
        }

        $violations = $conn->selectSql('SELECT count(*) AS violations FROM medias WHERE attachable_id IS NULL')->first();

        if ($violations !== null && (int) $violations->violations > 0) {
            throw new \DomainException("morph backfill gate: {$violations->violations} violating rows");
        }
    },
);
```

The contract mirrors the SQL step exactly: `$table` is a display and
lock-scoping label; `$destructive` is read by the confirmation gate
identically to a `SchemaChange`'s flag; the closure runs through the
same `SqlConnection::apply()` dispatch — same lock, same transaction
discipline — and **a throw is a veto**: under a transactional apply the
whole plan rolls back, with the identical semantics a failing statement
already has. Gates are plain PHP conditionals now; delete the sentinel
CHECK tables and the `RAISE`/`SIGNAL` forks.

The division:

- **Pure row transforms** → SQL step (visible, diffable — reviewers who
  didn't write it read the actual statements).
- **Logic-dependent transforms** — PHP class-string constants, bound
  writes, conditionals, exceptions-as-gates → callable step (testable,
  escape-free, description-only visibility).

That last clause is the honest cost: a dry run can show **only** the
description for a callable step. Review happens at the definition site,
not the plan — write `$description` for the person who will read it in
a `--dry-run` months later, and keep callable steps small enough that
the site is readable. Interleaving is positional: callables and SQL
steps sit in one plan list in authored order, gated and applied one by
one. Callable steps never defer past a SQLite standalone-transaction
rebuild (they carry no analyzable operation) — if a plan mixes the two,
the deferred rebuild still runs after the lock closes, exactly as it
does around other changes.

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

### Atomicity vs SQLite rebuilds

A `transactional: true` apply is one transaction around the whole
loop — but on SQLite a change that routes through the table rebuild
with FK involvement cannot live inside it: the rebuild toggles
`PRAGMA foreign_keys`, and that PRAGMA is a no-op inside a transaction
(SQLite semantics — the toggle must run outside any transaction). The
synchronizer detects such a change (via
`SqlConnection::changeRequiresStandaloneTransaction()`, overridden on
`SqliteConnection`) and **degrades the apply to non-transactional**:

- every change that does not need the toggle still applies normally;
- the rebuild itself keeps its own atomicity — its internal
  transaction, the `foreign_key_check` gate (any FK violation rolls
  the whole rebuild back, leaving the table untouched), and the PRAGMA
  restore on both success and failure paths;
- `sync()` plans and applies under the `'radiant:schema'` lock — one
  cross-process section. When the plan carries an FK-involved rebuild,
  the apply defers until after the lock transaction closes (the lock
  is itself a transaction on SQLite, and the PRAGMA toggle cannot run
  inside any transaction). A two-phase plan→apply host must do the
  same: hold the lock for the plan, release it, then apply.

The trade-off, stated plainly: a multi-change plan containing an
FK-involved rebuild is atomic **per change**, not across the whole
plan. A later change can still fail after the rebuild committed. The
plan-read window between lock release and apply is fenced by the
rebuild itself — it re-reads the live table per change, and its
`foreign_key_check` gate fails loud on drift it cannot reconcile. On
Postgres — and on SQLite plans without an FK-involved rebuild — the
apply stays under the lock and `transactional: true` remains
all-or-nothing. MySQL has no transactional DDL: requesting
`transactional: true` there fails fast with a `LogicException` instead
of silently degrading.

`PRAGMA defer_foreign_keys` is not a workaround: deferral postpones
the constraint *check*, but the `ON DELETE CASCADE` actions still fire
on the rebuild's implicit `DELETE FROM` during `DROP TABLE` — child
rows would still be lost.

### Two-phase sync: plan() + apply()

A host that wants to *display* the plan before applying it can split the
loop into two phases — the shown plan is then exactly what gets applied,
with no second diff pass:

```php
$plan = $conn->withLock(function () use ($synchronizer, $desired): array {
    $plan = $synchronizer->plan($desired);

    foreach ($plan as $change) {
        render($change->description, destructive: $change->destructive);
    }

    return confirmDestructive() ? $plan : [];
}, 'radiant:schema');

if ($plan !== []) {
    $synchronizer->apply($plan, confirm: ...);
}
```

`plan()` computes the changes and touches nothing; `apply()` applies
exactly the given changes — no re-diff — with the same confirm gate and
transactional semantics as `sync()`. Neither takes a lock itself: the
caller takes the `'radiant:schema'` lock around the plan phase, which
is what guarantees no drift between the shown and applied plan. The
apply runs AFTER the lock releases — on SQLite, a plan containing an
FK-involved rebuild cannot run inside the lock's transaction (see
"Atomicity vs SQLite rebuilds" above). `sync()` is the one-shot form:
plan and apply under its own lock, deferring the apply only when a
rebuild in the plan requires it.

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

Removing an inline `unique: true` column flag is not detected as drift
(the constraint rides the column shape, not the index list — drop it by
hand if intended). Primary-key changes are not diffed (a PK change is a
table rebuild with no in-place form). A live index the blueprint does
NOT declare is preserved — an out-of-band/DBA index is never dropped. A
CHECK whose expression changed but kept its name is reported as a
suggestion, not an executable change. The SQLite rebuild loses triggers
and views on the table (Radiant does not manage them) and re-seeds
`AUTOINCREMENT` from the max rowid. Review the plan before applying.

### Declared-but-missing indexes: AddIndex

An index declared on a blueprint whose name has no live counterpart is
ONE additive, non-destructive `AddIndex` change (all missing indexes on
a table batch into one change, one `CREATE INDEX` per index). Applying
is idempotent: an index already live under the declared name is skipped
— the same-plan guard for SQLite, where an earlier table rebuild
materializes every declared index before the `AddIndex` change applies.
The reverse direction is preservation, not drift: a live index the
blueprint does not declare is untouched (the out-of-band index above).

## Locking

Any work that must not run twice concurrently needs a cross-process lock —
the schema `diff → apply` loop is the prime case: it reads the live
schema, then executes DDL including `DROP TABLE`, and two overlapping
instances race on stale snapshots.

`SqlConnection::withLock()` takes the dialect's native lock — MySQL
`GET_LOCK` · Postgres advisory lock · SQLite a write transaction — with
the mutual-exclusion *name* supplied at call time: one name is one lock
domain, so distinct jobs use distinct names and never wait on each
other. The schema-sync convention is the name `'radiant:schema'`:

```php
$conn->withLock(function () use ($differ, $desired, $conn): void {
    foreach ($differ->diff($desired) as $change) {
        $conn->apply($change);
    }
}, 'radiant:schema');
```

On SQLite the lock is a write transaction. That is fine for ordinary
DDL — but a plan that carries an FK-involved SQLite rebuild (content
drift, FK/CHECK changes, or a NOT NULL add that rebuilds) cannot run
inside it: the rebuild's `PRAGMA foreign_keys` toggle is a no-op inside
a transaction. The raw loop above then throws loudly. The
`SchemaSynchronizer` handles it (it plans under the lock and applies
outside it); a hand-rolled loop must release the lock before
applying such a plan — see "Atomicity vs SQLite rebuilds" above.

The same gate covers any other serialized work — cron jobs that must not
overlap, cache warmups:

```php
$conn->withLock(fn () => $this->buildNightlyReport(), 'report:nightly');
```

For custom locking (a locker service, file locks across machines),
implement `BlueprintAU\Radiant\Database\Locks\Lock` and call
`->withLock($callback, $name)` around the critical section. Without any
lock, schema sync is safe only for single-instance deployments.
