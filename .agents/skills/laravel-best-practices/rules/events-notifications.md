# Events and Notifications Best Practices

## Rely on Event Discovery

Laravel discovers listeners in the configured listener directories by inspecting type-hinted event arguments on `handle()` or `__invoke()` methods. Register listeners manually only when discovery is disabled, the listener is outside those directories, or explicit registration is clearer.

## Cache Event Discovery During Production Deployment

Cache discovered listeners during production deployment with `php artisan optimize` or `php artisan event:cache`. Rebuild the cache whenever listener definitions change.

## Use `ShouldDispatchAfterCommit` Inside Transactions

When an event is dispatched inside a database transaction, `ShouldDispatchAfterCommit` delays dispatch until all open database transactions commit. If a transaction rolls back, Laravel discards the event. This affects synchronous and queued listeners; it is not limited to queue timing.

```php
class OrderShipped implements ShouldDispatchAfterCommit {}
```

## Queue Slow Notifications

Queue notifications that call external services, such as email, text messaging, or Slack, when they do not need to complete before the response. Keep a notification synchronous when immediate completion or failure feedback is part of the operation.

```php
class InvoicePaid extends Notification implements ShouldQueue
{
    use Queueable;
}
```

## Dispatch Queued Notifications After Commit

A queued notification sent inside a database transaction can run before the transaction commits. Call `afterCommit()` on the queued notification, or enable the queue connection's `after_commit` option, when its delivery depends on committed data. This setting has no scheduling effect on a synchronous notification.

```php
$user->notify((new InvoicePaid($invoice))->afterCommit());
```

## Route Notification Channels to Dedicated Queues

Different notification channels can have different latency and priority requirements. Implement `viaQueues()` when channels should use separate queues.

## Use On-Demand Notifications for Non-User Recipients

Avoid creating dummy models to send notifications to arbitrary addresses.

```php
Notification::route('mail', 'admin@example.com')->notify(new SystemAlert());
```

## Implement `HasLocalePreference` on Notifiable Models

Implement `HasLocalePreference::preferredLocale()` on a notifiable model when notifications and mailables should use the recipient's locale. Laravel also preserves that locale for queued delivery. An explicit `locale()` call can still override the preference for an individual notification.
