# Safety

The guarantees Radiant makes, in one place. Mechanisms live in the source
docblocks.

- [Injection-proof clauses](#injection-proof-clauses)
- [Log-safe failures](#log-safe-failures)
- [Fail-fast everywhere](#fail-fast-everywhere)
- [Honest transactions](#honest-transactions)
- [Bounded queries](#bounded-queries)

## Injection-proof clauses

Values are always bound as parameters. Identifiers are quoted per
dialect. The clause fragments that cannot be bound — order direction,
column-to-column operators, the MySQL charset — are restricted to fixed
sets of allowed values (`SortDirection`, `ColumnOperator` enums and the
charset allowlist) rather than interpolated raw. DSN metacharacters in
`host`/`database` config are rejected at validation; MySQL forces native
prepared statements (client-side emulation cannot be re-enabled via
options).

There is no string-based `*Raw()` API. Verbatim SQL is spliced only via an
explicit `Expression` — `select(new Expression(...))`,
`orderBy(new Expression(...))` — and `whereRaw()` (raw condition +
positional bindings). Wrapping SQL in `new Expression(...)` makes every
verbatim splice greppable in an app, and the `Expression` constructor
docblock states the caller owns its safety: never pass user-supplied
content.

Value objects that must land in an inline (non-bindable) position — an
`ORDER BY` key, a `GROUP BY` key, DDL — implement `ToSqlValue`. The
interface produces an unquoted SQL scalar, never arbitrary SQL, so a
UUID or enum object stays a literal in the compiled statement.

## Log-safe failures

`QueryException::getMessage()` carries no SQL and no bindings — hosts can
log it unfiltered without leaking request-derived PII or credentials.

SQL, bindings, and a full context rendering are available opt-in (`$sql`,
`getBindings()`, `toContextString()`) when a host deliberately wants them.

## Fail-fast everywhere

No silent fallbacks. Examples of what throws instead of misbehaving:

- An empty `whereIn([])` throws instead of compiling invalid `IN ()` SQL.
- An empty nested where group throws at declaration.
- A discarded `whereNested()` callback return (the callback added clauses
  but returned nothing usable) throws — under the immutable builder API a
  callback MUST return the `WhereBuilder` it constrained.
- Aggregate arguments fail closed on non-column shapes.
- A table reference containing whitespace must use the `table as alias`
  spelling — the compact `profiles p1` form throws instead of being
  silently rewritten into an alias (a mistyped table name must not
  become a phantom alias).
- A subquery select alias must be a bare identifier — a `SubquerySelect`
  node with `bad alias!` throws at declaration instead of compiling
  broken AS
  SQL.
- A non-SQL connection rejects EXISTS constraints and subquery select
  columns by name (`subquery-where` / `subquery-select`) before any
  compilation — the CSV engine never walks a clause shape it cannot
  evaluate.
- A corrupt JSON or datetime cell throws with the column named, instead of
  silently loading wrong data into the model.
- A where value that is invalid for its column's type throws naming the
  column — a malformed UUID against a Uuid column included. Hosts binding
  user-supplied identifiers (route params, request bodies) should validate
  the format BEFORE querying and render their own not-found semantics
  (e.g. a 404), since a well-formed id that matches no row and a malformed
  id are different failure modes the ORM deliberately distinguishes.
- A query carrying a row lock (`lockForUpdate()`/`sharedLock()`) is
  rejected outside a transaction — the lock would be released the moment
  it was acquired.
- A through relation whose two hop keys disagree in shape (one scalar, one
  composite, or mismatched arity) throws at construction — the join is
  unbuildable.

Dialects refuse what they cannot express honestly, rather than emitting
SQL with different semantics: SQLite rejects row locks (no syntax);
MySQL rejects partial-index predicates and `NULLS NOT DISTINCT`; index
and constraint names over the 64-character MySQL cap fail at compile.
MySQL-specific quirks are explicit, not silent: a bare
`offset()` pads the limit (`LIMIT 18446744073709551615 OFFSET n`) and an
empty insert compiles to `INSERT INTO t () VALUES ()`.

## Honest transactions

Transaction nesting uses uniquely-named real savepoints. The depth counter
cannot desync from a failed commit/rollback, and a failed rollback never
masks the original exception. A connection is never left believing it is
in a transaction the server has already aborted.

## Bounded queries

Bulk key lists never scale past driver limits:

- Parent keys in eager loading are chunked (500 per query), so one
  oversized `load()` degrades to more queries instead of exceeding driver
  placeholder caps or `max_allowed_packet`.
- `whereKey([...])` is deliberately NOT chunked — it returns one builder,
  so a single query is preserved. An oversized list therefore hits the
  driver's own placeholder cap (SQLite's 999 variables, MySQL's
  `max_allowed_packet`) with the driver's error; keep such lists bounded
  at the call site.
- `chunkSql()` streams fixed-size chunks so a bulk read never
  materializes the whole result set.
