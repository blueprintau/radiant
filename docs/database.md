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
- [Value codecs](#value-codecs)
- [The static facade](#the-static-facade)

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
selects one, `$manager->usingConnection('name', fn () => ...)` scopes a
callback to one, and `$manager->useConnection('name')` makes one active
persistently — rejected while the active connection holds an open
transaction, since switching away would leave it dangling. Every name —
`connection('name')`, both switches, and the constructor's `default:` —
fail fast on an unknown name. Each connector validates its own config at
construction and fails fast with a message naming the problem.

| Driver | Key | Required | Optional |
| --- | --- | --- | --- |
| MySQL | `mysql` | `host`, `port` (integer), `database` | `username`, `password`, `charset` (allowlisted), PDO `options` |
| MariaDB | `mariadb` | `host`, `port` (integer), `database` | `username`, `password`, `charset` (allowlisted), PDO `options` |
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

### MariaDB

MariaDB speaks the MySQL wire protocol and SQL surface, so the `'mariadb'`
driver reuses the MySQL grammar — every query, DDL statement, savepoint
and advisory lock is identical. It is its own driver key (not an alias)
because the live-schema reader must adapt four MariaDB divergences:

- `JSON` columns store as `LONGTEXT` — a declared `Json` column reads back
  as `longtext`, which the MariaDB inspector maps onto `Json` so schema
  sync converges instead of re-planning every Json column as a modify.
- A current-timestamp default reports as `current_timestamp()` (or
  `now()`) — normalized to `CURRENT_TIMESTAMP` so an
  `Expression('CURRENT_TIMESTAMP')` column converges on the first plan.
- Integer display widths (`bigint(20)`) are stripped — except
  `tinyint(1)`, which is Boolean and kept verbatim.
- A literal `'NULL'` string default is normalized to a real SQL `NULL`.

The tested floor is MariaDB 11.4 LTS. Older versions are untested.

A custom connection can pre-flight queries instead of discovering an
unsupported shape at execution time: `SqlFeature::usedBy($query)` reports
which features a builder's query uses (joins, having, aggregates, raw SQL,
subquery-from, subquery-where, subquery-select, unions, row locks,
distinct), and
`$query->assertSupports(...)` fails fast with the named feature before any
SQL is compiled.

## The portable core

The generic `ConnectionInterface` runs a structured query against any
backend (SQL, CSV, …): `table()`, `select()`, `selectColumn()`,
`insert()`, `insertGetId()`, `update()`, `delete()`, `cursor()` (plus
the staleness pair `isStale()`/`markStale()`). The ORM's core CRUD works
on all of them. Scalar reads (`value()`/`pluck()`) ride `selectColumn()`
when given a plain column — an `Aggregate` argument instead runs through
a `select()` with a stable `radiant_scalar` alias and reads the result
back by name. `selectColumn()` fetches the single column directly on SQL
backends (`PDO::FETCH_COLUMN`) instead of materializing one row object
per record.

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

**Builders are immutable.** Every filter/select/order/limit call returns
a NEW builder — the original is never modified. A discarded call is a
no-op, and a base builder can be shared and branched safely:

```php
$base = $db->table('users')->where('active', '=', 1);

$admins = $base->where('role', '=', 'admin')->get(); // both queries
$recent = $base->where('last_login', '>', $cutoff)->get(); // independent
```

In a `whereNested()` callback, RETURN the builder — the callback's return
value is what gets stored, so a discarded return adds nothing:

```php
$q->whereNested(fn ($nested) => $nested->where('a', '=', 1)->orWhere('b', '=', 2));
```

### Subqueries

EXISTS constraints take a caller-built subquery — typically another
table's builder correlated to the outer query via `whereColumn()`. The
sub-builder is stored structurally (the Grammar renders it by recursion)
and its bindings are captured at declaration, so compiling stays a pure
snapshot:

```php
$users = $db->table('users')
    ->whereExists(
        $db->table('orders')
            ->whereColumn('orders.user_id', '=', 'users.id')
            ->where('total', '>', 100)
    )
    ->get();
```

The full family — `whereExists()`/`whereNotExists()` plus the
`or…` variants — exists on the builder, inside `whereNested()` groups
(via the `WhereBuilder` facade), on relations, and as `Model::` statics.
A scalar subquery joins the select list through the `SubquerySelect`
node — a builder + alias pair passed straight to `select()`:

```php
use BlueprintAU\Radiant\Database\Query\SubquerySelect;

$rows = $db->table('users')
    ->select(
        Aggregate::count('*', 'total'),
        new SubquerySelect(
            $db->table('orders')
                ->select(Aggregate::count())
                ->whereColumn('orders.user_id', '=', 'users.id'),
            'order_count',
        ),
    )
    ->get();
```

Both features are SQL-only: a non-SQL connection rejects them with the
named feature (`subquery-where` / `subquery-select`) before compiling.
Correlated references to the OUTER query's tables ride a base builder
(`$db->table(...)`); a model builder's column allowlist correctly refuses
columns it does not own.

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

**Bulk inserts are uniform.** A multi-row `insert()` compiles ONE column
list and one placeholder group per row, so every row must carry the same
columns — a row whose column set differs from the first row's throws
`InvalidArgumentException` before any statement runs. There is no
implicit padding: an absent column in a multi-row `VALUES` list could
only be filled with NULL, and silently writing NULL into a column the
caller never named is a data-corruption hazard, not a convenience. Give
every row the same keys (use an explicit `null` where you mean NULL), or
issue one `insert()` per shape. `update()` is unaffected — it writes
exactly the columns you pass.

Aggregates are typed. The common five have static factories; anything
server-specific (`group_concat`, `array_agg`, …) takes `new Aggregate(...)`
— the function is any bare SQL identifier, the column a declared column
(or `*`, optionally `distinct`):

```php
use BlueprintAU\Radiant\Database\Query\Aggregate;

// Group + filter on an aggregate:
$busy = $db->table('posts')
    ->select('user_id', Aggregate::count('*', 'total'))
    ->groupBy('user_id')
    ->having(Aggregate::count(), '>', 5)
    ->get();

// Multiple aggregates in one query (variadic; alias each):
$stats = $db->table('orders')->aggregates(
    Aggregate::count('*', 'total'),
    Aggregate::max('price', 'top'),
);
```

`insertGetId()` returns the new row's id only when the builder knows which
column holds it — declare it with `insertIdColumn()` first; otherwise the
insert runs and the method returns `null`. On the CSV backend the method
always returns `null` regardless — a CSV has no auto-increment id:

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

Two ownership rules are enforced rather than assumed:

- **Row locks require a transaction.** A query carrying
  `lockForUpdate()` or `sharedLock()` throws immediately when no
  transaction is open — row locks only live as long as their transaction,
  so holding one without one is meaningless. Wrap the query in
  `transaction()` or `beginTransaction()` first.
- **One connection per coroutine while a transaction is open.** The
  connection records which coroutine (fiber, Swoole coroutine, or process)
  opened the transaction and throws if a *different* coroutine calls
  `beginTransaction()`/`commit()`/`rollBack()` on it. Queries on the same
  coroutine are unrestricted; without a coroutine runtime every caller
  resolves to the same process id and the guard is inert.

## Self-healing connections

A connection whose query fails with a connection-loss error (server
restart, network blip) is marked stale and transparently rebuilt on the
next use — under long-running runtimes a transient outage doesn't leave
the worker with a broken connection. Eviction and garbage collection roll
back any transaction the caller abandoned.

## Value codecs

Each connection adapts PHP values to driver bytes at the boundary through
a codec (`encode()` on the write/bind path, `decode()` on the read path).
The default codec passes scalars through and normalizes
`DateTimeInterface` values to `Y-m-d H:i:s` in UTC; Postgres overrides it
for microsecond precision (`Y-m-d H:i:s.u`). The contract is symmetric —
value codecs (or the cast pipeline) also *interpret* datetime strings as
UTC wall-clock on the way out, so a stored instant survives round-trips
regardless of the host's `date.timezone` setting.

A column with a declared fractional-seconds precision is the exception:
the cast pipeline formats the value itself (UTC, exactly the declared
number of fractional digits) and the codec passes the string through —
the codec has no per-column knowledge, so a `datetime(3)` column would
otherwise receive a second-precision string and lose its milliseconds.

This is the driver boundary, not the field boundary: the property-level
cast pipeline (a `?Carbon` property, an `int` Unix-timestamp cast) is the
ORM's, described in
[Columns and types](orm.md#columns-and-types). The
codec runs beneath it, translating whatever the cast produced into the
dialect's wire format.

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

`Database::hasManager()` reports whether a manager has been injected, and
`Database::clearManager()` unsets it. Calling any other facade method
without a manager fails fast with a `RuntimeException` — never a silent
fallthrough — which makes unset bootstrap state visible at the call site
(and gives test teardowns a one-liner reset).
