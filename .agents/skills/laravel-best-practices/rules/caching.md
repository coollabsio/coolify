# Caching Best Practices

## Use `Cache::remember()` Instead of Manual Get/Put

Cleaner cache-aside pattern that removes boilerplate. use `Cache::lock()` for race conditions.

Incorrect:
```php
$val = Cache::get('stats');
if (! $val) {
    $val = $this->computeStats();
    Cache::put('stats', $val, 60);
}
```

Correct:
```php
$val = Cache::remember('stats', 60, fn () => $this->computeStats());
```

## Use `Cache::flexible()` for Stale-While-Revalidate

On high-traffic keys, one user always gets a slow response when the cache expires. `flexible()` serves slightly stale data while refreshing in the background.

Incorrect: `Cache::remember('users', 300, fn () => User::all());`

Correct: `Cache::flexible('users', [300, 600], fn () => User::all());` — fresh for 5 min, stale-but-served up to 10 min, refreshes via deferred function.

## Use `Cache::memo()` to Avoid Redundant Hits Within a Request

If the same cache key is read multiple times per request (e.g., a service called from multiple places), `memo()` stores the resolved value in memory.

`Cache::memo()->get('settings');` — 5 calls = 1 Redis round-trip instead of 5.

## Use Cache Tags to Invalidate Related Groups

Without tags, invalidating a group of entries requires tracking every key. Tags let you flush atomically. Only works with `redis`, `memcached`, `dynamodb` — not `file` or `database`.

```php
Cache::tags(['user-1'])->flush();
```

## Use `Cache::add()` for Atomic Conditional Writes

`add()` only writes if the key does not exist — atomic, no race condition between checking and writing.

Incorrect: `if (! Cache::has('lock')) { Cache::put('lock', true, 10); }`

Correct: `Cache::add('lock', true, 10);`

## Use `once()` for Per-Request Memoization

`once()` memoizes a function's return value for the lifetime of the object (or request for closures). Unlike `Cache::memo()`, it doesn't hit the cache store at all — pure in-memory.

```php
public function roles(): Collection
{
    return once(fn () => $this->loadRoles());
}
```

Multiple calls return the cached result without re-executing. Use `once()` for expensive computations called multiple times per request. Use `Cache::memo()` when you also want cross-request caching.

## Configure Failover Cache Stores in Production

If Redis goes down, the app falls back to a secondary store automatically.

```php
'failover' => ['driver' => 'failover', 'stores' => ['redis', 'database']],
```
