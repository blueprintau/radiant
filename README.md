# BlueprintAU Radiant

[![PHP Tests](https://github.com/blueprintau/radiant/actions/workflows/tests.yml/badge.svg)](https://github.com/blueprintau/radiant/actions/workflows/tests.yml)
[![Packagist](https://img.shields.io/packagist/v/blueprintau/radiant.svg)](https://packagist.org/packages/blueprintau/radiant)

A **database + ORM package** for the BlueprintAU ecosystem — a fail-fast,
explicit query builder, SQL connection layer, and attribute-driven ORM.
Radiant is the database layer split out of the Lucent restructure.

> **Status:** the `Database\` layer is implemented and tested. The ORM
> (`Model`, `#[Column]`, relations, …) is the next milestone — the examples
> below are the planned API.

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

Raw SQL — including streaming large result sets without buffering them all in
memory — is SQL-only, so narrow to a `SqlConnection` first (see
[SQL-only features](#sql-only-features)):

```php
use BlueprintAU\Radiant\Database\Connections\SqlConnection;

$conn = $manager->connection('mysql');

if ($conn instanceof SqlConnection) {
    foreach ($conn->cursorSql('SELECT * FROM logs WHERE level = ?', ['warn']) as $row) {
        // ...
    }
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

### ORM *(planned)*

```php
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\Table;
use BlueprintAU\Radiant\Column;
use BlueprintAU\Radiant\Database\Schema\ColumnType;
use Carbon\Carbon;

#[Table(name: 'user_accounts')] // optional — see naming below
class User extends Model
{
    #[Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    #[Column(type: ColumnType::String, length: 255, fillable: true, unique: true)]
    public string $email;

    #[Column(type: ColumnType::DateTime, nullable: true)]
    public ?Carbon $emailVerifiedAt;
}

$user = User::find(1);
$user->name = 'Alicia';
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

*(Planned — the attribute classes and metadata factory are not implemented
yet.)*

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

`alter()` and `drop()` are available on `SqlConnection` for schema changes.

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
|---|---|---|
| MySQL | `mysql` | `host`, `port`, `database`, `username`, `password`; optional `charset` (allowlisted) and PDO `options` |
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
  (`find`, `all`, `where`, `save`, `delete`) works on any `Connection`;
  SQL-only extras (joins, transactions, relations) throw
  `UnsupportedFeatureException` on a non-SQL backend — never silently
  ignored.
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