# Database layer

Connections, drivers, the query builder, writes, aggregates, transactions,
and streaming. Everything here works identically on every driver unless a
section says otherwise.

- [Connections and drivers](#connections-and-drivers)
- [The portable core](#the-portable-core)
- [Query builder](#query-builder)
- [Writes and aggregates](#writes-and-aggregates)
- [Streaming results](#streaming-results)
- [Raw SQL](#raw-sql)
- [Transactions](#transactions)
- [Self-healing connections](#self-healing-connections)

## Connections and drivers

A `DatabaseManager` owns named connections and hands them out. Wiring is
explicit: the constructor takes the config array and the default name —
no container, no service locator, no static registry.

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
```

Multiple named connections can be declared. `$manager->connection('name')`
selects one, and `$manager->usingConnection('name', fn () => ...)`
scopes a callback to one. Each connector validates its own config at
construction and fails fast with a message naming the problem.

| Driver | Key | Required | Optional |
| --- | --- | --- | --- |
| MySQL | `mysql` | `host`, `port` (integer), `database` | `username`, `password`, `charset` (allowlisted), PDO `options` |
| SQLite | `sqlite` | `database` (non-empty path string) | PDO `options` |
| Postgres | `pgsql` | `host`, `database` | `port` (integer, default 5432), `sslmode` (allowlisted), `username`, `password`, PDO `options` |
| CSV | `csv` | `path` (non-empty string) | `readonly` boolean |

```php
'sqlite' => ['driver' => 'sqlite', 'database' => __DIR__.'/app.sqlite'],
'pgsql'  => ['driver' => 'pgsql', 'host' => '127.0.0.1', 'database' => 'app'],
```

Custom backends register via `extendConnector('mydriver', MyConnector::class)`
or `$manager->addConnection(...)`. The CSV backend is a first-class example —
see [The CSV backend](csv-backend.md).

## The portable core

The generic `ConnectionInterface` runs a structured query against any
backend (SQL, CSV, …): `table()`, `select()`, `insert()`, `update()`,
`delete()`, `cursor()`. The ORM's core CRUD works on all of them.

Features that only make sense with a real SQL engine — joins, raw SQL,
transactions, schema changes — live on `SqlConnection` and throw
`UnsupportedFeatureException` on a non-SQL backend. Never silently ignored.

Narrow the connection when you need the extras:

```php
use BlueprintAU\Radiant\Database\Connections\SqlConnection;

$conn = $manager->connection();

if (!$conn instanceof SqlConnection) {
    // The portable core still works here — only the extras are unavailable.
}
```

Or let the facade throw for you:

```php
use BlueprintAU\Radiant\Database;

$conn = Database::sqlConnection(); // throws UnsupportedFeatureException on a non-SQL backend
```

## Query builder

The builder is the same fluent surface everywhere:

```php
$users = $db->table('users')
    ->where('active', '=', 1)
    ->orderBy('name')
    ->get(); // Collection<int, \stdClass>
```

Every method validates its inputs and fails fast rather than compiling
broken SQL — an empty `whereIn([])` throws, aggregate arguments fail
closed on non-column shapes, and clause fragments that cannot be bound as
parameters (order direction, column-to-column operators, the MySQL
charset) are allowlisted, never interpolated raw.

## Writes and aggregates

The same builder runs writes on any backend:

```php
$count = $db->table('users')->insert([
    ['name' => 'Alicia', 'active' => 1],
    ['name' => 'Ben',    'active' => 1],
]);

$updated = $db->table('users')
    ->where('last_login', '<', $cutoff)
    ->update(['active' => 0]);

$deleted = $db->table('users')->where('active', '=', 0)->delete();

$total    = $db->table('orders')->count();
$cheapest = $db->table('orders')->min('price');
$emails   = $db->table('users')->pluck('email'); // Collection
$one      = $db->table('users')->where('id', '=', 1)->first();
```

`insertGetId()` returns the new row's id only when the builder knows which
column holds it — declare it with `insertIdColumn()` first; otherwise the
insert runs and the method returns `null`:

```php
$id = $db->table('users')->insertIdColumn('id')->insertGetId(['name' => 'Alicia']);
```

## Streaming results

`cursor()` streams large result sets without materializing them all as PHP
objects — the memory-light counterpart of `get()`:

```php
foreach ($db->table('logs')->where('level', '=', 'warn')->cursor() as $row) {
    // Rows arrive one at a time — on SQL, memory stays bounded by a single row.
}
```

Each backend materializes rows however its transport allows: a SQL
connection fetches row by row from the statement; a CSV connection, whose
file is already fully in memory, simply yields the rows `get()` would
return.

Prefer batches over single rows? `chunkSql()` feeds fixed-size chunks to a
callback (return strict `false` from the callback to stop early). Every
chunk is exactly `$size` rows except possibly the last, which holds the
remainder:

```php
if ($conn instanceof SqlConnection) {
    $conn->chunkSql('SELECT * FROM logs', [], 1000, function (array $chunk): void {
        // ...
    });
}
```

## Raw SQL

Raw SQL is SQL-only — narrow to a `SqlConnection` first:

```php
use BlueprintAU\Radiant\Database\Connections\SqlConnection;

if ($conn instanceof SqlConnection) {
    foreach ($conn->cursorSql('SELECT * FROM logs WHERE level = ?', ['warn']) as $row) {
        // ...
    }
}
```

`selectSql()` returns a `Collection` of all rows; `statement()` runs a
statement and discards the outcome; `affectingStatement()` returns the
affected-row count.

## Transactions

Transactions are SQL-only and nest via real savepoints — a failed rollback
never masks the original exception:

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

## Self-healing connections

A connection whose query fails with a connection-loss error (server
restart, network blip) is marked stale and transparently rebuilt on the
next use — under long-running runtimes a transient outage doesn't poison
the worker. Eviction and garbage collection roll back any transaction the
caller abandoned.

## The static facade

If you prefer not to thread a `DatabaseManager` through your code, inject
it once at bootstrap and use the `Database` facade:

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
