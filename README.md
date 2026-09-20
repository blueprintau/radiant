# BlueprintAU Radiant

[![PHP Tests](https://github.com/blueprintau/radiant/actions/workflows/tests.yml/badge.svg)](https://github.com/blueprintau/radiant/actions/workflows/tests.yml)
[![Packagist](https://img.shields.io/packagist/v/blueprintau/radiant.svg)](https://packagist.org/packages/blueprintau/radiant)

A **database + ORM package** for the BlueprintAU ecosystem — a fail-fast,
explicit query builder, SQL connection layer, and attribute-driven ORM.
Radiant is the database layer split out of the Lucent restructure.

## Documentation

| Guide | Contents |
| --- | --- |
| [Database layer](docs/database.md) | Connections, drivers, query builder, writes, transactions, streaming |
| [The ORM](docs/orm.md) | Models, `#[Column]`, constraints, soft deletes, multi-table inheritance |
| [Relations](docs/relations.md) | HasOne/HasMany/BelongsTo, polymorphic (morphTo/MorphOne/MorphMany — allowlist-typed), many-to-many (BelongsToMany/MorphToMany), typed cache-aware reads, eager loading, through relations |
| [Schema & sync](docs/schema.md) | Blueprints, the differ, plan → show → apply, cross-process locking |
| [CSV backend](docs/csv-backend.md) | The portable non-SQL connection and its limits |
| [Safety](docs/safety.md) | Injection-proofing, log-safe failures, fail-fast guarantees |

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

Prefer a static facade over threading a `DatabaseManager` through your
code? Inject it once at bootstrap:

```php
use BlueprintAU\Radiant\Database;

Database::setManager($manager); // typically at application bootstrap

$rows = Database::table('users')->where('active', '=', 1)->get();
```

### ORM quick start

```php
use BlueprintAU\Radiant\Model;
use BlueprintAU\Radiant\Attributes\Column;
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;
use Carbon\Carbon;

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

Queries through the model return a `Collection` of hydrated model
instances (a `blueprintau/collections` subclass with model helpers like
`find()`).

## Dependencies

- `blueprintau/collections` — the one dependency that appears in Radiant's
  public API signatures: queries return `Collection` instances.
- `nesbot/carbon` — first-class datetime support. Datetime columns hold
  `Carbon` instances on the model.

Everything else is PHP built-ins. **No logging, no container, no cache.**
Radiant reports via fail-fast exceptions (`QueryException`,
`ConnectionException`, `UnsupportedFeatureException`); the host framework
decides what to log. Wiring is explicit — no service locator, no DI lookup.

A failed query throws `QueryException` whose `getMessage()` is **log-safe
by contract**: it carries no SQL text and no bound values, so hosts can
log it unfiltered without leaking request-derived PII or credentials.
See [Safety](docs/safety.md).

## Philosophy

- **Fail-fast exceptions.** No silent fallbacks, no transparent caching
  that returns stale data. Radiant reports via exceptions; the host
  framework decides what to log.
- **Explicit wiring.** No container, no service locator, no static
  registry. `DatabaseManager` takes the config array and default name in
  its constructor.
- **Portable core, gated extras.** The generic `Connection` interface runs
  a structured query against any backend (SQL, CSV, …). The ORM's core
  CRUD (`find`, `all`, `where`, `save`, `delete` — soft deletes included)
  works on any `Connection`; SQL-only extras (joins, transactions, raw
  SQL, schema) throw `UnsupportedFeatureException` on a non-SQL backend —
  never silently ignored.
- **Type-driven casting.** Each column casts between the typed property
  value and a bindable value, driven by the PHP property type. The field
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

`composer.lock` is committed, so CI checks dependencies against the
security advisories database over a reviewed, reproducible set; consumers
resolve their own versions as usual for a library.

CI runs the test suite across PHP 8.4 / 8.5 (lowest and highest
dependencies) on every push and pull request, with MySQL and Postgres
service containers for the integration suite. Releases are cut from the
**Release** workflow (Actions → Release), which takes a version tag,
verifies it does not already exist, runs the full test suite, then creates
the tag and GitHub Release.

## License

MIT — see [LICENSE](LICENSE)
