# Advanced Query Patterns

## Use `addSelect()` Subqueries for Single Values from Has-Many

Instead of eager-loading an entire has-many relationship for a single value (like the latest timestamp), use a correlated subquery via `addSelect()`. This pulls the value directly in the main SQL query — zero extra queries.

```php
public function scopeWithLastLoginAt($query): void
{
    $query->addSelect([
        'last_login_at' => Login::select('created_at')
            ->whereColumn('user_id', 'users.id')
            ->latest()
            ->take(1),
    ])->withCasts(['last_login_at' => 'datetime']);
}
```

## Create Dynamic Relationships via Subquery FK

Extend the `addSelect()` pattern to fetch a foreign key via subquery, then define a `belongsTo` relationship on that virtual attribute. This provides a fully-hydrated related model without loading the entire collection.

```php
public function lastLogin(): BelongsTo
{
    return $this->belongsTo(Login::class);
}

public function scopeWithLastLogin($query): void
{
    $query->addSelect([
        'last_login_id' => Login::select('id')
            ->whereColumn('user_id', 'users.id')
            ->latest()
            ->take(1),
    ])->with('lastLogin');
}
```

## Use Conditional Aggregates Instead of Multiple Count Queries

Replace N separate `count()` queries with a single query using `CASE WHEN` inside `selectRaw()`. Use `toBase()` to skip model hydration when you only need scalar values.

```php
$statuses = Feature::toBase()
    ->selectRaw("count(case when status = 'Requested' then 1 end) as requested")
    ->selectRaw("count(case when status = 'Planned' then 1 end) as planned")
    ->selectRaw("count(case when status = 'Completed' then 1 end) as completed")
    ->first();
```

## Use `setRelation()` to Prevent Circular N+1

When a parent model is eager-loaded with its children, and the view also needs `$child->parent`, use `setRelation()` to inject the already-loaded parent rather than letting Eloquent fire N additional queries.

```php
$feature->load('comments.user');
$feature->comments->each->setRelation('feature', $feature);
```

## Prefer `whereIn` + Subquery Over `whereHas`

`whereHas()` emits a correlated `EXISTS` subquery that re-executes per row. Using `whereIn()` with a `select('id')` subquery lets the database use an index lookup instead, without loading data into PHP memory.

Incorrect (correlated EXISTS re-executes per row):

```php
$query->whereHas('company', fn ($q) => $q->where('name', 'like', $term));
```

Correct (index-friendly subquery, no PHP memory overhead):

```php
$query->whereIn('company_id', Company::where('name', 'like', $term)->select('id'));
```

## Sometimes Two Simple Queries Beat One Complex Query

Running a small, targeted secondary query and passing its results via `whereIn` is often faster than a single complex correlated subquery or join. The additional round-trip is worthwhile when the secondary query is highly selective and uses its own index.

## Use Compound Indexes Matching `orderBy` Column Order

When ordering by multiple columns, create a single compound index in the same column order as the `ORDER BY` clause. Individual single-column indexes cannot combine for multi-column sorts — the database will filesort without a compound index.

```php
// Migration
$table->index(['last_name', 'first_name']);

// Query — column order must match the index
User::query()->orderBy('last_name')->orderBy('first_name')->paginate();
```

## Use Correlated Subqueries for Has-Many Ordering

When sorting by a value from a has-many relationship, avoid joins (they duplicate rows). Use a correlated subquery inside `orderBy()` instead, paired with an `addSelect` scope for eager loading.

```php
public function scopeOrderByLastLogin($query): void
{
    $query->orderByDesc(Login::select('created_at')
        ->whereColumn('user_id', 'users.id')
        ->latest()
        ->take(1)
    );
}
```