# The CSV backend

The CSV connection proves the portable core works on a non-SQL backend —
select/insert/update/delete run entirely in PHP, while SQL-only features
throw `UnsupportedFeatureException`.

- [Configuration](#configuration)
- [What it guarantees](#what-it-guarantees)
- [Concurrency boundary](#concurrency-boundary)
- [When to use it](#when-to-use-it)

## Configuration

| Key | Required | Notes |
| --- | --- | --- |
| `path` | yes | The CSV file to open |
| `readonly` | no | `true` gates this connection's writes (default `false`) |

```php
'export' => [
    'driver'   => 'csv',
    'path'     => __DIR__.'/export.csv',
    'readonly' => false,
],
```

## What it guarantees

- **Exclusive lock across every read-modify-write**, with a shared lock
  for reads so they proceed concurrently.
- **Atomic writes** — a UNIQUE temp file per write + rename, so concurrent
  writers cannot clobber each other and a mid-write failure never orphans
  a partial file. Original file permissions are preserved.
- **Column-aligned rows** as the schema grows — an update introducing a
  new column never shifts another row's values under the wrong header.
- **Formula-injection neutralization** on write — payloads starting with
  `=`, `@`, `+`, `-` (whitespace-prefixed included) are quoted so a
  spreadsheet cannot evaluate them; headers included. Ordinary negative
  numbers are data, not formulas, and stay unquoted.

## Concurrency boundary

The lock coordinates only `CsvConnection` instances of this library
cooperating through `flock`. Non-participating writers (another process
using `file_put_contents`, an editor save) bypass it and can tear a read
in progress — the `readonly` flag gates *this* connection's writes, not
the file's.

The blocking file I/O is also not coroutine-aware: under Swoole/Fiber
runtimes it stalls the worker for the I/O duration. SQL connections carry
their own concurrency contract — one connection per coroutine while a
transaction is open — described in
[Transactions](database.md#transactions).

## When to use it

It reads the whole file on every query and rewrites it on every write, so
it suits small, simple datasets — exports, fixtures, local tooling — not
production workloads.
