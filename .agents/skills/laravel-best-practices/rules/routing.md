# Routing and Controller Best Practices

## Use Implicit Route Model Binding

Let Laravel resolve models from route parameters when the default lookup and missing-model behavior fit the endpoint.

Instead of manual lookup:

```php
public function show(int $id): View
{
    $post = Post::findOrFail($id);

    return view('posts.show', ['post' => $post]);
}
```

Use route model binding:

```php
public function show(Post $post): View
{
    return view('posts.show', ['post' => $post]);
}
```

## Scope Nested Bindings

Use scoped bindings when a nested resource must belong to its parent. This constrains model resolution; it does not replace authorization.

```php
Route::get('/users/{user}/posts/{post}', function (User $user, Post $post) {
    // The resolved post belongs to the resolved user.
})->scopeBindings();
```

## Use Resource Routes for Resourceful Actions

Use `Route::resource()` or `Route::apiResource()` when the endpoint follows Laravel's resource-controller actions. Define explicit routes when the behavior does not fit that vocabulary.

```php
Route::resource('posts', PostController::class);

// Alternatively, for an API-only resource:
Route::apiResource('posts', ApiPostController::class);
```

`apiResource()` omits the HTML-oriented `create` and `edit` routes. It does not itself add an `/api` prefix; that prefix comes from the application's API route configuration.

## Organize Controllers Around Resources

As a general default, organize each controller around one resource and use Laravel's standard resource actions: `index`, `show`, `create`, `store`, `edit`, `update`, and `destroy`. This keeps routes predictable and prevents controllers from accumulating unrelated behavior.

When a controller needs a custom action such as `publish`, `approve`, or `archive`, first consider whether that behavior represents a separate resource. A focused resource controller gives the behavior its own authorization, validation, and middleware boundary.

Custom action on the primary controller:

```php
Route::post('/podcasts/{podcast}/publish', [PodcastController::class, 'publish']);
```

The published podcast modeled as a resource:

```php
Route::post('/published-podcasts/{podcast}', [PublishedPodcastController::class, 'store'])
    ->name('published-podcasts.store');

Route::delete('/published-podcasts/{podcast}', [PublishedPodcastController::class, 'destroy'])
    ->name('published-podcasts.destroy');
```

```php
class PublishedPodcastController extends Controller
{
    public function store(Podcast $podcast): RedirectResponse
    {
        $podcast->publish();

        return back();
    }

    public function destroy(Podcast $podcast): RedirectResponse
    {
        $podcast->unpublish();

        return back();
    }
}
```

Treat a custom verb as a design signal, not proof that another controller is required. Use query parameters for simple filtering, and keep an explicit action route when modeling the operation as a resource would obscure the domain or conflict with established project conventions.

## Keep Controllers Focused on HTTP Concerns

Controllers should coordinate HTTP input, authorization, validation, an application operation, and the response. Extract substantial or reusable business logic, but do not introduce an action or service merely to satisfy an arbitrary line limit.

```php
public function store(StorePostRequest $request, CreatePostAction $create): RedirectResponse
{
    $post = $create->handle($request->validated());

    return redirect()->route('posts.show', $post);
}
```

A form request can perform validation and authorization before the controller runs. Do not repeat its rules in the controller. Keep simple, endpoint-specific validation inline when extraction would not improve reuse or clarity; see the validation rules for detailed guidance.
