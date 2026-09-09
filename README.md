# BlueprintAU Radiant

[![PHP Tests](https://github.com/blueprintau/radiant/actions/workflows/tests.yml/badge.svg)](https://github.com/blueprintau/radiant/actions/workflows/tests.yml)
[![Packagist](https://img.shields.io/packagist/v/blueprintau/radiant.svg)](https://packagist.org/packages/blueprintau/radiant)

A **database + ORM package** for the BlueprintAU ecosystem — a fail-fast,
explicit query builder, SQL connection layer, and attribute-driven ORM.
Radiant is the database layer split out of the Lucent restructure.

> **Status:** the `Database\` layer, the attribute-driven ORM (`Model`,
> `#[Column]`, soft deletes), **Relations** (HasOne/HasMany/BelongsTo +
> through), and **multi-table inheritance** are implemented and tested.

## Dependencies

- `blueprintau/collections` — the one dependency that appears in Radiant's
  public API signatures: queries return `Collection` instances.
- `nesbot/carbon` — first-class datetime support. Datetime columns hold
  `Carbon` instances on the model.

Everything else is PHP built-ins. **No logging, no container, no cache.**
Radiant reports via fail-fast exceptions (`QueryException`,
`ConnectionException`, `UnsupportedFeatureException`); the host framework
decides what to log. Wiring is explicit — no service locator, no DI lookup.

## Installation

```bash
composer require blueprintau/radiant
```

## Quick start

```php
use BlueprintAU\Radiant\Database\DatabaseManager;

$manager = new DatabaseManager([
  'mysql' => [
    'driver'   => 'mysql',
    'host'     => '127.0.0.1',
    'port'     => 3306,
    'database' => 'app',
    'username' => 'root',
    'password' => '',
  ],
], default: 'mysql');

$db = $manager->connection();

$users = $db->table('users')
    ->where('active', '=', 1)
    ->orderBy('name')
    ->get(); // Collection<int, \stdClass>
```

Streams large result sets without materializing them all as PHP objects via
`cursor()` — the memory-light counterpart of `get()`. Each backend
materializes rows however its transport allows: a SQL connection fetches row by
row from the statement (memory bounded by a single row); a CSV connection,
whose file is already fully in memory anyway, simply yields the rows `get()`
would return:

```php
foreach ($db->table('logs')->where('level', '=', 'warn')->cursor() as $row) {
    // Rows arrive one at a time — on SQL, memory stays bounded by a single row.
}
```

Raw SQL streams the same way via `cursorSql()` — that one is SQL-only, so
narrow to a `SqlConnection` first (see [SQL-only features](#sql-only-features)):

```php
use BlueprintAU\Radiant\Database\Connections\SqlConnection;

$conn = $manager->connection('mysql');

if ($conn instanceof SqlConnection) {
    foreach ($conn->cursorSql('SELECT * FROM logs WHERE level = ?', ['warn']) as $row) {
        // ...
    }
}
```

Prefer batches over single rows? `chunkSql()` feeds fixed-size chunks to a
callback (return strict `false` from the callback to stop early):

```php
if ($conn instanceof SqlConnection) {
    $conn->chunkSql('SELECT * FROM logs', [], 1000, function (array $chunk): void {
        // Exactly 1000 rows (the last chunk holds the remainder).
    });
}
```

### Static facade

If you prefer not to thread a `DatabaseManager` through your code, inject it
once at bootstrap and use the `Database` facade:

```php
use BlueprintAU\Radiant\Database;
use BlueprintAU\Radiant\Database\DatabaseManager;

Database::setManager($manager); // typically at application bootstrap

$rows    = Database::table('users')->where('active', '=', 1)->get();
$single  = Database::select('SELECT * FROM users WHERE id = ?', [1])->first();
$changed = Database::affectingStatement('UPDATE users SET active = ? WHERE id = ?', [0, 1]);

// SQL-only features through the facade fail fast on a non-SQL backend:
$conn = Database::sqlConnection(); // throws UnsupportedFeatureException otherwise
```

### ORM

```php
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\Attributes\Table;
use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use Carbon\Carbon;

#[Table(name: 'user_accounts')] // optional — see naming below
class User extends Model
{
    #[Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    #[Column(type: ColumnType::String, length: 255, unique: true)]
    public string $email;

    #[Column(type: ColumnType::DateTime, nullable: true)]
    public ?Carbon $emailVerifiedAt;
}

$user = User::find(1);
$user->email = 'alicia@example.com';
$user->save();
```

**Table naming.** A model's table defaults to the snake-cased plural of
its class name — `User` → `users`, `EmailVerificationToken` →
`email_verification_tokens`. Declare `#[Table(name: '...')]` when the
default would be wrong: irregular plurals (`Person` → `people`), prefixed
tables, or shared tables. The attribute is designed to grow other
table-level settings later, so `name` is optional there — `#[Table]` with
no name keeps the convention (empty string still fails fast).

Simple indexes and unique flags ride on `#[Column]` (`index: true`,
`unique: true`, `foreign: 'users.id'`). Composite constraints use
class-level attributes — one uniform rule: **a constraint over more than
one column, or one needing explicit configuration, is a class-level
attribute.**

```php
#[Unique(columns: ['country', 'tracking'])]
#[ForeignKey(
    columns: ['region_id', 'country'],
    references: 'geo_regions',
    referencesColumns: ['id', 'country'],
    onDelete: 'cascade',
)]
#[CompositeIndex(columns: ['country', 'created_at'])]
class Shipment extends Model
{
    #[Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    #[Column(type: ColumnType::String, length: 64, unique: true)]  // single: flag
    public string $slug;

    #[Column(type: ColumnType::BigInt)]
    public int $regionId;

    #[Column(type: ColumnType::String, length: 2)]
    public string $country;

    #[Column(type: ColumnType::String, length: 64)]
    public string $tracking;

    #[Column(type: ColumnType::DateTime, nullable: true)]
    public ?Carbon $createdAt;
}
```

Every column name in a constraint attribute is validated against the
model's `#[Column]` set at build time — renaming a property fails loudly,
never silently drops out of a constraint. A flag and an attribute covering
the same column is a build-time error, so constraints can't double-declare.
`#[ForeignKey]`'s `references` also accepts a model class-string, resolved
through that model's table.

Every `#[Column]` property must declare a single named PHP type — untyped,
union, and intersection types fail at metadata build — and the property
type drives the cast between the model and the database (e.g. a `?Carbon`
property on a DateTime column round-trips `Carbon` instances; an `int`
property on a timestamp column is a Unix-timestamp cast).

Queries through the model return a `Collection` of hydrated model
instances (a `blueprintau/collections` subclass with model helpers like
`find()`). Every column-accepting query method validates its column names
against the model — an unknown column throws instead of compiling a
broken query.

### Soft deletes

Opt into soft deletes by applying the `SoftDeletes` trait — the delete
column (`deleted_at` by default, overridable via `deletedAtColumn()`) is
declared for you if you haven't:

```php
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\SoftDeletes;

class Post extends Model
{
    use SoftDeletes;
}

$post->delete();        // UPDATE sets deleted_at
$post->trashed();       // true
$post->restore();       // clears the timestamp
$post->forceDelete();   // the real DELETE
```

Queries exclude trashed rows automatically; `withTrashed()` includes them
and `onlyTrashed()` returns just them. Soft deletes use only the portable
core (`update()` + `whereKey()`), so they work on any backend — CSV
included.

### Relations

Declare a relation as a method returning a relation object. The method name
is the relation's key; the constructor applies the constraint, so the
relation composes like the builder itself:

```php
class User extends Model
{
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);          // fk: user_id (convention)
    }

    public function featured(): HasOne
    {
        return $this->hasOne(Post::class, 'user_id');
    }
}

class Post extends Model
{
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class);        // fk: user_id
    }
}
```

The relation carries the shared filter vocabulary itself (`where`, `orWhere`,
`whereIn`, `whereNull`, `whereBetween`, `orderBy`, `limit`, `offset` — all
validated against the related model), so composition happens right on the
relation:

```php
foreach ($user->posts()->orderBy('created_at')->getResults() as $post) { ... }
$recent = $user->posts()->where('active', '=', 1)->limit(5)->getResults();
```

FK/local-key defaults follow the snake_case convention (`user_id`, the
model's primary key) and are overridable with explicit arguments. Every
column a relation names is validated against the model's declared columns
at construction — an unknown FK throws immediately.

Fail-fast semantics: a `BelongsTo` over a null FK yields no results (a
legitimately optional relation, not an error); a `HasOne` over duplicate
rows takes the first (stably ordered by the related PK) — uniqueness is the
schema's job.

**Eager loading.** `with()` runs one extra `whereIn(fk, keys)` query per
relation — no joins, no row multiplication, pagination stays correct:

```php
$users = User::with('posts')->get();               // static forwarder
$users = User::where('active', '=', 1)->with('posts')->get();  // mid-chain
$users->load('posts', 'followers');                // on an existing Collection
$fresh = $users->fresh();                          // re-query each model by key

$users = User::with('posts.comments')->get();      // dot-notation nests
```

An unknown relation name throws **at the `with()` call** — the typo is
caught at the call site. Loaded relations are cached on the instance
(`relationLoaded()` / `getRelation()`); lazy access through the relation
method always executes fresh. Eager-loaded `HasOne`/`BelongsTo` results
are a single model or `null`; `HasMany` results are a `Collection`.

Dot-notation nests to any depth (`'posts.comments.author'`), loading one
extra query per path segment.

**Through relations** hop via an intermediate model and are SQL-only (they
need a join):

```php
class Mechanic extends Model
{
    public function owner(): HasOneThrough
    {
        return $this->hasOneThrough(Owner::class, Car::class);
    }
}
```

HasOne/HasMany/BelongsTo ride the portable core (`whereIn` + `select`) and
work on any backend, CSV included.

### Multi-table inheritance

A concrete subclass that adds columns **and** declares its own `#[Table]`
is a multi-table-inheritance (MTI) child: the child table holds its own
columns, the ancestor's table keeps the inherited ones, and the shared
primary key links them:

```php
#[Table(name: 'admins')]
class Admin extends User
{
    #[Column(type: ColumnType::String, length: 64)]
    public string $level;      // lives on admins; email/id live on users
}
```

The child declares **no key of its own** — the factory derives it from the
root's `#[Column]` with `autoIncrement: false` (only the root generates the
id), and the schema layer emits the FK (`FOREIGN KEY (id) REFERENCES users
(id) ON DELETE CASCADE`). `Blueprint::fromMetadata(Admin::class)` produces
that DDL, so the schema sync covers both tables.

Reads JOIN every ancestor table (INNER — the FK CASCADE guarantees each
ancestor row exists) and alias every column back to its plain name, so one
hydrated model spans all levels and `Admin::find(5)` returns an admin with
its `email` intact. Writes split per table in one transaction: the root
inserts first (generating the id), descendants copy it; updates touch only
the dirty partitions; deletes run leaf-first. Adding columns without
`#[Table]` is still a build error (the columns have nowhere to go), and a
behavior-only subclass still shares the ancestor's table — MTI is opt-in
via `#[Table]`.

The `ColumnType` enum is the shared, dialect-agnostic type vocabulary for
both the schema layer (`Blueprint`, `SchemaGrammar`) and the `#[Column]`
attribute — one portable type system, mapped per dialect.

### Schema & indexes

Indexes are available today at the schema layer: `Blueprint::index($name,
$columns, $unique)` compiles to `CREATE INDEX` per dialect, and
single-column indexes come from a column's `index:` flag.

```php
use BlueprintAU\Radiant\Database\Schema\Blueprint;
use BlueprintAU\Radiant\Database\Schema\ColumnType;
use BlueprintAU\Radiant\Database\Connections\SqlConnection;

/** @var SqlConnection $conn — narrow first; schema is SQL-only */
$conn = $manager->connection();

$blueprint = (new Blueprint())
    ->id()
    ->column(ColumnType::String, 'email', length: 255, unique: true)
    ->column(ColumnType::String, 'country', length: 2, index: true)
    ->timestamp('created_at')
    ->index('users_country_created', ['country', 'created_at']); // composite

$conn->create('users', $blueprint);
```

`alter()` and `drop()` are available on `SqlConnection` for schema changes —
see below for the sync layer built on top of them.

### Schema sync

The schema layer also ships a **differ**: desired state vs. live schema →
ordered, classified changes. Radiant computes and reports; the host command
(plan → show → apply) decides and acts.

Declare the desired state from your models — `Blueprint::fromMetadata()`
folds every `#[Column]` and class-level constraint attribute into a
blueprint (a `SoftDeletes` model's delete column is included):

```php
use BlueprintAU\Radiant\Database\Schema\Blueprint;
use BlueprintAU\Radiant\Database\Schema\SchemaDiffer;
use BlueprintAU\Radiant\Database\Connections\SqlConnection;

/** @var SqlConnection $conn — schema sync is SQL-only */
$conn = $manager->connection();

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
(anything that can lose data), and a human-readable `description` for dry-run
output. Changes are ordered **creates → alters → drops**, so a rename (drop +
create) never destroys data before its replacement exists.

Rename-shaped diffs are **flagged, never rewritten**: a column add+drop pair
on one table, or a create+drop table pair sharing at least half their
columns, is marked `possibleRename` / `renameOf` so the host can ask "is this
a rename?" — a wrong guess executing `RENAME COLUMN` between unrelated
columns would corrupt data.

The v1 differ is **column-level only**: it detects whole-table creates/drops
and column adds/drops. A changed column type, nullable flag, or default
surfaces as a re-add (reported in the plan) — not an in-place modify — and
index/FK changes are not diffed yet. Review the plan before applying.

## SQL-only features

Raw SQL, transactions, and schema changes live on `SqlConnection`, not on the
generic `ConnectionInterface`. On a SQL backend, the generic methods are all
you need for CRUD; when you need the extras, narrow the connection:

```php
use BlueprintAU\Radiant\Database\Connections\SqlConnection;

$conn = $manager->connection();

if (!$conn instanceof SqlConnection) {
    // The portable core (select/insert/update/delete) still works here —
    // only joins, raw SQL, transactions, and schema are unavailable.
}
```

Narrowing by hand is verbose; the `Database::sqlConnection()` facade method
throws `UnsupportedFeatureException` for you on a non-SQL backend.

## Writes, aggregates & transactions

The same builder runs writes — on any backend, SQL or not:

```php
$count = $db->table('users')->insert([
    ['name' => 'Alicia', 'active' => 1],
    ['name' => 'Ben',    'active' => 1],
]);

$id = $db->table('users')->insertGetId(['name' => 'Alicia']);

$updated = $db->table('users')
    ->where('last_login', '<', $cutoff)
    ->update(['active' => 0]);

$deleted = $db->table('users')->where('active', '=', 0)->delete();
```

`insertGetId()` returns the new row's id only when the builder knows which
column holds it — declare it with `insertIdColumn()` first; otherwise the
insert runs and the method returns `null`:

```php
$id = $db->table('users')->insertIdColumn('id')->insertGetId(['name' => 'Alicia']);
```

Aggregates and reads:

```php
$total  = $db->table('orders')->count();
$cheapest = $db->table('orders')->min('price');
$emails = $db->table('users')->pluck('email'); // Collection
$one    = $db->table('users')->where('id', '=', 1)->first();
```

Transactions are SQL-only and use real savepoints when nested — a failed
rollback never masks the original exception:

```php
use BlueprintAU\Radiant\Database\Connections\SqlConnection;

/** @var SqlConnection $conn */
$conn->transaction(function () use ($conn): void {
    $conn->table('accounts')->where('id', '=', 1)->update(['balance' => 900]);
    $conn->table('accounts')->where('id', '=', 2)->update(['balance' => 1100]);
}); // throws, and rolls everything back, on any failure

$conn->beginTransaction();
// ...
$conn->commit();   // or $conn->rollBack();
echo $conn->transactionLevel(); // nesting depth
```

## Drivers

The driver key selects the connector; each connector validates its own config
at construction and fails fast with a message naming the problem.

| Driver | Key | Required config |
| --- | --- | --- |
| MySQL | `mysql` | `host`, `port` (integer), `database`; optional `username`, `password`, `charset` (allowlisted) and PDO `options` |
| SQLite | `sqlite` | `database` (path string); optional PDO `options` |
| Postgres | `pgsql` | `host`, `database`; optional `port` (default 5432), `username`, `password`, PDO `options` |
| CSV | `csv` | `path`; optional `readonly` boolean |

```php
'sqlite' => ['driver' => 'sqlite', 'database' => __DIR__.'/app.sqlite'],
'pgsql'  => ['driver' => 'pgsql', 'host' => '127.0.0.1', 'database' => 'app'],
```

Custom backends register via `extendConnector('mydriver', MyConnector::class)`
or `$manager->addConnection(...)`. Multiple named connections can be declared;
`$manager->connection('name')` selects one, and
`$manager->usingConnection('name', fn () => ...)` scopes a callback to one.

### CSV backend

The CSV connection proves the portable core works on a non-SQL backend —
select/insert/update/delete run entirely in PHP, while SQL-only features throw
`UnsupportedFeatureException`. It takes an exclusive file lock across every
read-modify-write, writes atomically (temp file + rename), and neutralizes
formula-injection values on write.

```php
'export' => [
    'driver'   => 'csv',
    'path'     => __DIR__.'/export.csv',
    'readonly' => false,
],
```

It reads the whole file on every query and rewrites it on every write, so it
suits small, simple datasets — not production workloads.

## Philosophy

- **Fail-fast exceptions.** No silent fallbacks, no transparent caching that
  returns stale data. Radiant reports via exceptions; the host framework
  decides what to log.
- **Explicit wiring.** No container, no service locator, no static registry.
  `DatabaseManager` takes the config array and default name in its
  constructor.
- **Portable core, gated extras.** The generic `Connection` interface runs a
  structured query against any backend (SQL, CSV, …). The ORM's core CRUD
  (`find`, `all`, `where`, `save`, `delete` — soft deletes included) works
  on any `Connection`; SQL-only extras (joins, transactions, raw SQL,
  schema) throw `UnsupportedFeatureException` on a non-SQL backend — never
  silently ignored.
- **Type-driven casting.** Each `Column` casts between the typed property
  value and a bindable value, driven by the PHP property type. The `ValueCodec`
  handles dialect specifics (Postgres' microsecond datetimes, …). The field
  is always set to the type the user expects.

## Safety

- **Injection-proof clauses.** Values are always bound as parameters.
  Identifiers are quoted per dialect. The clause fragments that cannot be
  bound — order direction, column-to-column operators, the MySQL charset —
  are allowlisted (`SortDirection`, `ColumnOperator` enums and the charset
  allowlist) rather than interpolated raw.
- **Fail-fast everywhere.** An empty `whereIn([])` throws instead of
  compiling invalid `IN ()` SQL; a bad chunk size throws; a malformed
  connection config throws at construction.
- **Self-healing connections.** A connection whose query fails with a
  connection-loss error (server restart, network blip) is marked stale and
  transparently rebuilt on the next use — under long-running runtimes a
  transient outage doesn't poison the worker.
- **Honest transactions.** Transaction nesting uses real savepoints; the
  depth counter cannot desync from a failed commit/rollback, and a failed
  rollback never masks the original exception.
- **CSV backend hardening.** Mutations take an exclusive file lock across
  the whole read-modify-write, writes are atomic (temp file + rename), rows
  stay column-aligned as the schema grows, and values that a spreadsheet
  would evaluate as formulas are neutralized on write.

## Requirements

PHP **8.4 or newer**.

## Testing

```bash
composer install
composer test          # PHPUnit
composer analyse       # PHPStan (level 8)
composer security:audit  # dependency security advisories
```

`composer.lock` is committed, so CI checks dependencies against the security
advisories database over a reviewed, reproducible set; consumers resolve
their own versions as usual for a library.

CI runs the test suite across PHP 8.4 / 8.5 (lowest and highest
dependencies) on every push and pull request, with MySQL and Postgres
service containers for the integration suite. Releases are cut from the
**Release** workflow (Actions → Release), which takes a version tag, verifies
it does not already exist, runs the full test suite, then creates the tag and
GitHub Release.

## License

MIT — see [LICENSE](LICENSE)
