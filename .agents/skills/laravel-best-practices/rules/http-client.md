# HTTP Client Best Practices

## Always Set Explicit Timeouts

The default timeout is 30 seconds — too long for most API calls. Always set explicit `timeout` and `connectTimeout` to fail fast.

Incorrect:
```php
$response = Http::get('https://api.example.com/users');
```

Correct:
```php
$response = Http::timeout(5)
    ->connectTimeout(3)
    ->get('https://api.example.com/users');
```

For service-specific clients, define timeouts in a macro:

```php
Http::macro('github', function () {
    return Http::baseUrl('https://api.github.com')
        ->timeout(10)
        ->connectTimeout(3)
        ->withToken(config('services.github.token'));
});

$response = Http::github()->get('/repos/laravel/framework');
```

## Use Retry with Backoff for External APIs

External APIs have transient failures. Use `retry()` with increasing delays.

Incorrect:
```php
$response = Http::post('https://api.stripe.com/v1/charges', $data);

if ($response->failed()) {
    throw new PaymentFailedException('Charge failed');
}
```

Correct:
```php
$response = Http::retry([100, 500, 1000])
    ->timeout(10)
    ->post('https://api.stripe.com/v1/charges', $data);
```

Only retry on specific errors:

```php
$response = Http::retry(3, 100, function (Exception $exception, PendingRequest $request) {
    return $exception instanceof ConnectionException
        || ($exception instanceof RequestException && $exception->response->serverError());
})->post('https://api.example.com/data');
```

## Handle Errors Explicitly

The HTTP Client does not throw on 4xx/5xx by default. Always check status or use `throw()`.

Incorrect:
```php
$response = Http::get('https://api.example.com/users/1');
$user = $response->json(); // Could be an error body
```

Correct:
```php
$response = Http::timeout(5)
    ->get('https://api.example.com/users/1')
    ->throw();

$user = $response->json();
```

For graceful degradation:

```php
$response = Http::get('https://api.example.com/users/1');

if ($response->successful()) {
    return $response->json();
}

if ($response->notFound()) {
    return null;
}

$response->throw();
```

## Use Request Pooling for Concurrent Requests

When making multiple independent API calls, use `Http::pool()` instead of sequential calls.

Incorrect:
```php
$users = Http::get('https://api.example.com/users')->json();
$posts = Http::get('https://api.example.com/posts')->json();
$comments = Http::get('https://api.example.com/comments')->json();
```

Correct:
```php
use Illuminate\Http\Client\Pool;

$responses = Http::pool(fn (Pool $pool) => [
    $pool->as('users')->get('https://api.example.com/users'),
    $pool->as('posts')->get('https://api.example.com/posts'),
    $pool->as('comments')->get('https://api.example.com/comments'),
]);

$users = $responses['users']->json();
$posts = $responses['posts']->json();
```

## Fake HTTP Calls in Tests

Never make real HTTP requests in tests. Use `Http::fake()` and `preventStrayRequests()`.

Incorrect:
```php
it('syncs user from API', function () {
    $service = new UserSyncService;
    $service->sync(1); // Hits the real API
});
```

Correct:
```php
it('syncs user from API', function () {
    Http::preventStrayRequests();

    Http::fake([
        'api.example.com/users/1' => Http::response([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]),
    ]);

    $service = new UserSyncService;
    $service->sync(1);

    Http::assertSent(function (Request $request) {
        return $request->url() === 'https://api.example.com/users/1';
    });
});
```

Test failure scenarios too:

```php
Http::fake([
    'api.example.com/*' => Http::failedConnection(),
]);
```