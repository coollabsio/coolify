# HTTP Client Best Practices

## Set Explicit Timeouts

Laravel's HTTP client has a 30-second response timeout by default. Choose response and connection timeouts that fit the service and the calling request or job. Remember that retries can multiply the total elapsed time.

Less resilient:

```php
$response = Http::get('https://api.example.com/users');
```

Preferred:

```php
$response = Http::connectTimeout(3)
    ->timeout(5)
    ->get('https://api.example.com/users');
```

Define shared settings in a macro or a dedicated client:

```php
Http::macro('github', function () {
    return Http::baseUrl('https://api.github.com')
        ->connectTimeout(3)
        ->timeout(10)
        ->withToken(config('services.github.token'));
});

$response = Http::github()->get('/repos/laravel/framework');
```

## Retry Only Safe Operations

Retry transient connection failures, rate-limit responses, and server errors with an appropriate delay. Retry idempotent requests such as `GET` when the operation can safely run more than once. Retry a state-changing request only when the remote API supports an idempotency key or provides equivalent duplicate protection.

Unsafe without an idempotency guarantee:

```php
$response = Http::retry([100, 500, 1000])
    ->post('https://api.example.com/v1/charges', $data);
```

Safe for an idempotent request:

```php
$response = Http::connectTimeout(3)
    ->timeout(10)
    ->retry([100, 500, 1000], 0, function (Throwable $exception) {
        return $exception instanceof ConnectionException
            || ($exception instanceof RequestException
                && ($exception->response->serverError() || $exception->response->status() === 429));
    })
    ->get('https://api.example.com/data');
```

For a supported state-changing API, send a stable idempotency key for every attempt:

```php
$response = Http::withHeaders(['Idempotency-Key' => $paymentAttempt->uuid])
    ->connectTimeout(3)
    ->timeout(10)
    ->retry([100, 500, 1000], 0, function (Throwable $exception) {
        return $exception instanceof ConnectionException
            || ($exception instanceof RequestException
                && ($exception->response->serverError() || $exception->response->status() === 429));
    })
    ->post('https://api.example.com/v1/charges', $data);
```

## Handle Errors Explicitly

The HTTP client returns responses for `4xx` and `5xx` status codes instead of throwing by default. Inspect the expected statuses or call `throw()` before consuming a success payload.

Unsafe when a success payload is expected:

```php
$user = Http::get('https://api.example.com/users/1')->json();
```

Preferred:

```php
$user = Http::connectTimeout(3)
    ->timeout(5)
    ->get('https://api.example.com/users/1')
    ->throw()
    ->json();
```

Handle expected alternatives explicitly when graceful degradation is required:

```php
$response = Http::connectTimeout(3)
    ->timeout(5)
    ->get('https://api.example.com/users/1');

if ($response->successful()) {
    return $response->json();
}

if ($response->notFound()) {
    return null;
}

$response->throw();
```

## Pool Independent Requests

Use `Http::pool()` when several independent requests can run concurrently. Pooling changes execution time, not error handling; inspect or throw for each response as needed.

```php
use Illuminate\Http\Client\Pool;

$responses = Http::pool(fn (Pool $pool) => [
    $pool->as('users')->connectTimeout(3)->timeout(5)
        ->get('https://api.example.com/users'),
    $pool->as('posts')->connectTimeout(3)->timeout(5)
        ->get('https://api.example.com/posts'),
]);

$users = $responses['users']->throw()->json();
$posts = $responses['posts']->throw()->json();
```

## Fake HTTP Requests in Tests

Use `Http::fake()` for external integrations, and use `Http::preventStrayRequests()` when an unexpected real request should fail the test. Also test timeouts, connection failures, and error responses that the application handles.

```php
it('syncs a user from the API', function () {
    Http::preventStrayRequests();

    Http::fake([
        'api.example.com/users/1' => Http::response([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]),
    ]);

    (new UserSyncService)->sync(1);

    Http::assertSent(fn (Request $request) =>
        $request->url() === 'https://api.example.com/users/1'
    );
});
```

For example, fake a connection failure when testing the integration's failure path:

```php
Http::fake([
    'api.example.com/*' => Http::failedConnection(),
]);
```
