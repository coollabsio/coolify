# Controller Entrypoint (`asController`)

## Scope

Use this reference when exposing an action through HTTP routes.

## Recap

- Documents controller lifecycle around `asController(...)` and response adapters.
- Covers routing patterns, middleware, and optional in-action `routes()` registration.
- Summarizes validation/authorization hooks used by `ActionRequest`.
- Provides extension points for JSON/HTML responses and failure customization.

## Recommended pattern

- Route directly to action class when appropriate.
- Keep HTTP adaptation in controller methods (`asController`, `jsonResponse`, `htmlResponse`).
- Keep domain logic in `handle(...)`.

## Methods provided (`AsController` trait)

### `__invoke`

Required so Laravel can register the action class as an invokable controller.

```php
$action($someArguments);

// Equivalent to:
$action->handle($someArguments);
```

If the method does not exist, Laravel route registration fails for invokable controllers.

```php
// Illuminate\Routing\RouteAction
protected static function makeInvokable($action)
{
    if (! method_exists($action, '__invoke')) {
        throw new UnexpectedValueException("Invalid route action: [{$action}].");
    }

    return $action.'@__invoke';
}
```

If you need your own `__invoke`, alias the trait implementation:

```php
class MyAction
{
    use AsAction {
        __invoke as protected invokeFromLaravelActions;
    }

    public function __invoke()
    {
        // Custom behavior...
    }
}
```

## Methods used (`ControllerDecorator` + `ActionRequest`)

### `asController`

Called when used as invokable controller. If missing, it falls back to `handle(...)`.

```php
public function asController(User $user, Request $request): Response
{
    $article = $this->handle(
        $user,
        $request->get('title'),
        $request->get('body')
    );

    return redirect()->route('articles.show', [$article]);
}
```

### `jsonResponse`

Called after `asController` when request expects JSON.

```php
public function jsonResponse(Article $article, Request $request): ArticleResource
{
    return new ArticleResource($article);
}
```

### `htmlResponse`

Called after `asController` when request expects HTML.

```php
public function htmlResponse(Article $article, Request $request): Response
{
    return redirect()->route('articles.show', [$article]);
}
```

### `getControllerMiddleware`

Adds middleware directly on the action controller.

```php
public function getControllerMiddleware(): array
{
    return ['auth', MyCustomMiddleware::class];
}
```

### `routes`

Defines routes directly in the action.

```php
public static function routes(Router $router)
{
    $router->get('author/{author}/articles', static::class);
}
```

To enable this, register routes from actions in a service provider:

```php
use Lorisleiva\Actions\Facades\Actions;

Actions::registerRoutes();
Actions::registerRoutes('app/MyCustomActionsFolder');
Actions::registerRoutes([
    'app/Authentication',
    'app/Billing',
    'app/TeamManagement',
]);
```

### `prepareForValidation`

Called before authorization and validation are resolved.

```php
public function prepareForValidation(ActionRequest $request): void
{
    $request->merge(['some' => 'additional data']);
}
```

### `authorize`

Defines authorization logic.

```php
public function authorize(ActionRequest $request): bool
{
    return $request->user()->role === 'author';
}
```

You can also return gate responses:

```php
use Illuminate\Auth\Access\Response;

public function authorize(ActionRequest $request): Response
{
    if ($request->user()->role !== 'author') {
        return Response::deny('You must be an author to create a new article.');
    }

    return Response::allow();
}
```

### `rules`

Defines validation rules.

```php
public function rules(): array
{
    return [
        'title' => ['required', 'min:8'],
        'body' => ['required', IsValidMarkdown::class],
    ];
}
```

### `withValidator`

Adds custom validation logic with an after hook.

```php
use Illuminate\Validation\Validator;

public function withValidator(Validator $validator, ActionRequest $request): void
{
    $validator->after(function (Validator $validator) use ($request) {
        if (! Hash::check($request->get('current_password'), $request->user()->password)) {
            $validator->errors()->add('current_password', 'Wrong password.');
        }
    });
}
```

### `afterValidator`

Alternative to add post-validation checks.

```php
use Illuminate\Validation\Validator;

public function afterValidator(Validator $validator, ActionRequest $request): void
{
    if (! Hash::check($request->get('current_password'), $request->user()->password)) {
        $validator->errors()->add('current_password', 'Wrong password.');
    }
}
```

### `getValidator`

Provides a custom validator instead of default rules pipeline.

```php
use Illuminate\Validation\Factory;
use Illuminate\Validation\Validator;

public function getValidator(Factory $factory, ActionRequest $request): Validator
{
    return $factory->make($request->only('title', 'body'), [
        'title' => ['required', 'min:8'],
        'body' => ['required', IsValidMarkdown::class],
    ]);
}
```

### `getValidationData`

Defines which data is validated (default: `$request->all()`).

```php
public function getValidationData(ActionRequest $request): array
{
    return $request->all();
}
```

### `getValidationMessages`

Custom validation error messages.

```php
public function getValidationMessages(): array
{
    return [
        'title.required' => 'Looks like you forgot the title.',
        'body.required' => 'Is that really all you have to say?',
    ];
}
```

### `getValidationAttributes`

Human-friendly names for request attributes.

```php
public function getValidationAttributes(): array
{
    return [
        'title' => 'headline',
        'body' => 'content',
    ];
}
```

### `getValidationRedirect`

Custom redirect URL on validation failure.

```php
public function getValidationRedirect(UrlGenerator $url): string
{
    return $url->to('/my-custom-redirect-url');
}
```

### `getValidationErrorBag`

Custom error bag name on validation failure (default: `default`).

```php
public function getValidationErrorBag(): string
{
    return 'my_custom_error_bag';
}
```

### `getValidationFailure`

Override validation failure behavior.

```php
public function getValidationFailure(): void
{
    throw new MyCustomValidationException();
}
```

### `getAuthorizationFailure`

Override authorization failure behavior.

```php
public function getAuthorizationFailure(): void
{
    throw new MyCustomAuthorizationException();
}
```

## Checklist

- Route wiring points to the action class.
- `asController(...)` delegates to `handle(...)`.
- Validation/authorization methods are explicit where needed.
- Response mapping is split by channel (`jsonResponse`, `htmlResponse`) when useful.
- HTTP tests cover both success and validation/authorization failure branches.

## Common pitfalls

- Putting response/redirect logic in `handle(...)`.
- Duplicating business rules in `asController(...)` instead of delegating.
- Assuming action route discovery works without `Actions::registerRoutes(...)` when using in-action `routes()`.

## References

- https://www.laravelactions.com/2.x/as-controller.html