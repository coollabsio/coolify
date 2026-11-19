# Convention and Style Best Practices

## Follow Project Naming Conventions

Prefer Laravel's conventions in new code, but preserve an established project convention unless a coordinated rename is worthwhile.

| Element | Convention | Example |
| --- | --- | --- |
| Controller | Singular resource name | `ArticleController` |
| Model | Singular StudlyCase | `User` |
| Table | Plural snake_case | `article_comments` |
| Pivot table | Singular model names in alphabetical order, in snake_case | `article_user` |
| Column | snake_case | `meta_title` |
| Conventional foreign key | Singular model name plus `_id`, in snake_case | `article_id` |
| Resource URI | Plural resource | `articles/1` |
| Route name | Dotted segments; snake_case within a segment when needed | `users.show_active` |
| Method | camelCase | `getAll` |
| Variable | camelCase | `$articlesWithAuthor` |
| Collection | Descriptive and plural | `$activeUsers` |
| Object | Descriptive and singular | `$activeUser` |
| View | kebab-case | `show-filtered.blade.php` |
| Configuration file | snake_case | `google_calendar.php` |
| Enumeration | Singular StudlyCase | `UserType` |

## Prefer Clear, Idiomatic Syntax

Use Laravel helpers and query methods when they communicate intent more directly. Do not shorten code when the result is ambiguous or loses useful type information.

| More verbose | Idiomatic alternative |
| --- | --- |
| `Session::get('cart')` | `session('cart')` |
| `$request->session()->get('cart')` | `session('cart')` |
| `return Redirect::back()` | `return back()` |
| `Carbon::now()` | `now()` |
| `->where('column', '=', 1)` | `->where('column', 1)` |
| `->orderBy('created_at', 'desc')` | `->latest()` |
| `->orderBy('created_at', 'asc')` | `->oldest()` |
| `->first()?->name` | `->value('name')` when only that value is needed |

Use typed request accessors such as `$request->string()`, `$request->integer()`, and `$request->boolean()` when their coercion matches the operation.

## Use Utilities When They Clarify Intent

Laravel's `Str`, `Arr`, `Number`, and `Uri` utilities provide expressive operations and framework-consistent behavior. Prefer them when they are clearer or safer than an equivalent PHP operation, not as an unconditional replacement for every built-in function.

```php
$slug = Str::slug($title);
$short = Str::limit($text, 100);
$class = class_basename(User::class);
$result = Str::of($input)->trim()->replace('_', '-')->lower();
```

Use `Arr` for dot notation and common transformations:

```php
$name = Arr::get($array, 'user.name', 'default');
$public = Arr::only($attributes, ['name', 'email']);
```

Use `Number` for localized display formatting rather than values that will be stored or calculated:

```php
Number::format(1000000);
Number::currency(1500, 'USD');
Number::fileSize(1024 * 1024);
```

Use `Uri` when constructing or transforming a uniform resource identifier (URI) benefits from a structured API:

```php
$uri = Uri::of('https://example.com/search')
    ->withQuery(['q' => 'laravel', 'page' => 1]);
```

Check the documentation for the Laravel version supported by the project before using newer utility classes or methods.

## Keep Presentation Code Maintainable

Prefer the project's asset pipeline, components, and existing conventions for substantial JavaScript and Cascading Style Sheets (CSS). Small page-specific scripts or styles can be reasonable in Blade layouts or stacks; avoid mixing large behavior and style blocks into templates.

Pass server data with an encoding mechanism appropriate to its context. For example, Blade's `Js::from()` safely formats data for JavaScript:

```blade
<script>
    const article = {{ Js::from($article) }};
</script>
```

Data attributes are useful for small scalar values, but serializing a large model into an attribute can expose unnecessary fields and complicate escaping.

## Write Comments That Explain Why

Prefer clear names and small units of code over comments that merely restate an operation. Add concise comments for non-obvious constraints, tradeoffs, workarounds, regular expressions, or external behavior that the code cannot express by itself. Keep comments accurate when behavior changes.

Unhelpful:

```php
// Check whether the query has joins.
if (count((array) $builder->getQuery()->joins) > 0) {
    // ...
}
```

Clearer:

```php
if ($this->hasJoins()) {
    // ...
}
```
