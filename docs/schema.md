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

$blueprint = (new Blueprint())
    ->id()
    ->column(ColumnType::String, 'email', length: 255, unique: true)
    ->column(ColumnType::String, 'country', length: 2, index: true)
    ->timestamp('created_at')
    ->index('users_country_created', ['country', 'created_at']); // composite
```

Indexes: `Blueprint::index($name, $columns, $unique)` compiles to
`CREATE INDEX` per dialect, and single-column indexes come from a
column's `index:` flag.

## Creating, altering, dropping

```php
use BlueprintAU\Radiant\Database\Connections\SqlConnection;

/** @var SqlConnection $conn */

$conn->create('users', $blueprint);
$conn->alter($operation, $blueprint);
$conn->drop('users');
```

## Schema sync

The schema layer ships a **differ**: desired state vs. live schema →
ordered, classified changes. Radiant computes and reports; the host
command (plan → show → apply) decides and acts.

```php
use BlueprintAU\Radiant\Database\Schema\Blueprint;
use BlueprintAU\Radiant\Database\Schema\SchemaDiffer;
use BlueprintAU\Radiant\Database\Connections\SqlConnection;

/** @var SqlConnection $conn */

$desired = [
    'users' => Blueprint::fromMetadata(User::class),
    'posts' => Blueprint::fromMetadata(Post::class),
];

$differ = new SchemaDiffer($conn->schemaInspector); // never construct an inspector yourself
$changes = $differ->diff($desired);                 // plan

foreach ($changes as $change) {
    echo $change->description, $change->destructive ? '  [DESTRUCTIVE]' : '', "\n";
    if (!$change->destructive) {
        $conn->apply($change); // show → apply; gate destructive changes behind a confirmation
    }
}
```

Each returned `SchemaChange` carries the table, the operation
(`CreateTable`/`AddColumn`/`DropColumn`/`DropTable`), a `destructive` flag
(anything that can lose data), and a human-readable `description` for
dry-run output. Changes are ordered **creates → alters → drops**, so a
rename (drop + create) never destroys data before its replacement exists.

Rename-shaped diffs are **flagged, never rewritten**: a column add+drop
pair on one table, or a create+drop table pair sharing at least half their
columns, is marked `possibleRename` / `renameOf` so the host can ask "is
this a rename?" — a wrong guess executing `RENAME COLUMN` between
unrelated columns would corrupt data.

The v1 differ is **column-level only**: it detects whole-table
creates/drops and column adds/drops. A changed column type, nullable
flag, or default surfaces as a re-add (reported in the plan) — not an
in-place modify — and index/FK changes are not diffed yet. Review the
plan before applying.

## Locking

Any work that must not run twice concurrently needs a cross-process lock —
the schema `diff → apply` loop is the prime case: it reads the live
schema, then executes DDL including `DROP TABLE`, and two overlapping
instances race on stale snapshots.

`SqlConnection::withLock()` takes the dialect's native lock — MySQL
`GET_LOCK` · Postgres advisory lock · SQLite `BEGIN IMMEDIATE` — with the
mutual-exclusion *name* supplied at call time: one name is one lock
domain, so distinct jobs use distinct names and never serialize each
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
