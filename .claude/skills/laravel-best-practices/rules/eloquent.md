# Eloquent Best Practices

## Define Precise Relationship Types

Define the relationship that matches the database association, and declare its concrete return type.

```php
public function comments(): HasMany
{
    return $this->hasMany(Comment::class);
}

public function author(): BelongsTo
{
    return $this->belongsTo(User::class, 'user_id');
}
```

## Use Local Scopes for Reusable Queries

Extract reusable query constraints into local scopes to avoid duplication.

Duplicated constraints:

```php
$active = User::where('verified', true)->whereNotNull('activated_at')->get();
$articles = Article::whereHas('user', function ($q) {
    $q->where('verified', true)->whereNotNull('activated_at');
})->get();
```

Reusable local scope:

```php
#[Scope]
protected function active(Builder $query): Builder
{
    return $query->where('verified', true)->whereNotNull('activated_at');
}

// Usage
$active = User::active()->get();
$articles = Article::whereHas('user', fn ($q) => $q->active())->get();
```

## Apply Global Scopes Sparingly

Global scopes silently modify every query on the model, making debugging difficult. Prefer local scopes and reserve global scopes for truly universal constraints like soft deletes or multi-tenancy.

Global scope tradeoff:

```php
class PublishedScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where('published', true);
    }
}

// Admin panels, reports, and jobs now omit drafts unless the scope is removed.
```

Explicit local scope:

```php
#[Scope]
protected function published(Builder $query): Builder
{
    return $query->where('published', true);
}

Post::published()->paginate(); // Explicit
Post::paginate(); // Admin sees all
```

## Define Attribute Casts

Use the `casts()` method (or `$casts` property following project convention) for automatic type conversion.

```php
protected function casts(): array
{
    return [
        'is_active' => 'boolean',
        'metadata' => 'array',
        'total' => 'decimal:2',
    ];
}
```

## Cast Date and Time Attributes

Cast a date or timestamp attribute when application code should treat it as a Carbon instance. Eloquent already casts the conventional `created_at` and `updated_at` timestamps.

Manual parsing in the template:

```blade
{{ Carbon::parse($order->ordered_at)->toDateString() }}
```

Model cast:

```php
protected function casts(): array
{
    return [
        'ordered_at' => 'datetime',
    ];
}
```

```blade
{{ $order->ordered_at->toDateString() }}
{{ $order->ordered_at->format('m-d') }}
```

## Use `whereBelongsTo()` for Relationship Queries

`whereBelongsTo()` expresses the relationship constraint without manually specifying its foreign key.

Foreign key constraint:

```php
Post::where('user_id', $user->id)->get();
```

Relationship-aware constraint:

```php
Post::whereBelongsTo($user)->get();
Post::whereBelongsTo($user, 'author')->get();
```

## Keep Application Queries Model-Aware

Prefer Eloquent models and relationships for model-backed application queries. They preserve casts, scopes, and model table configuration. The query builder and raw SQL legitimately require table names, so use them when their lower-level behavior is intentional.

Lower-level alternatives:

```php
DB::table('users')->where('active', true)->get();

$query->join('companies', 'companies.id', '=', 'users.company_id');

DB::select('SELECT * FROM orders WHERE status = ?', ['pending']);
```

Model-aware queries:

```php
User::where('active', true)->get();
Order::where('status', 'pending')->get();
```

When a query builder operation should follow a model's configured table name, use `(new User)->getTable()`. For complex joins or raw SQL, explicit table names may be clearer; keep those references covered by tests when schema changes are possible.

In migrations, use explicit table names rather than application models. Migrations are historical snapshots, while models and their scopes can change after a migration is deployed.
