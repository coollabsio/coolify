# Listener Entrypoint (`asListener`)

## Scope

Use this reference when wiring actions to domain/application events.

## Recap

- Shows how listener execution maps event payloads into `handle(...)` arguments.
- Describes `asListener(...)` fallback behavior and adaptation role.
- Includes event registration example for provider wiring.
- Emphasizes test focus on dispatch and action interaction.

## Recommended pattern

- Register action listener in `EventServiceProvider` (or project equivalent).
- Use `asListener(Event $event)` for event adaptation.
- Delegate core logic to `handle(...)`.

## Methods used (`ListenerDecorator`)

### `asListener`

Called when executed as an event listener. If missing, it falls back to `handle(...)`.

```php
class SendOfferToNearbyDrivers
{
    use AsAction;

    public function handle(Address $source, Address $destination): void
    {
        // ...
    }

    public function asListener(TaxiRequested $event): void
    {
        $this->handle($event->source, $event->destination);
    }
}
```

## Examples

### Event registration

```php
// app/Providers/EventServiceProvider.php
protected $listen = [
    TaxiRequested::class => [
        SendOfferToNearbyDrivers::class,
    ],
];
```

### Focused listener test

```php
use Illuminate\Support\Facades\Event;

Event::fake();

TaxiRequested::dispatch($source, $destination);

Event::assertDispatched(TaxiRequested::class);
```

## Checklist

- Event-to-listener mapping is registered.
- Listener method signature matches event contract.
- Listener tests verify dispatch and action interaction.

## Common pitfalls

- Assuming automatic listener registration when explicit mapping is required.
- Re-implementing business logic in `asListener(...)`.

## References

- https://www.laravelactions.com/2.x/as-listener.html