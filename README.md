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
], 'mysql');

$db = $manager->connection();

$users = $db->table('users')
    ->where('active', '=', 1)
    ->orderBy('name')
    ->get();

// Stream large result sets without buffering them all in memory.
foreach ($db->sqlConnection()->cursorSql('SELECT * FROM logs WHERE level = ?', ['warn']) as $row) {
    // ...
}
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

There is no separate `#[Index]` attribute planned. Simple indexes ride on
`#[Column]`'s `index:`/`unique:` flags. Composite indexes use named
groups: set the same group name on every participating column, and the
metadata factory emits one index per group — no duplicated column-name
strings, so renaming a property can't silently drop a column out of its
index. A column may join several groups at once by passing an array (a
`country` column often belongs to both `(country, created_at)` and
`(country, status)`).

```php
#[Column(type: ColumnType::String, length: 2,
         indexGroup: ['country_created', 'country_status'])]
public string $country;

#[Column(type: ColumnType::DateTime, indexGroup: 'country_created')]
public ?Carbon $createdAt;

#[Column(type: ColumnType::String, length: 16, indexGroup: 'country_status')]
public string $status;
// → CREATE INDEX ... ON users ("country", "created_at")
// → CREATE INDEX ... ON users ("country", "status")
```

*(Planned — the attribute classes and metadata factory are not
implemented yet; see the plan, §13–14.)*

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

$blueprint = (new Blueprint())
    ->id()
    ->column(ColumnType::String, 'email', length: 255, unique: true)
    ->column(ColumnType::String, 'country', length: 2, index: true)
    ->timestamp('created_at')
    ->index('users_country_created', ['country', 'created_at']); // composite

$db->sqlConnection()->create('users', $blueprint);
```

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