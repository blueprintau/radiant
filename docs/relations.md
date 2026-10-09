# Relations

Declaring and loading relationships between models. Relation methods
return relation objects; the method name is the relation's key.

- [Declaring a relation](#declaring-a-relation)
- [Filtering and composing](#filtering-and-composing)
- [Grouped aggregates](#grouped-aggregates)
- [Key conventions](#key-conventions)
- [Eager loading](#eager-loading)
- [The model Collection](#the-model-collection)
- [Through relations](#through-relations)
- [Polymorphic relations](#polymorphic-relations)
- [Many-to-many relations](#many-to-many-relations)
- [Portability and semantics](#portability-and-semantics)

## Declaring a relation

Declare a relation as a method returning a relation object. The
constructor applies the constraint, so the relation composes like the
builder itself:

```php
use BlueprintAU\Radiant\Relations\BelongsTo;
use BlueprintAU\Radiant\Relations\HasMany;
use BlueprintAU\Radiant\Relations\HasOne;

class User extends Model
{
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);          // fk: user_id (convention)
    }

    public function featured(): HasOne
    {
        return $this->hasOne(Post::class, 'user_id');
    }
}

class Post extends Model
{
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class);        // fk: user_id
    }
}
```

## Filtering and composing

The relation carries the shared filter vocabulary itself (`where`,
`orWhere`, `whereIn`, `whereNotIn`, `whereNull`, `whereNotNull`,
`whereBetween`, `whereNotBetween`, `whereLike`/`whereNotLike` and their
`or*` forms, `whereNested`, plus `select`, `groupBy`, `having`,
`orderBy`, `limit`, `offset` — all validated against the related
model), so composition happens right on the relation:

```php
foreach ($user->posts()->orderBy('created_at')->get() as $post) { ... }
$recent = $user->posts()->where('active', '=', 1)->limit(5)->get();
```

**Relations are immutable.** Every filter and configurator (`withPivot()`,
`withTimestamps()`) returns a NEW relation — the original is never
modified, and a discarded call is a no-op. A composed chain always
executes fresh: the eagerly-loaded result was fetched unfiltered, so it
can never be served for a filtered read.

The relation also exposes the full read family, all scoped to the
relation's constraint: `first()`/`find()` return the first related model
(or null), `firstOrFail()`/`findOrFail()` throw
`BlueprintAU\Radiant\Exceptions\ModelNotFoundException` when the
relation matches none, `sole()` requires exactly one match (more than one
throws `MultipleRecordsFoundException`), and `count()`/`exists()` report
the constrained set. The scalar reads (`value()`, `pluck()`,
`max()`/`min()`/`sum()`/`avg()`, `aggregates()`) and the grouped
aggregates (`aggregateBy()`, `countBy()`) run against the constrained
query too.

The row reads share `get()`'s cache-awareness: after `with()`, an
unfiltered `first()`/`find()`/`sole()`/`count()`/`exists()`/`cursor()` is
served from the loaded snapshot without a query, and the fail-fasts
answer from it too (an empty snapshot throws
`ModelNotFoundException`, a snapshot with two rows throws
`MultipleRecordsFoundException`). Every row read takes `fresh: true` to
bypass the snapshot and always run the query. The scalar reads never
consult the snapshot — an aggregate is a live question.

On `morphTo()` the row reads (`first()`, `find()`, `count()`,
`exists()`, the fail-fast family) resolve the related class per row and
run against it; the scalar reads cannot compose — the related table is
not known until the type column resolves, so they throw
`LogicException`.

The row-read family also creates. `firstOrCreate()` treats the
relation's constraint as the match: the constraint's equality clauses
(the FK pointing at the parent, a morph pair's key) become fills, so
the created model always satisfies the very match that failed to find
it — `$author->posts()->firstOrCreate(['title' => 'First'])` inserts a
post carrying the parent's `user_id`. `findOrCreate($id, $values)`
finds within the constraint or creates carrying both the key and the
constraint. On `morphTo()` the resolved pair's key equality inverts
into the fill — the created target's primary key equals the parent's
morph key; an unresolved (null) pair fails the inversion. A hit reads
the model's `$wasRecentlyCreated` as `false`, a miss as `true`.

## Grouped aggregates

`countBy()` counts the related rows per group of a column in a single
`GROUP BY` query — the FK constraint rides along, so the counts only
ever cover this parent's related rows:

```php
$counts = $event->rsvps()->countBy('status');
// ['going' => 12, 'declined' => 3, 'maybe' => 5]
```

An optional seed lists group values that must appear even when the
database has no rows for them — each seeded key absent from the result
becomes `0`. The seed is additive: database rows always win, and group
values present in the data but missing from the seed still appear.

```php
$counts = $event->rsvps()->countBy('status', ['going', 'declined', 'maybe']);
```

`aggregateBy()` is the general form — any aggregate per group. The value
type follows the aggregate: `count` yields int; `sum`/`avg` over numeric
columns yield int|float; `min`/`max` yield the column's decoded type (a
datetime column yields Carbon); custom functions and `Expression`
arguments yield the raw driver value. A group whose aggregated values
are all NULL sums to `null` — the SQL-honest result, not a coerced zero.

```php
use BlueprintAU\Radiant\Database\Query\Aggregate;

$totals = $event->rsvps()->aggregateBy(Aggregate::sum('amount'), 'status');
```

Both are reads, not compositions: they never mark the relation composed,
so a later `get()` is unaffected.

The same methods exist on the query builders — `Model::newQuery()` and
`Database::table()` — with the same contracts. On a model builder the
values decode through the column casts; on a raw table builder they come
back as the driver delivered them (`count` is always an int).

## Key conventions

FK/local-key defaults follow the snake_case convention (`user_id`, the
model's primary key) and are overridable with explicit arguments. Every
column a relation names is validated against the model's declared columns
at construction — an unknown FK throws immediately.

**Composite keys.** The FK and local-key arguments accept a column list —
`hasMany(Shipment::class, ['region_id', 'country'])` — and both sides
must agree in shape (a scalar on one side and a list on the other
throws). A composite relation applies the key tuple as one AND-group, so
a caller's `orWhere()` composes against the tuple as a unit, and eager
loading matches parent and child rows positionally per column.

## Eager loading

`with()` runs one extra `whereIn(fk, keys)` query per relation — no joins,
no row multiplication, pagination stays correct:

```php
$users = User::with('posts')->get();               // static forwarder
$users = User::where('active', '=', 1)->with('posts')->get();  // mid-chain
$users->load('posts', 'followers');                // on an existing Collection
$fresh = $users->fresh();                          // re-query each model by key

$users = User::with('posts.comments')->get();      // dot-notation nests
```

An unknown relation name throws **at the `with()` call** — the typo is
caught at the call site. Loaded relations are cached on the instance
(`relationLoaded()` checks); the relation METHOD reads that cache —
`$user->posts()->get()` returns the eagerly-loaded result without
re-querying, and is typed by the method's declared return. The cache path
disengages when it must: a composed chain (`$user->posts()->where(...)`,
or `withPivot()` on a many-to-many) executes fresh (the cache was loaded
unfiltered, and pivot columns change the select shape), and a relation
not loaded on the instance always executes (lazy access is never stale).
The whole row-read family (`first()`, `find()`, the fail-fasts,
`count()`, `exists()`, `cursor()`) shares this path — a snapshot read is
fixed at load time, so rows deleted after `with()` are still in it until
a `fresh: true` read (or a fresh `get()`) re-queries.

Result shapes: eager-loaded `HasOne`/`BelongsTo` results are a single
model or `null`; `HasMany` results are a `Collection`. Dot-notation nests
to any depth (`'posts.comments.author'`), loading one extra query per path
segment.

## The model Collection

Query results come back as `BlueprintAU\Radiant\Collection` — the base
collection from `blueprintau/collections` (map, filter, pluck, … all
inherited unchanged) plus model-shaped conveniences:

```php
$post = $posts->find(42);              // by primary key, or null
$keys  = $posts->modelKeys();          // every model's PK value
$posts->load('comments');              // eager-load on an existing collection
$posts = $posts->fresh();              // re-query every model by key
```

- `find()` matches composite keys by **shape** — the same column set,
  compared pair-wise, so map ordering never matters. Numeric strings
  normalize to int before comparison (`42` matches `'42'`), but the
  comparison is otherwise strict.
- `load()` uses the same loader as `with()` — one `IN` query per relation
  path, dot-notation nests — and is a no-op on an empty collection.
- `fresh()` re-queries every model by key in **one** query and re-attaches
  rows to their original positions. A row deleted externally leaves its
  original model in place (the collection never silently shrinks while you
  iterate it) — that staleness is the documented contract. Eager loads are
  not re-applied; call `load()` again if you need relations.

## Through relations

Through relations hop via an intermediate model and are SQL-only (they
need a join):

```php
use BlueprintAU\Radiant\Relations\HasOneThrough;
use BlueprintAU\Radiant\Relations\HasManyThrough;

class Mechanic extends Model
{
    public function owner(): HasOneThrough
    {
        return $this->hasOneThrough(Owner::class, Car::class);
    }

    public function owners(): HasManyThrough
    {
        return $this->hasManyThrough(Owner::class, Car::class);
    }
}
```

**Key conventions.** `firstKey` is the **through table's** FK pointing
back at the parent (`cars.mechanic_id`); `secondKey` is the **related
table's** FK pointing at the through table (`owners.car_id`). The join
compiles `related.<secondKey> = through.<primary key>` — the second
hop's FK must therefore live on the RELATED table. A shape whose
second-hop FK lives on the THROUGH table instead (parent → through →
related where the through row carries `related_id`) cannot be expressed
as a through relation — declare a `belongsToMany` with the through
table (or a pivot model class-string) as the pivot, since both FKs
already live on it. When both hop keys are omitted they derive by
convention: `firstKey` `{parent-short-name}_id` on the through table,
`secondKey` `{through-short-name}_id` on the related table — a through
model whose class name doesn't match its FK column naming needs both
keys declared explicitly, and a `secondKey` naming a column that
doesn't exist on the related model throws at construction.

The join INNER JOINs the intermediate table — a parent with no
intermediate row legitimately has no through-result. Composite keys are
supported on both hops; the two hop keys must agree in shape (both scalar
or both composite with matching arity), or construction throws.

## Polymorphic relations

A polymorphic relation lets one child table belong to models of MANY
classes. The child carries a `(type, key)` pair — `{name}_type` holds the
parent's class-string (the **morph alias**), `{name}_id` its key value.

Declare the columns with the `#[Morphs]` class attribute (synthetic
columns — no PHP properties needed; values ride the model's attribute
store):

```php
use BlueprintAU\Radiant\Attributes\Morphs;

#[Morphs(name: 'commentable', nullable: true)]
class Comment extends Model
{
    public function commentable(): MorphTo
    {
        return $this->morphTo('commentable');   // columns: commentable_type, commentable_id
    }
}

class Post extends Model
{
    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    public function image(): MorphOne
    {
        return $this->morphOne(Image::class, 'imageable');
    }
}
```

The morph alias is the parent model's **full class-string** — unambiguous
across namespaces. Renaming a class changes the stored alias; migrating
the column values is the host's data-migration concern.

Eager loading dispatches **per type**: parents are grouped by their type
value and one chunked `IN` query runs per distinct class, so each type's
rows hydrate through its own model.

**Static typing.** Without an allowlist, `morphTo` results are the honest
`Model|null` (the related class is dynamic) — narrow with a local
`instanceof` after reading `commentable()->get()->first()`. With
an allowlist, the relation narrows statically: declare the list in the
relation method and the reads deliver exactly those classes,

```php
use BlueprintAU\Radiant\Relations\MorphTo;

class Comment extends Model
{
    /**
     * @return MorphTo<Post|Video> Reads type as (Post|Video)|null —
     *         no local instanceof needed.
     */
    public function commentable(): MorphTo
    {
        return $this->morphTo('commentable', types: [Post::class, Video::class]);
    }
}
```

The template is backed by the runtime: any type value outside the list
still fails fast at resolution. The narrowing can also ride a helper —
a method taking `list<class-string<T>>` and returning `MorphTo<T>`
infers `T` from the caller's list, so one shared helper serves every
allowlisted morph.

Fail-fast semantics: a null type column resolves empty (an optional morph
target); an unknown class, a non-model class, or a non-string type value
throws; on `morphTo` the check that a target's primary-key type matches
the key column's declared type runs at read/eager **resolution** time
(the related class is dynamic until then) — on the direct directions
(`morphMany`/`morphOne`) the equivalent check runs at construction.

## Many-to-many relations

`belongsToMany` links two models through a pivot table:

```php
class Post extends Model
{
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
        // pivot: posts_tags (deterministic {parentTable}_{relatedTable});
        // keys: posts_id, tags_id — all overridable.
    }
}
```

The relation query INNER JOINs the pivot (SQL-only). Pivot data rides the
select: `withPivot('position')` carries the column onto each related
model, readable through `$tag->pivotValue('position')`;
`withTimestamps()` is sugar for the `created_at`/`updated_at` pair.

The pivot `$table` argument accepts a model class-string as well as a
plain name — the table derives from the class's own metadata
(`belongsToMany(Tag::class, table: PostTag::class)` on a `post_tags`
pivot). The model itself is optional sugar: the relation needs no pivot
PHP model to function, and a class whose table collides with either
endpoint's table is rejected at construction.

**The pivot table is yours.** Radiant derives its NAME
(`{parentTable}_{relatedTable}`, overridable) but never creates, migrates,
or syncs the table itself — `Blueprint::fromMetadata()` folds model
attributes into blueprints, and a pivot has no model, so it never enters
the schema-sync desired state. Create it like any other table:

```php
use BlueprintAU\Radiant\Database\Schema\Enums\ColumnType;

$conn->create(
    (new Blueprint('posts_tags'))
        ->foreignId('posts_id', 'posts.id')
        ->foreignId('tags_id', 'tags.id')
        ->column(ColumnType::String, 'position', length: 16, nullable: true),
);
```

The write API (`attach`/`detach`/`sync`/`toggle`) assumes the columns it
writes exist — a missing pivot column fails at the database, not silently.

**Reserved prefix.** Column names starting with `radiant_` are reserved —
the ORM uses that prefix for its internal select aliases
(`radiant_pivot_{column}`, `radiant_pivot_parent_{table}`,
`radiant_scalar`, `radiant_through_parent_{table}`), and any row field
with that prefix is treated as internal bookkeeping rather than model
data. `withPivot()` rejects a reserved pivot column name; a declared
model column with the prefix collides the same way the moment it rides a
reserved alias — name your columns anything else.

**Joined reads never collide.** As soon as a join touches a model query
with a bare select, the ORM qualifies that model's own columns — a bare
star becomes `SELECT "tags".* FROM "tags" INNER JOIN ...` and bare
column names prefix with the table (`"tags"."id"`), so every compiled
spec names its table. Duplicate column names across joined tables —
most commonly every table's `id` — would otherwise collapse last-wins
in the fetched row and hydrate the wrong table's values through this
model's casts. Ordering never matters: selecting before or after the
join lands on the same qualified list. The pivot's columns ride a read
only through `withPivot()` aliases; the model's own select is never
polluted with them.

The write API operates on the pivot directly:

```php
$post->tags()->attach(3);                       // or a list, or id => attributes
$post->tags()->detach(3);                       // null detaches all
$post->tags()->sync([2, 3]);                    // → ['attached' => [3], 'detached' => [1], 'updated' => []]
$post->tags()->toggle([2, 3]);
$post->tags()->syncWithoutDetaching([3]);
```

`sync()` runs inside a transaction and computes the exact diff; a list
input applies no attributes, a map input (`[1 => ['position' => 'x'], 2]`)
attaches/updates per id. Both models must declare a single named primary
key — pivot keys are scalar-only.

**Uniform attribute maps.** `attach()` compiles its rows into ONE bulk
INSERT, so every id's attribute map must carry the same keys — a mixed
map (`[1 => ['position' => 'x'], 2]`) throws. Give bare ids an explicit
empty-attribute twin with the same keys (`2 => ['position' => null]`), or
attach them in a separate call.

**Polymorphic many-to-many** shares one pivot across parent classes: the
pivot's parent side is a `(type, key)` pair.

```php
#[Morphs(name: 'taggable')]
class Post extends Model
{
    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable');
        // pivot: taggable (the morph name itself — identical across all
        // directions and parent classes); columns: taggable_id,
        // taggable_type, tags_id.
    }
}

class Tag extends Model
{
    public function posts(): MorphToMany
    {
        return $this->morphedByMany(Post::class, 'taggable');  // the inverse direction
    }
}
```

Every query filters the pivot's type column to the morph alias — the
parent's class-string on the direct side, the related class's on the
inverse (`morphedByMany`) — and `attach()`/`sync()` stamp that alias
onto every inserted row.

The inverse side can also read the SHARED pool across every morph type —
`pool()` resolves one query per type through the shared pivot columns,
hydrating each row through its own model. Declare an allowlist to
restrict the read to exactly those classes (every other stored alias is
ignored, the way `morphTo`'s allowlist restricts resolution) and the
same list narrows the static bound:

```php
class Tag extends Model
{
    /** @return MorphToMany<Post, Post|Video> pool(): Collection<Post|Video> */
    public function postables(): MorphToMany
    {
        return $this->morphedByMany(Post::class, 'taggable',
            poolTypes: [Post::class, Video::class]);
    }
}

$tag->postables()->pool();        // every Post AND Video row this tag links
$tag->postables()->get();         // unchanged — the single-typed Post read
```

Without an allowlist the pool resolves every distinct stored type (the
honest `Model` bound), failing fast on a stored alias that is not a
model class. `pool()` is a read, not a composition: it never serves the
eager-loaded snapshot and ignores relation filters.

## Portability and semantics

HasOne/HasMany/BelongsTo and the polymorphic family (MorphOne/MorphMany/
MorphTo) ride the portable core (`whereIn` + `select`) and work on any
backend, CSV included. Through relations, `belongsToMany`, and
`morphToMany` need joins and are SQL-only — a non-SQL connection throws
`UnsupportedFeatureException` at execution (the pivot write API fails the
same way).

Fail-fast semantics:

- A `BelongsTo` over a null FK yields no results — a legitimately optional
  relation, not an error.
- A `HasOne` over duplicate rows takes the first (stably ordered by the
  related PK) — uniqueness is the schema's job.
