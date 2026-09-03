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

- `blueprintau/collections` — the only concrete-class dependency. Radiant's
  public API returns `Collection` instances, so it's the one concrete-class
  dependency. Everything else is PHP built-ins.
- `nesbot/carbon` — first-class datetime support. Datetime columns hold
  `Carbon` instances on the model.

**No logging, no container, no cache.** Radiant reports via fail-fast
exceptions (`QueryException`, `ConnectionException`,
`UnsupportedFeatureException`); the host framework decides what to log.
Wiring is explicit — no service locator, no DI lookup.

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
```

### ORM *(planned)*

```php
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\Column;
use BlueprintAU\Radiant\Database\Schema\ColumnType;
use Carbon\Carbon;

class User extends Model
{
    #[Column(type: ColumnType::BigInt, primaryKey: true, autoIncrement: true)]
    public int $id;

    #[Column(type: ColumnType::String, length: 255, fillable: true)]
    public string $name;

    #[Column(type: ColumnType::DateTime, nullable: true)]
    public ?Carbon $emailVerifiedAt;
}

$user = User::find(1);
$user->name = 'Alicia';
$user->save();
```

The `ColumnType` enum is the shared, dialect-agnostic type vocabulary for
both the schema layer (`Blueprint`, `SchemaGrammar`) and the `#[Column]`
attribute — one portable type system, mapped per dialect.

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

## Requirements

PHP **8.4 or newer**.

## Testing

```bash
composer install
composer test          # PHPUnit
composer analyse       # PHPStan (level 8)
composer security:audit  # dependency security advisories
```

CI runs the test suite across PHP 8.4 / 8.5 (lowest and highest
dependencies) on every push and pull request, with MySQL and Postgres
service containers for the integration suite. Releases are cut from the
**Release** workflow (Actions → Release), which takes a version tag, verifies
it does not already exist, runs the full test suite, then creates the tag and
GitHub Release.

## License

MIT — see [LICENSE](LICENSE)