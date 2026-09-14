# The ORM

Attribute-driven models: columns, casts, constraints, soft deletes, and
multi-table inheritance. Relations are covered in
[Relations](relations.md); turning models into DDL is covered in
[Schema sync](schema.md).

- [Defining a model](#defining-a-model)
- [Table naming](#table-naming)
- [Columns and types](#columns-and-types)
- [Constraints](#constraints)
- [Soft deletes](#soft-deletes)
- [Multi-table inheritance](#multi-table-inheritance)

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
