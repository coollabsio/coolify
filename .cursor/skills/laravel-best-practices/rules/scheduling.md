# Task Scheduling Best Practices

## Prevent Unwanted Overlap

Use `withoutOverlapping()` when a second run must not begin while the previous run holds the lock. This is appropriate for variable-duration tasks that are not safe to run concurrently.

```php
Schedule::command('reports:generate')
    ->everyFifteenMinutes()
    ->withoutOverlapping(30);
```

The optional value is the lock expiration time in minutes, not the task timeout. Choose it carefully: the default is 24 hours, stale locks can be cleared with `php artisan schedule:clear-cache`, and an expiration that is too short can permit overlap while the first task still runs. The task itself should still tolerate retries and partial execution where practical.

## Run a Task on One Server

Use `onOneServer()` when only one scheduler node should run an eligible task. Scheduler nodes must use the same default cache store, and that store must support atomic locks. Supported stores include `database`, `memcached`, `dynamodb`, and `redis`.

```php
Schedule::command('billing:charge')->daily()->onOneServer();
```

Name scheduled closures before applying `onOneServer()`, especially when scheduling the same closure with different parameters, so each task has a distinct lock identity.

## Run Eligible Commands in the Background

Tasks due at the same time run sequentially by default. Use `runInBackground()` when an independent, long-running scheduled command should not delay later tasks.

```php
Schedule::command('analytics:process')->hourly()->runInBackground();
```

Laravel restricts `runInBackground()` to tasks scheduled with `command()` and `exec()`; it is not available for scheduled closures. Ensure background processes have appropriate logging and failure monitoring.

## Restrict Tasks by Environment

Use `environments()` when a task should run only in named application environments. Treat this as an operational safeguard, not an authorization control.

```php
Schedule::command('billing:charge')
    ->monthly()
    ->environments(['production']);
```

## Group Shared Configuration

Use schedule groups when several tasks genuinely share frequency or constraints.

```php
Schedule::daily()
    ->onOneServer()
    ->timezone('America/New_York')
    ->group(function () {
        Schedule::command('emails:send --force');
        Schedule::command('emails:prune');
    });
```

## Bound Work Inside the Task

The scheduler does not provide a `takeUntilTimeout()` event method or terminate arbitrary tasks at a deadline. Bound work in the command or job itself by processing finite chunks, checking a deadline, or dispatching queue jobs with suitable timeouts. Use operating-system or process controls when hard termination is required.
