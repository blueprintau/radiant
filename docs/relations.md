# Relations

Declaring and loading relationships between models. Relation methods
return relation objects; the method name is the relation's key.

- [Declaring a relation](#declaring-a-relation)
- [Filtering and composing](#filtering-and-composing)
- [Key conventions](#key-conventions)
- [Eager loading](#eager-loading)
- [The model Collection](#the-model-collection)
- [Through relations](#through-relations)
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

The relation also exposes fail-fast reads: `firstOrFail()` returns the
first related model or throws
`BlueprintAU\Radiant\Database\Exceptions\ModelNotFoundException` when the
relation matches none, and `sole()` requires exactly one match (more than
one throws `MultipleRecordsFoundException`).

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
(`relationLoaded()` / `getRelation()`); lazy access through the relation
method always executes fresh.

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

## Portability and semantics

HasOne/HasMany/BelongsTo ride the portable core (`whereIn` + `select`) and
work on any backend, CSV included.

Fail-fast semantics:

- A `BelongsTo` over a null FK yields no results — a legitimately optional
  relation, not an error.
- A `HasOne` over duplicate rows takes the first (stably ordered by the
  related PK) — uniqueness is the schema's job.
