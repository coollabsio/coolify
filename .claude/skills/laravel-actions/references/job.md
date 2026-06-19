# Job Entrypoint (`dispatch`, `asJob`)

## Scope

Use this reference when running an action through queues.

## Recap

- Lists async/sync dispatch helpers and conditional dispatch variants.
- Covers job wrapping/chaining with `makeJob`, `makeUniqueJob`, and `withChain`.
- Documents queue assertion helpers for tests (`assertPushed*`).
- Summarizes `JobDecorator` hooks/properties for retries, uniqueness, timeout, and failure handling.

## Recommended pattern

- Dispatch with `Action::dispatch(...)` for async execution.
- Keep queue-specific orchestration in `asJob(...)`.
- Keep reusable business logic in `handle(...)`.

## Methods provided (`AsJob` trait)

### `dispatch`

Dispatches the action asynchronously.

```php
SendTeamReportEmail::dispatch($team);
```

### `dispatchIf`

Dispatches asynchronously only if condition is met.

```php
SendTeamReportEmail::dispatchIf($team->plan === 'premium', $team);
```

### `dispatchUnless`

Dispatches asynchronously unless condition is met.

```php
SendTeamReportEmail::dispatchUnless($team->plan === 'free', $team);
```

### `dispatchSync`

Dispatches synchronously.

```php
SendTeamReportEmail::dispatchSync($team);
```

### `dispatchNow`

Alias of `dispatchSync`.

```php
SendTeamReportEmail::dispatchNow($team);
```

### `dispatchAfterResponse`

Dispatches synchronously after the HTTP response is sent.

```php
SendTeamReportEmail::dispatchAfterResponse($team);
```

### `makeJob`

Creates a `JobDecorator` wrapper. Useful with `dispatch(...)` helper or chains.

```php
dispatch(SendTeamReportEmail::makeJob($team));
```

### `makeUniqueJob`

Creates a `UniqueJobDecorator` wrapper. Usually automatic with `ShouldBeUnique`, but can be forced.

```php
dispatch(SendTeamReportEmail::makeUniqueJob($team));
```

### `withChain`

Attaches jobs to run after successful processing.

```php
$chain = [
    OptimizeTeamReport::makeJob($team),
    SendTeamReportEmail::makeJob($team),
];

CreateNewTeamReport::withChain($chain)->dispatch($team);
```

Equivalent using `Bus::chain(...)`:

```php
use Illuminate\Support\Facades\Bus;

Bus::chain([
    CreateNewTeamReport::makeJob($team),
    OptimizeTeamReport::makeJob($team),
    SendTeamReportEmail::makeJob($team),
])->dispatch();
```

Chain assertion example:

```php
use Illuminate\Support\Facades\Bus;

Bus::fake();

Bus::assertChained([
    CreateNewTeamReport::makeJob($team),
    OptimizeTeamReport::makeJob($team),
    SendTeamReportEmail::makeJob($team),
]);
```

### `assertPushed`

Asserts the action was queued.

```php
use Illuminate\Support\Facades\Queue;

Queue::fake();

SendTeamReportEmail::assertPushed();
SendTeamReportEmail::assertPushed(3);
SendTeamReportEmail::assertPushed($callback);
SendTeamReportEmail::assertPushed(3, $callback);
```

`$callback` receives:
- Action instance.
- Dispatched arguments.
- `JobDecorator` instance.
- Queue name.

### `assertNotPushed`

Asserts the action was not queued.

```php
use Illuminate\Support\Facades\Queue;

Queue::fake();

SendTeamReportEmail::assertNotPushed();
SendTeamReportEmail::assertNotPushed($callback);
```

### `assertPushedOn`

Asserts the action was queued on a specific queue.

```php
use Illuminate\Support\Facades\Queue;

Queue::fake();

SendTeamReportEmail::assertPushedOn('reports');
SendTeamReportEmail::assertPushedOn('reports', 3);
SendTeamReportEmail::assertPushedOn('reports', $callback);
SendTeamReportEmail::assertPushedOn('reports', 3, $callback);
```

## Methods used (`JobDecorator`)

### `asJob`

Called when dispatched as a job. Falls back to `handle(...)` if missing.

```php
class SendTeamReportEmail
{
    use AsAction;

    public function handle(Team $team, bool $fullReport = false): void
    {
        // Prepare report and send it to all $team->users.
    }

    public function asJob(Team $team): void
    {
        $this->handle($team, true);
    }
}
```

### `getJobMiddleware`

Adds middleware to the queued action.

```php
public function getJobMiddleware(array $parameters): array
{
    return [new RateLimited('reports')];
}
```

### `configureJob`

Configures `JobDecorator` options.

```php
use Lorisleiva\Actions\Decorators\JobDecorator;

public function configureJob(JobDecorator $job): void
{
    $job->onConnection('my_connection')
        ->onQueue('my_queue')
        ->through(['my_middleware'])
        ->chain(['my_chain'])
        ->delay(60);
}
```

### `$jobConnection`

Defines queue connection.

```php
public string $jobConnection = 'my_connection';
```

### `$jobQueue`

Defines queue name.

```php
public string $jobQueue = 'my_queue';
```

### `$jobTries`

Defines max attempts.

```php
public int $jobTries = 10;
```

### `$jobMaxExceptions`

Defines max unhandled exceptions before failure.

```php
public int $jobMaxExceptions = 3;
```

### `$jobBackoff`

Defines retry delay seconds.

```php
public int $jobBackoff = 60;
```

### `getJobBackoff`

Defines retry delay (int or per-attempt array).

```php
public function getJobBackoff(): int
{
    return 60;
}

public function getJobBackoff(): array
{
    return [30, 60, 120];
}
```

### `$jobTimeout`

Defines timeout in seconds.

```php
public int $jobTimeout = 60 * 30;
```

### `$jobRetryUntil`

Defines timestamp retry deadline.

```php
public int $jobRetryUntil = 1610191764;
```

### `getJobRetryUntil`

Defines retry deadline as `DateTime`.

```php
public function getJobRetryUntil(): DateTime
{
    return now()->addMinutes(30);
}
```

### `getJobDisplayName`

Customizes queued job display name.

```php
public function getJobDisplayName(): string
{
    return 'Send team report email';
}
```

### `getJobTags`

Adds queue tags.

```php
public function getJobTags(Team $team): array
{
    return ['report', 'team:'.$team->id];
}
```

### `getJobUniqueId`

Defines uniqueness key when using `ShouldBeUnique`.

```php
public function getJobUniqueId(Team $team): int
{
    return $team->id;
}
```

### `$jobUniqueId`

Static uniqueness key alternative.

```php
public string $jobUniqueId = 'some_static_key';
```

### `getJobUniqueFor`

Defines uniqueness lock duration in seconds.

```php
public function getJobUniqueFor(Team $team): int
{
    return $team->role === 'premium' ? 1800 : 3600;
}
```

### `$jobUniqueFor`

Property alternative for uniqueness lock duration.

```php
public int $jobUniqueFor = 3600;
```

### `getJobUniqueVia`

Defines cache driver used for uniqueness lock.

```php
public function getJobUniqueVia()
{
    return Cache::driver('redis');
}
```

### `$jobDeleteWhenMissingModels`

Property alternative for missing model handling.

```php
public bool $jobDeleteWhenMissingModels = true;
```

### `getJobDeleteWhenMissingModels`

Defines whether jobs with missing models are deleted.

```php
public function getJobDeleteWhenMissingModels(): bool
{
    return true;
}
```

### `jobFailed`

Handles job failure. Receives exception and dispatched parameters.

```php
public function jobFailed(?Throwable $e, ...$parameters): void
{
    // Notify users, report errors, trigger compensations...
}
```

## Checklist

- Async/sync dispatch method matches use-case (`dispatch`, `dispatchSync`, `dispatchAfterResponse`).
- Queue config is explicit when needed (`$jobConnection`, `$jobQueue`, `configureJob`).
- Retry/backoff/timeout policies are intentional.
- `asJob(...)` delegates to `handle(...)` unless queue-specific branching is required.
- Queue tests use `Queue::fake()` and action assertions (`assertPushed*`).

## Common pitfalls

- Embedding domain logic only in `asJob(...)`.
- Forgetting uniqueness/timeout/retry controls on heavy jobs.
- Missing queue-specific assertions in tests.

## References

- https://www.laravelactions.com/2.x/as-job.html