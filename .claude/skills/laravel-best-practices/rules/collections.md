# Collection Best Practices

## Use Higher-Order Messages for Simple Operations

Explicit closure:

```php
$users->each(function (User $user) {
    $user->markAsVip();
});
```

Concise equivalent:

```php
$users->each->markAsVip();
```

Higher-order messages are available for supported collection methods such as `each`, `map`, `filter`, and `sum`. Use an explicit closure when arguments or nontrivial logic would be clearer.

## Choose Between `cursor()` and `lazy()`

`cursor()` executes one query and hydrates models individually, but it cannot eager load relationships. The database driver's result buffering can still consume substantial memory for very large results. Use it for low-memory, attribute-only iteration when one long-running query is acceptable.

`lazy()` executes multiple chunked queries and returns a flat `LazyCollection`. It supports eager loading relationships for each chunk and avoids holding one database cursor open for the entire iteration.

With relationships:

```php
User::with('roles')->lazy()->each(function (User $user) {
    // The roles for this chunk have been eager loaded.
});
```

Without relationships:

```php
User::cursor()->each(function (User $user) {
    // Process model attributes.
});
```

## Use `lazyById()` When Updating Records While Iterating

`lazy()` uses offset pagination, so updates to columns that affect the query can shift rows and cause records to be skipped or processed twice. `lazyById()` paginates by a monotonic key and is safer when updating other columns during iteration. Do not change the pagination key itself while iterating.

## Use `toQuery()` for Bulk Operations on Collections

Use `toQuery()` to build a query from the models in an Eloquent collection instead of manually constructing a `whereIn` clause.

Manual query:

```php
User::whereIn('id', $users->modelKeys())->update(['active' => false]);
```

Collection query:

```php
$users->toQuery()->update(['active' => false]);
```

`toQuery()` requires a non-empty Eloquent collection whose models are of the same type. Like other bulk Eloquent updates, it does not dispatch per-model update events, so use it only when those events are not required.

## Use `#[CollectedBy]` for Custom Collection Classes

The `#[CollectedBy]` attribute declares the custom collection class without requiring a `newCollection()` override.

```php
#[CollectedBy(UserCollection::class)]
class User extends Model {}
```
