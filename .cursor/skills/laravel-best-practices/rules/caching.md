# Caching Best Practices

## Use `Cache::remember()` for Cache-Aside Reads

`Cache::remember()` implements a cache-aside read without a separate truthiness check. It does not prevent concurrent requests from computing the same missing value; use an atomic lock when duplicate computation must be prevented.

The manual version below incorrectly treats valid falsy values, such as `false` or `0`, as cache misses.

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

## Consider `Cache::flexible()` for Stale-While-Revalidate

For frequently read keys, `Cache::flexible()` can serve stale data during a defined stale period and register a deferred refresh. During an HTTP request, that refresh normally runs after the response; it is not a durable background job. Once the stale period has elapsed, the request recomputes the value synchronously.

Synchronous expiration:

```php
Cache::remember('users', 300, fn () => User::all());
```

Stale-while-revalidate tradeoff:

```php
Cache::flexible('users', [300, 600], fn () => User::all());
```

This value is fresh for five minutes and may be served stale until ten minutes after it was cached.

## Use `Cache::memo()` to Avoid Redundant Hits Within an Execution

If the same cache key is read repeatedly during one request or job, `memo()` decorates a cache store and retains resolved values in memory for that execution.

```php
$settings = Cache::memo()->get('settings');
```

Repeated reads through the same memoized store avoid additional store lookups. Writes through the memoized store update or invalidate its in-memory values as appropriate.

## Use Cache Tags to Invalidate Related Groups

Tags group related entries for invalidation without tracking each key. Cache tags are not supported by the `file`, `dynamodb`, or `database` drivers; confirm support before choosing a store.

```php
Cache::tags(['user-1'])->flush();
```

## Use `Cache::add()` for Atomic Conditional Writes

`add()` atomically writes a value only when the key does not already exist.

Incorrect:

```php
if (! Cache::has('lock')) {
    Cache::put('lock', true, 10);
}
```

Correct:

```php
Cache::add('lock', true, 10);
```

Use `Cache::lock()` rather than an ordinary cache key when lock ownership and safe release are required.

## Use `once()` for In-Process Memoization

`once()` memoizes a callback's return value for the current request or job. Calls made from an object instance are scoped to that instance. Unlike `Cache::memo()`, `once()` does not read from an external cache store.

```php
public function roles(): Collection
{
    return once(fn () => $this->loadRoles());
}
```

Repeated calls return the memoized result without rerunning the callback. Use `once()` for repeated computation within one execution. Use `Cache::memo()` to memoize access to an underlying store that can also persist values across executions.

## Configure Failover Cache Stores in Production

The failover driver tries each configured store in order when a store operation throws an exception. It does not consult later stores for an ordinary cache miss, and data is not replicated between stores.

```php
'failover' => ['driver' => 'failover', 'stores' => ['redis', 'database']],
```
