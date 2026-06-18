# With Attributes (`WithAttributes` trait)

## Scope

Use this reference when an action stores and validates input via internal attributes instead of method arguments.

## Recap

- Documents attribute lifecycle APIs (`setRawAttributes`, `fill`, `fillFromRequest`, readers/writers).
- Clarifies behavior of key collisions (`fillFromRequest`: request data wins over route params).
- Lists validation/authorization hooks reused from controller validation pipeline.
- Includes end-to-end example from fill to `validateAttributes()` and `handle(...)`.

## Methods provided (`WithAttributes` trait)

### `setRawAttributes`

Replaces all attributes with the provided payload.

```php
$action->setRawAttributes([
    'key' => 'value',
]);
```

### `fill`

Merges provided attributes into existing attributes.

```php
$action->fill([
    'key' => 'value',
]);
```

### `fillFromRequest`

Merges request input and route parameters into attributes. Request input has priority over route parameters when keys collide.

```php
$action->fillFromRequest($request);
```

### `all`

Returns all attributes.

```php
$action->all();
```

### `only`

Returns attributes matching the provided keys.

```php
$action->only('title', 'body');
```

### `except`

Returns attributes excluding the provided keys.

```php
$action->except('body');
```

### `has`

Returns whether an attribute exists for the given key.

```php
$action->has('title');
```

### `get`

Returns the attribute value by key, with optional default.

```php
$action->get('title');
$action->get('title', 'Untitled');
```

### `set`

Sets an attribute value by key.

```php
$action->set('title', 'My blog post');
```

### `__get`

Accesses attributes as object properties.

```php
$action->title;
```

### `__set`

Updates attributes as object properties.

```php
$action->title = 'My blog post';
```

### `__isset`

Checks attribute existence as object properties.

```php
isset($action->title);
```

### `validateAttributes`

Runs authorization and validation using action attributes and returns validated data.

```php
$validatedData = $action->validateAttributes();
```

## Methods used (`AttributeValidator`)

`WithAttributes` uses the same authorization/validation hooks as `AsController`:

- `prepareForValidation`
- `authorize`
- `rules`
- `withValidator`
- `afterValidator`
- `getValidator`
- `getValidationData`
- `getValidationMessages`
- `getValidationAttributes`
- `getValidationRedirect`
- `getValidationErrorBag`
- `getValidationFailure`
- `getAuthorizationFailure`

## Example

```php
class CreateArticle
{
    use AsAction;
    use WithAttributes;

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:8'],
            'body' => ['required', 'string'],
        ];
    }

    public function handle(array $attributes): Article
    {
        return Article::create($attributes);
    }
}

$action = CreateArticle::make()->fill([
    'title' => 'My first post',
    'body' => 'Hello world',
]);

$validated = $action->validateAttributes();
$article = $action->handle($validated);
```

## Checklist

- Attribute keys are explicit and stable.
- Validation rules match expected attribute shape.
- `validateAttributes()` is called before side effects when needed.
- Validation/authorization hooks are tested in focused unit tests.

## Common pitfalls

- Mixing attribute-based and argument-based flows inconsistently in the same action.
- Assuming route params override request input in `fillFromRequest` (they do not).
- Skipping `validateAttributes()` when using external input.

## References

- https://www.laravelactions.com/2.x/with-attributes.html