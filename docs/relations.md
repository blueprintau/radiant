# Relations

Declaring and loading relationships between models. Relation methods
return relation objects; the method name is the relation's key.

- [Declaring a relation](#declaring-a-relation)
- [Filtering and composing](#filtering-and-composing)
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
`orWhere`, `whereIn`, `whereNull`, `whereBetween`, `orderBy`, `limit`,
`offset` — all validated against the related model), so composition
happens right on the relation:

```php
foreach ($user->posts()->orderBy('created_at')->getResults() as $post) { ... }
$recent = $user->posts()->where('active', '=', 1)->limit(5)->getResults();
```

**Relations are immutable.** Every filter and configurator (`withPivot()`,
`withTimestamps()`) returns a NEW relation — the original is never
modified, and a discarded call is a no-op. Composition can never poison
the shared cached prototype: the cache holds the un-composed original,
and a composed chain always executes fresh.

The relation also exposes fail-fast reads: `firstOrFail()` returns the
first related model or throws
`BlueprintAU\Radiant\Database\Exceptions\ModelNotFoundException` when the
relation matches none, and `sole()` requires exactly one match (more than
one throws `MultipleRecordsFoundException`).

`getResults()` and the fail-fast reads differ in one way: `getResults()`
is cache-aware (see [Eager loading](#eager-loading) — after `with()`, the
unfiltered read returns the loaded result), while `firstOrFail()` and
`sole()` always execute against the database.

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
`$user->posts()->getResults()` returns the eagerly-loaded result without
re-querying, and is typed by the method's declared return. The cache path
disengages when it must: a composed chain (`$user->posts()->where(...)`,
or `withPivot()` on a many-to-many) executes fresh (the cache was loaded
unfiltered, and pivot columns change the select shape), and a relation
not loaded on the instance always executes (lazy access is never stale).
The fail-fast reads `firstOrFail()`/`sole()` never consult the cache.

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
`instanceof` after reading `commentable()->getResults()->first()`. With
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
throws.

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

**Reserved prefix.** Column names starting with `radiant_` are reserved —
the ORM namespaces its internal select aliases there
(`radiant_pivot_{column}`, `radiant_pivot_parent_{table}`,
`radiant_scalar`, `radiant_through_parent_{table}`), and the hydration
lift treats any row field with that prefix as internal state. `withPivot()`
fails fast on a reserved pivot column name; a declared model column with
the prefix collides the same way the moment it rides a reserved alias —
name your columns anything else.

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

Every query filters the pivot's type column to the parent's class-string,
and `attach()`/`sync()` stamp the alias onto every inserted row.

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
