# Architecture Best Practices

## Extract Focused Business Operations

Extract a discrete business operation into an action class when doing so makes the operation easier to reuse or test. An action class has no special meaning to Laravel; follow the project's naming and invocation conventions.

```php
class CreateOrderAction
{
    public function __construct(private InventoryService $inventory) {}

    public function handle(array $data): Order
    {
        $order = Order::create($data);
        $this->inventory->reserve($order);

        return $order;
    }
}
```

## Inject Required Dependencies

Prefer constructor injection for dependencies required throughout an object's lifetime. Method injection is appropriate for dependencies needed by one controller action, listener, job handler, or other container-invoked method. Avoid `app()` and `resolve()` when normal injection can make a dependency explicit.

Hidden dependency:

```php
class OrderController extends Controller
{
    public function store(StoreOrderRequest $request)
    {
        $service = app(OrderService::class);

        return $service->create($request->validated());
    }
}
```

Injected dependency:

```php
class OrderController extends Controller
{
    public function store(StoreOrderRequest $request, OrderService $service)
    {
        return $service->create($request->validated());
    }
}
```

## Depend on Contracts at Boundaries

Depend on contracts at system boundaries, such as payment gateways, notification channels, and external services, when testability or interchangeable implementations justify the abstraction.

Concrete boundary dependency:

```php
class OrderService
{
    public function __construct(private StripeGateway $gateway) {}
}
```

Contract boundary dependency:

```php
interface PaymentGateway
{
    public function charge(int $amount, string $customerId): PaymentResult;
}

class OrderService
{
    public function __construct(private PaymentGateway $gateway) {}
}
```

Bind in a service provider:

```php
$this->app->bind(PaymentGateway::class, StripeGateway::class);
```

## Specify a Deterministic Sort Order

Without an explicit `ORDER BY`, row order is undefined. Choose an order that matches the feature, and add a unique tie-breaker when stable pagination matters.

Unspecified order:

```php
$posts = Post::paginate();
```

Newest first with a stable tie-breaker:

```php
$posts = Post::query()
    ->orderByDesc('created_at')
    ->orderByDesc('id')
    ->paginate();
```

## Use Atomic Locks for Race Conditions

Use a lock when concurrent execution must be serialized. `Cache::lock()` provides an atomic lock when the configured cache store supports locks. `lockForUpdate()` locks selected database rows and must run inside a database transaction. These mechanisms solve different coordination problems.

```php
Cache::lock('order-processing-'.$order->id, 10)->block(5, function () use ($order) {
    $order->process();
});

// Or at query level, inside a transaction
DB::transaction(function () use ($id) {
    $product = Product::where('id', $id)->lockForUpdate()->first();

    // Read and update the product while the database lock is held.
});
```

## Use `mb_*` String Functions

When no Laravel helper exists, prefer multibyte-aware functions such as `mb_strlen()` and `mb_strtolower()` for UTF-8 text. For example, `strlen()` counts bytes, while `strtolower()` is not multibyte-aware.

Incorrect:

```php
strlen('José');          // 5 bytes, not 4 characters
strtolower('MÜNCHEN');  // Does not lowercase Ü
```

Correct:

```php
mb_strlen('José');         // 4 characters
mb_strtolower('MÜNCHEN'); // 'münchen'

// Prefer Laravel's Str helpers when available
Str::length('José');       // 4
Str::lower('MÜNCHEN');     // 'münchen'
```

## Use `defer()` for Post-Response Work

For lightweight work that does not need retries or crash durability, consider `defer()` instead of dispatching a job. During an HTTP request, the callback normally runs after the response has been sent but remains in the same PHP process.

Queued and durable:

```php
dispatch(new LogPageView($page));
```

Deferred in the current process:

```php
defer(fn () => PageView::create(['page_id' => $page->id, 'user_id' => auth()->id()]));
```

Use a queued job when the work needs retries, queue controls, or durability across process failures.

## Use `Context` for Request-Scoped Data

The `Context` facade makes contextual data available across the current execution lifecycle without manually passing arguments through every layer.

```php
// In middleware
Context::add('tenant_id', $request->header('X-Tenant-ID'));

// Later in the same execution lifecycle
$tenantId = Context::get('tenant_id');
```

Visible context is added to log context, and both visible and hidden context are captured and restored for queued jobs. Use `Context::addHidden()` for data that should propagate to queued jobs without appearing in logs. Do not place secrets in context unless that propagation is intended.

## Use `Concurrency::run()` for Parallel Execution

Run independent operations concurrently through Laravel's configured concurrency driver.

```php
use Illuminate\Support\Facades\Concurrency;

[$users, $orders] = Concurrency::run([
    fn () => User::count(),
    fn () => Order::where('status', 'pending')->count(),
]);
```

With a process-based driver, each closure runs in a separate PHP process that boots the application. Use concurrency when independent database queries, HTTP client calls, or computations benefit enough to offset process and serialization overhead. The `sync` driver executes closures sequentially and is useful primarily during testing.

## Follow Framework Conventions

Follow Laravel conventions unless the domain or an existing schema requires an override.

Customized schema:

```php
class Customer extends Model
{
    protected $table = 'Customer';
    protected $primaryKey = 'customer_id';

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_customer', 'customer_id', 'role_id');
    }
}
```

Conventional schema:

```php
class Customer extends Model
{
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }
}
```
