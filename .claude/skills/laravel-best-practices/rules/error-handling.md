# Error Handling Best Practices

## Choose Where to Report and Render Exceptions

Laravel supports exception-specific methods and centralized handler callbacks. Follow the pattern already established by the project.

Exception methods keep behavior beside the exception definition:

```php
class InvalidOrderException extends Exception
{
    public function report(): void
    {
        // Send the exception to a custom reporter.
    }

    public function render(Request $request): Response
    {
        return response()->view('errors.invalid-order', status: 422);
    }
}
```

Centralized callbacks in `bootstrap/app.php` keep the application's exception policy together:

```php
->withExceptions(function (Exceptions $exceptions) {
    $exceptions->report(function (InvalidOrderException $e) {
        // Send the exception to a custom reporter.
    });
    $exceptions->render(function (InvalidOrderException $e, Request $request) {
        return response()->view('errors.invalid-order', status: 422);
    });
})
```

An exception's `report()` method suppresses Laravel's default reporting unless it returns `false`. A report callback allows default reporting unless it returns `false` or is chained with `stop()`. Use `ShouldntReport` or `dontReport()` when the handler should not report an exception at all. By contrast, returning `false` from a `render()` method or render callback defers to Laravel's default rendering.

## Mark Exceptions the Handler Should Not Report

Implementing `ShouldntReport` prevents Laravel's exception handler from reporting that exception type and keeps the policy visible on the class. It does not prevent application code from logging the exception explicitly.

```php
class PodcastProcessingException extends Exception implements ShouldntReport {}
```

## Throttle High-Volume Exception Reports

A failing integration can flood logs or error tracking. Configure `throttle()` with a `Lottery` or `Limit` result to sample or rate-limit matching exception reports. Choose keys deliberately when separate exception classes, tenants, or integrations need independent limits.

## Prevent Duplicate Reports of One Exception Instance

Enable `dontReportDuplicates()` when the same exception object may pass through multiple `report($exception)` calls. It deduplicates by object identity, not by exception class or message.

## Define JSON Rendering for API Routes

Laravel normally uses request content negotiation to decide whether to render an exception as JSON. If the application's API contract requires JSON regardless of the `Accept` header, define that policy explicitly for the relevant routes.

```php
$exceptions->shouldRenderJsonWhen(function (Request $request, Throwable $e) {
    return $request->is('api/*') || $request->expectsJson();
});
```

## Add Context to Exception Classes

Attach structured data to an exception through `context()`. Laravel merges that data into the exception's log context when the handler reports it.

```php
class InvalidOrderException extends Exception
{
    public function context(): array
    {
        return ['order_id' => $this->orderId];
    }
}
```
