# Object Entrypoint (`run`, `make`, DI)

## Scope

Use this reference when the action is invoked as a plain object.

## Recap

- Explains object-style invocation with `make`, `run`, `runIf`, `runUnless`.
- Clarifies when to use static helpers versus DI/manual invocation.
- Includes minimal examples for direct run and service-level injection.
- Highlights boundaries: business logic stays in `handle(...)`.

## Recommended pattern

- Keep core business logic in `handle(...)`.
- Prefer `Action::run(...)` for readability.
- Use `Action::make()->handle(...)` or DI only when needed.

## Methods provided

### `make`

Resolves the action from the container.

```php
PublishArticle::make();

// Equivalent to:
app(PublishArticle::class);
```

### `run`

Resolves and executes the action.

```php
PublishArticle::run($articleId);

// Equivalent to:
PublishArticle::make()->handle($articleId);
```

### `runIf`

Resolves and executes the action only if the condition is met.

```php
PublishArticle::runIf($shouldPublish, $articleId);

// Equivalent mental model:
if ($shouldPublish) {
    PublishArticle::run($articleId);
}
```

### `runUnless`

Resolves and executes the action only if the condition is not met.

```php
PublishArticle::runUnless($alreadyPublished, $articleId);

// Equivalent mental model:
if (! $alreadyPublished) {
    PublishArticle::run($articleId);
}
```

## Checklist

- Input/output types are explicit.
- `handle(...)` has no transport concerns.
- Business behavior is covered by direct `handle(...)` tests.

## Common pitfalls

- Putting HTTP/CLI/queue concerns in `handle(...)`.
- Calling adapters from `handle(...)` instead of the reverse.

## References

- https://www.laravelactions.com/2.x/as-object.html

## Examples

### Minimal object-style invocation

```php
final class PublishArticle
{
    use AsAction;

    public function handle(int $articleId): bool
    {
        // Domain logic...
        return true;
    }
}

$published = PublishArticle::run(42);
```

### Dependency injection invocation

```php
final class ArticleService
{
    public function __construct(
        private PublishArticle $publishArticle
    ) {}

    public function publish(int $articleId): bool
    {
        return $this->publishArticle->handle($articleId);
    }
}
```