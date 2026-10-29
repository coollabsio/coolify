# Queue and Job Best Practices

## Keep Reservation Time Longer Than Execution Time

For queue drivers that use Laravel's `retry_after` setting, configure it to exceed the longest worker or job timeout by a safety margin. When a reservation expires, another worker can reserve the same job while the first process is still running. Keep the worker's `--timeout` several seconds shorter than `retry_after`.

```php
// Job
public $timeout = 120;

// config/queue.php for the connection
'retry_after' => 150,
```

Amazon Simple Queue Service uses its visibility timeout instead of Laravel's `retry_after`; configure that timeout at the queue level. Because workers can also stop after side effects but before acknowledging a job, make important jobs idempotent even with correct timeout settings.

## Back Off Transient Failures

Use progressively longer delays when a dependency needs time to recover. Do not retry permanent validation or business-rule failures.

```php
class SyncWithStripe implements ShouldQueue
{
    public $tries = 4;

    public $backoff = [1, 5, 10];
}
```

Rate-limiting and exception-throttling middleware can release jobs back to the queue. Released attempts may still count toward the maximum attempt limit, so configure `$tries` or `retryUntil()` to allow the intended retry window.

## Use Unique Jobs for Dispatch Deduplication

Implement `ShouldBeUnique` when only one queued instance of a logical job should exist. Uniqueness uses a cache lock and is not a substitute for idempotent processing or a database constraint.

```php
class GenerateInvoice implements ShouldQueue, ShouldBeUnique
{
    public $uniqueFor = 3600;

    public function uniqueId(): string
    {
        return (string) $this->order->id;
    }
}
```

All dispatching processes must use a shared cache that supports locks. Unique-job constraints do not apply to jobs within batches.

Use `ShouldBeUniqueUntilProcessing` only when the lock should be released immediately before processing begins, allowing another instance to be dispatched while the first is running:

```php
class UpdateSearchIndex implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    // ...
}
```

## Handle Terminal Failure When Needed

Implement `failed()` when the application must update state, alert an operator, or record domain-specific context after all attempts are exhausted. Logging every failure in each job may duplicate the queue system's failure reporting.

Laravel invokes `failed()` on a new job instance, so mutations made to the job during `handle()` are not available there.

```php
public function failed(?Throwable $exception): void
{
    $this->podcast->update(['status' => 'failed']);

    Log::error('Podcast processing failed', [
        'podcast_id' => $this->podcast->id,
        'exception' => $exception,
    ]);
}
```

## Rate Limit External Calls

Use queue middleware such as `RateLimited` when jobs share a third-party API quota. Define the named limiter and choose release delays and attempt limits together.

```php
public function middleware(): array
{
    return [new RateLimited('external-api')];
}
```

## Batch Jobs for Group Coordination

Use `Bus::batch()` to monitor a group of jobs and run callbacks when the batch completes or encounters failures. A batch is not a database transaction: completed jobs are not rolled back when another job fails. By default, one failed job cancels the batch; call `allowFailures()` only when partial failure is acceptable.

```php
Bus::batch([
    new ImportCsvChunk($chunk1),
    new ImportCsvChunk($chunk2),
])
    ->then(fn (Batch $batch) => Notification::send($user, new ImportComplete))
    ->catch(fn (Batch $batch, Throwable $exception) => Log::error('Import batch failed', [
        'exception' => $exception,
    ]))
    ->dispatch();
```

## Configure Time-Based Retry Limits Deliberately

Use `retryUntil()` as the time-based alternative to a maximum attempt count. Laravel may attempt the job any number of times until this deadline, subject to other failure conditions such as maximum exceptions. The method takes precedence over attempt-based limits, so setting `$tries = 0` is not required.

```php
public function retryUntil(): DateTimeInterface
{
    return now()->addHours(4);
}
```

## Use Horizon for Redis Queue Operations

Laravel Horizon provides monitoring, balancing, metrics, and supervisor configuration for Redis queues. It does not support non-Redis queue drivers.
