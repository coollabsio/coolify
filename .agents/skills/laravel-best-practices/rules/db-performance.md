# Database Performance Best Practices

## Eager Load Relationships Before Iterating

When a relationship will be accessed for many models, eager load it with `with()` to avoid running one initial query plus one relationship query per model, commonly called an N+1 query pattern. Lazy loading is reasonable when the relationship may not be needed or only one model is involved.

Lazy-loaded version:

```php
$posts = Post::all();
foreach ($posts as $post) {
    echo $post->author->name;
}
```

Eager-loaded version:

```php
$posts = Post::with('author')->get();
foreach ($posts as $post) {
    echo $post->author->name;
}
```

Constrain eager loads when large columns are unnecessary. Include the related model's primary key and every column Eloquent needs to match the relationship. In this example, `users.id` and `posts.user_id` match posts to users, while selecting `posts.id` preserves each related model's primary key:

```php
$users = User::with(['posts' => function ($query) {
    $query->select('id', 'user_id', 'title')
          ->where('published', true)
          ->latest()
          ->limit(10);
}])->get();
```

## Prevent Lazy Loading in Development

Enable this in `AppServiceProvider::boot()` to catch N+1 issues during development.

```php
public function boot(): void
{
    Model::preventLazyLoading(! app()->isProduction());
}
```

By default, accessing an unloaded relationship then throws a `LazyLoadingViolationException`. Applications can customize violation handling with `handleLazyLoadingViolationUsing()`.

## Select Only Needed Columns

Select only the columns the operation needs when omitting large text, binary, or JSON columns provides a meaningful benefit.

All columns:

```php
$posts = Post::with('author')->get();
```

Selected columns:

```php
$posts = Post::select('id', 'title', 'user_id', 'created_at')
    ->with(['author:id,name,avatar'])
    ->get();
```

When limiting selected columns, retain every key Eloquent needs for matching. A `belongsTo` relationship needs its foreign key on the parent query and the owner's key on the related query. A `hasMany` relationship needs the parent's local key and the related model's foreign key.

## Process Large Data Sets Incrementally

Use chunking or lazy iteration when loading an entire result set would exceed the application's practical memory budget.

Loads the complete result set:

```php
$users = User::all();
foreach ($users as $user) {
    $user->notify(new WeeklyDigest);
}
```

Processes bounded chunks:

```php
User::where('subscribed', true)->chunk(200, function ($users) {
    foreach ($users as $user) {
        $user->notify(new WeeklyDigest);
    }
});
```

Use `chunkById()` when updates can change which rows match the query. Standard `chunk()` uses offset pagination, whose result positions can shift as rows change:

```php
User::where('active', false)->chunkById(200, function ($users) {
    $users->each->delete();
});
```

For read-only, attribute-only iteration, `cursor()` hydrates models individually from one query, although some database drivers still buffer raw results. Use `lazy()` when relationships must be eager loaded in chunks, and use `lazyById()` or `chunkById()` when updates can affect query membership. See the collection rules for detailed tradeoffs.

## Add Indexes for Measured Query Patterns

Design indexes around frequent, performance-sensitive query patterns. A column's presence in `WHERE`, `ORDER BY`, `JOIN`, or `GROUP BY` does not by itself justify an index; selectivity, write cost, existing indexes, and the database query plan all matter.

Schema without an application-specific query index:

```php
Schema::create('orders', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained();
    $table->string('status');
    $table->timestamps();
});
```

Schema optimized for `WHERE status = ? ORDER BY created_at`:

```php
Schema::create('orders', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained();
    $table->string('status');
    $table->timestamps();
    $table->index(['status', 'created_at']);
});
```

Confirm composite index column order and effectiveness with production-like data and the database's query-plan tools. Also check whether the database already created an index to support a foreign key before adding another one.

## Count Relationships Without Loading Them

Use `withCount()` when only relationship counts are needed; loading and hydrating every related model wastes memory.

Loads related models:

```php
$posts = Post::all();
foreach ($posts as $post) {
    echo $post->comments->count();
}
```

Selects relationship counts:

```php
$posts = Post::withCount('comments')->get();
foreach ($posts as $post) {
    echo $post->comments_count;
}
```

Conditional counting:

```php
$posts = Post::withCount([
    'comments',
    'comments as approved_comments_count' => function ($query) {
        $query->where('approved', true);
    },
])->get();
```

## Keep Queries Out of Blade Templates

Prepare data before rendering a Blade template, such as in a controller, query service, or view composer. This keeps query behavior visible and testable.

Query in the template:

```blade
@foreach (User::all() as $user)
    {{ $user->profile->name }}
@endforeach
```

Data prepared before rendering:

```php
// Controller
$users = User::with('profile')->get();

return view('users.index', compact('users'));
```

```blade
@foreach ($users as $user)
    {{ $user->profile->name }}
@endforeach
```
