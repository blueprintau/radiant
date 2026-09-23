# The ORM

Attribute-driven models: columns, casts, constraints, soft deletes, and
multi-table inheritance. Relations are covered in
[Relations](relations.md); turning models into DDL is covered in
[Schema sync](schema.md).

- [Defining a model](#defining-a-model)
- [Table naming](#table-naming)
- [Columns and types](#columns-and-types)
- [Constraints](#constraints)
- [Saving and primary keys](#saving-and-primary-keys)
- [Soft deletes](#soft-deletes)
- [Multi-table inheritance](#multi-table-inheritance)
- [Metadata lifecycle](#metadata-lifecycle)

## Defining a model

Extend `Model` and declare `#[Column]` properties. The property's PHP type
drives the cast between the model and the database — the field is always
set to the type you declared:

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

### Fail-fast retrieval

`find()` and the builder's `first()` return `null` on no match. When an
empty result is a bug rather than an expected state, use the fail-fast
counterparts — they throw
`BlueprintAU\Radiant\Database\Exceptions\ModelNotFoundException` naming
the model class and, for keyed lookups, the key:

```php
$user = User::findOrFail(1);          // static
$user = User::newQuery()->firstOrFail();
$region = Region::newQuery()->sole(); // exactly one row required
```

`sole()` is stricter: it throws `ModelNotFoundException` on zero rows and
`MultipleRecordsFoundException` when more than one row matches — intended
for reads backed by a uniqueness guarantee. Relations expose the same
fail-fast reads: `$user->featuredPost()->firstOrFail()` and
`->sole()` delegate to the constrained query.

Every `#[Column]` property must declare a single named PHP type — untyped,
union, and intersection types fail at metadata build. A `?Carbon` property
on a DateTime column round-trips `Carbon` instances; an `int` property on
a timestamp column is a Unix-timestamp cast.

## Table naming

A model's table defaults to the snake-cased plural of its class name —
`User` → `users`, `EmailVerificationToken` →
`email_verification_tokens`. Declare `#[Table(name: '...')]` when the
default would be wrong: irregular plurals (`Person` → `people`), prefixed
tables, or shared tables.

The attribute is designed to grow other table-level settings later, so
`name` is optional there — `#[Table]` with no name keeps the convention
(empty string still fails fast).

## Columns and types

`#[Column]` options you'll use day to day:

- `type:` — a `ColumnType` enum case (the shared, dialect-agnostic type
  vocabulary for both the schema layer and the attribute).
- `primaryKey:`, `autoIncrement:` — the key.
- `nullable:` — allows `null`.
- `length:` — **required** for string columns.
- `name:` — explicit DB column name when it differs from the property.
- `unique:`, `index:`, `foreign:` — single-column flags (see below).
- `default:` — a scalar or SQL expression default.

**Synthetic columns.** A column may exist in the metadata without a
property backing it — useful for columns the model reads and writes but
doesn't want as a typed field. Read it with
`$model->attribute('column_name')`; a value written with
`$model->setAttribute('column_name', $value)` is held in a runtime store
and survives re-loading the model. A column backed by a typed property
rejects `setAttribute()` — write the property directly.

## Constraints

One uniform rule: **a constraint over more than one column, or one needing
explicit configuration, is a class-level attribute.** Single-column
uniques, indexes, and foreign keys ride the `#[Column]` flags.

```php
use BlueprintAU\Radiant\Attributes\Index;
use BlueprintAU\Radiant\Attributes\ForeignKey;
use BlueprintAU\Radiant\Attributes\Unique;

#[Unique(columns: ['country', 'tracking'])]
#[ForeignKey(
    columns: ['region_id', 'country'],
    references: 'geo_regions',
    referencesColumns: ['id', 'country'],
    onDelete: 'cascade',
)]
#[Index(columns: ['country', 'created_at'])]
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

**Constraint options.** The same dialect gating as the schema layer: an
option the active dialect cannot render fails fast at DDL compile time.

- `#[Unique(nullsNotDistinct: true)]` — `NULLS NOT DISTINCT` (Postgres 15+):
  at most one NULL among duplicates. The classic "one active row per user"
  constraint on a nullable column.
- `#[Unique(where: ...)]` / `#[Index(where: ...)]` — partial (filtered)
  index (Postgres, SQLite), e.g. `where: 'accepted_at IS NULL'` for a
  unique-among-pending constraint.
- `#[ForeignKey(deferrable: true, initiallyDeferred: true)]` — Postgres
  only; lets circular foreign keys seed within one transaction.
- `#[Check(expression: 'price >= 0', name: 'price_positive')]` — portable
  table-level CHECK (MySQL 8.0.16+ enforces it; older MySQL parses and
  ignores).

**Composite support.** A composite primary key is declared by setting
`primaryKey: true` on multiple `#[Column]` properties. Every constraint
location accepts a column list: `#[Unique]` and `#[Index]` take
`columns:` lists by shape, and a composite foreign key is the class-level
`#[ForeignKey]` with a `columns:` list (its `referencesColumns` defaults
to the target model's full primary key). The single-column `foreign:`
flag rejects a composite-PK target — one column cannot reference a
two-column key — so use the class-level attribute there.

## Saving and primary keys

`save()` is shaped by the model's in-memory state: a model that has never
been saved (`exists === false`) always **INSERTs**; a loaded model
**UPDATEs** its dirty columns. The runtime does not re-check the database
before choosing.

**Caller-assigned keys.** With a non-auto-increment PK (UUID, char, or a
PK you assign yourself), a re-`save()` of a model constructed in memory
(with `new`) INSERTs again — a duplicate-PK failure if the row exists.
Re-save a row through a loaded instance (`Model::find()` / a query) or
set the key columns before the FIRST save and treat later `save()` calls
on that instance as the update path. There is deliberately no upsert.

**Concurrency.** `save()` is last-writer-wins: two workers that load the
same row and both save produce a lost update, with no version column and
no affected-rows guard on the update. There is no optimistic locking.
For critical read-modify-write paths, use `lockForUpdate()` inside a
transaction (row locks require one — see
[Transactions](database.md#transactions)), or add your own version column
and assert it in the update.

**Column defaults.** Properties left uninitialized are omitted from the
INSERT so the database default fires; after the insert, the declared
`default:` is materialized onto the uninitialized property (decoded
through the column's own cast), so the in-memory model matches the row
without a re-fetch. Expression defaults (e.g. `CURRENT_TIMESTAMP`) are
skipped — their DB-computed value is unknowable client-side, and the
property stays honestly uninitialized.

## Soft deletes

Opt in by applying the `SoftDeletes` trait — the delete column
(`deleted_at` by default, overridable via `deletedAtColumn()`) is declared
for you if you haven't:

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

## Multi-table inheritance

A concrete subclass that adds columns **and** declares its own `#[Table]`
is a multi-table-inheritance (MTI) child: the child table holds its own
columns, the ancestor's table keeps the inherited ones, and the shared
primary key links them.

```php
#[Table(name: 'admins')]
class Admin extends User
{
    #[Column(type: ColumnType::String, length: 64)]
    public string $level;      // lives on admins; email/id live on users
}
```

The child declares **no key of its own** — the factory derives it from the
root's `#[Column]` with `autoIncrement: false` (only the root generates
the id), and the schema layer emits the FK (`FOREIGN KEY (id) REFERENCES
users (id) ON DELETE CASCADE`). `Blueprint::fromMetadata(Admin::class)`
produces that DDL, so the schema sync covers both tables.

Reads JOIN every ancestor table and alias every column back to its plain
name, so one hydrated model spans all levels and `Admin::find(5)` returns
an admin with its `email` intact. Writes split per table in one
transaction: the root inserts first (generating the id), descendants copy
it; updates touch only the dirty partitions; deletes run leaf-first.

Adding columns without `#[Table]` is still a build error (the columns have
nowhere to go), and a behavior-only subclass still shares the ancestor's
table — MTI is opt-in via `#[Table]`.

## Metadata lifecycle

Metadata is built once per class and cached — attribute validation,
inheritance merging, and constraint checks run at first use, never per
query. The classes involved are `MetadataFactory` (the cache and build
entry point), `ClassMetadata` (a class's resolved table, columns, keys,
and constraints), and `PropertyMapping` (one column ↔ property pair).

Processes that regenerate classes at runtime — dev servers with hot
reload, codegen tools, test suites that redefine classes — must clear the
cache or it serves the old metadata forever:

```php
MetadataFactory::clear();            // clear everything
MetadataFactory::clear(User::class); // clear one class
```

Clearing one class does not clear its ancestors or descendants (their
metadata is cached independently) — prefer the full clear when a model
family changes.
