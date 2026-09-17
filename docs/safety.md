# Safety

The guarantees Radiant makes, in one place. Mechanisms live in the source
docblocks.

- [Injection-proof clauses](#injection-proof-clauses)
- [Log-safe failures](#log-safe-failures)
- [Fail-fast everywhere](#fail-fast-everywhere)
- [Honest transactions](#honest-transactions)
- [Bounded eager loading](#bounded-eager-loading)

## Injection-proof clauses

Values are always bound as parameters. Identifiers are quoted per
dialect. The clause fragments that cannot be bound — order direction,
column-to-column operators, the MySQL charset — are allowlisted
(`SortDirection`, `ColumnOperator` enums and the charset allowlist) rather
than interpolated raw. DSN metacharacters in `host`/`database` config are
rejected at validation; MySQL forces native prepared statements (client-side
emulation cannot be re-enabled via options).

There is no string-based `*Raw()` API. Verbatim SQL is spliced only via an
explicit `Expression` — `select(new Expression(...))`,
`orderBy(new Expression(...))` — and `whereRaw()` (raw condition +
positional bindings). Wrapping SQL in `new Expression(...)` makes every
verbatim splice greppable in an app, and the `Expression` constructor
docblock states the caller owns its safety: never pass user-supplied
content.

## Log-safe failures

`QueryException::getMessage()` carries no SQL and no bindings — hosts can
log it unfiltered without leaking request-derived PII or credentials.

SQL, bindings, and a full context rendering are available opt-in (`$sql`,
`getBindings()`, `toContextString()`) when a host deliberately wants them.

## Fail-fast everywhere

No silent fallbacks. Examples of what throws instead of misbehaving:

- An empty `whereIn([])` throws instead of compiling invalid `IN ()` SQL.
- An empty nested where group throws at declaration.
- Aggregate arguments fail closed on non-column shapes.
- A corrupt JSON or datetime cell throws with the column named, instead of
  corrupting hydration silently.

## Honest transactions

Transaction nesting uses uniquely-named real savepoints. The depth counter
cannot desync from a failed commit/rollback, and a failed rollback never
masks the original exception. A connection is never left believing it is
in a transaction the server has already aborted.

## Bounded eager loading

Parent keys are chunked (500 per query), so one oversized `load()` degrades
to more queries instead of exceeding driver placeholder caps or
`max_allowed_packet`.
