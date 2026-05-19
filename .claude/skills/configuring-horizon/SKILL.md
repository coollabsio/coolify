---
name: configuring-horizon
description: "Use this skill whenever the user mentions Horizon by name in a Laravel context. Covers the full Horizon lifecycle: installing Horizon (horizon:install, Sail setup), configuring config/horizon.php (supervisor blocks, queue assignments, balancing strategies, minProcesses/maxProcesses), fixing the dashboard (authorization via Gate::define viewHorizon, blank metrics, horizon:snapshot scheduling), and troubleshooting production issues (worker crashes, timeout chain ordering, LongWaitDetected notifications, waits config). Also covers job tagging and silencing. Do not use for generic Laravel queues without Horizon, SQS or database drivers, standalone Redis setup, Linux supervisord, Telescope, or job batching."
license: MIT
metadata:
  author: laravel
---

# Horizon Configuration

## Documentation

Use `search-docs` for detailed Horizon patterns and documentation covering configuration, supervisors, balancing, dashboard authorization, tags, notifications, metrics, and deployment.

For deeper guidance on specific topics, read the relevant reference file before implementing:

- `references/supervisors.md` covers supervisor blocks, balancing strategies, multi-queue setups, and auto-scaling
- `references/notifications.md` covers LongWaitDetected alerts, notification routing, and the `waits` config
- `references/tags.md` covers job tagging, dashboard filtering, and silencing noisy jobs
- `references/metrics.md` covers the blank metrics dashboard, snapshot scheduling, and retention config

## Basic Usage

### Installation

```bash
php artisan horizon:install
```

### Supervisor Configuration

Define supervisors in `config/horizon.php`. The `environments` array merges into `defaults` and does not replace the whole supervisor block:

<!-- Supervisor Config -->
```php
'defaults' => [
    'supervisor-1' => [
        'connection' => 'redis',
        'queue' => ['default'],
        'balance' => 'auto',
        'minProcesses' => 1,
        'maxProcesses' => 10,
        'tries' => 3,
    ],
],

'environments' => [
    'production' => [
        'supervisor-1' => ['maxProcesses' => 20, 'balanceCooldown' => 3],
    ],
    'local' => [
        'supervisor-1' => ['maxProcesses' => 2],
    ],
],
```

### Dashboard Authorization

Restrict access in `App\Providers\HorizonServiceProvider`:

<!-- Dashboard Gate -->
```php
protected function gate(): void
{
    Gate::define('viewHorizon', function (User $user) {
        return $user->is_admin;
    });
}
```

## Verification

1. Run `php artisan horizon` and visit `/horizon`
2. Confirm dashboard access is restricted as expected
3. Check that metrics populate after scheduling `horizon:snapshot`

## Common Pitfalls

- Horizon only works with the Redis queue driver. Other drivers such as database and SQS are not supported.
- Redis Cluster is not supported. Horizon requires a standalone Redis connection.
- Always check `config/horizon.php` before making changes to understand the current supervisor and environment configuration.
- The `environments` array overrides only the keys you specify. It merges into `defaults` and does not replace it.
- The timeout chain must be ordered: job `timeout` less than supervisor `timeout` less than `retry_after`. The wrong order can cause jobs to be retried before Horizon finishes timing them out.
- The metrics dashboard stays blank until `horizon:snapshot` is scheduled. Running `php artisan horizon` alone does not populate metrics.
- Always use `search-docs` for the latest Horizon documentation rather than relying on this skill alone.