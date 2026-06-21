# Notifications & Alerts

## Where to Find It

Search with `search-docs`:
- `"horizon notifications"` for Horizon's built-in notification routing helpers
- `"horizon long wait detected"` for LongWaitDetected event details

## What to Watch For

### `waits` in `config/horizon.php` controls the LongWaitDetected threshold

The `waits` array (e.g., `'redis:default' => 60`) defines how many seconds a job can wait in a queue before Horizon fires a `LongWaitDetected` event. This value is set in the config file, not in Horizon's notification routing. If alerts are firing too often or too late, adjust `waits` rather than the routing configuration.

### Use Horizon's built-in notification routing in `HorizonServiceProvider`

Configure notifications in the `boot()` method of `App\Providers\HorizonServiceProvider` using `Horizon::routeMailNotificationsTo()`, `Horizon::routeSlackNotificationsTo()`, or `Horizon::routeSmsNotificationsTo()`. Horizon already wires `LongWaitDetected` to its notification sender, so the documented setup is notification routing rather than manual listener registration.

### Failed job alerts are separate from Horizon's documented notification routing

Horizon's 12.x documentation covers built-in long-wait notifications. Do not assume the docs provide a `JobFailed` listener example in `HorizonServiceProvider`. If a user needs failed job alerts, treat that as custom queue event handling and consult the queue documentation instead of Horizon's notification-routing API.