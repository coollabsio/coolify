# Mail Best Practices

## Queue Slow Mail Delivery

Implement `ShouldQueue` on a mailable when delivery should normally happen in the background. Laravel queues that mailable even when the call site uses `Mail::send()`.

```php
class OrderShipped extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;
}
```

Keep mail synchronous when the caller must know immediately whether delivery was accepted, or when no queue worker is available.

## Dispatch Queued Mail After Commit

A queued mailable dispatched during a database transaction can be processed before the transaction commits. Call `afterCommit()` on the mailable, or enable the queue connection's `after_commit` option, when the mail depends on committed records.

```php
Mail::to($user)->send(
    (new OrderShipped($order))->afterCommit()
);
```

If the transaction rolls back, an after-commit mailable is not dispatched. This setting affects queued mail only; it does not defer synchronous delivery.

## Assert the Delivery Mode

Use `Mail::assertQueued()` for queued mailables and `Mail::assertSent()` for synchronously sent mailables.

Incorrect for a mailable that implements `ShouldQueue`:

```php
Mail::assertSent(OrderShipped::class);
```

Correct:

```php
Mail::assertQueued(OrderShipped::class);
```

## Use Markdown Mailables When They Fit

Markdown mailables render HTML and plain-text versions from Laravel's mail components and support publishable themes. They are useful for conventional transactional messages, but a custom HTML and text pair may be more appropriate for a specialized design.

```bash
php artisan make:mail OrderShipped --markdown=mail.orders.shipped
```

## Separate Content and Delivery Tests

Test rendered content by instantiating the mailable and using assertions such as `assertSeeInHtml()` and `assertSeeInText()`. Test delivery separately with `Mail::fake()` and `assertSent()` or `assertQueued()` so failures identify the affected behavior.
