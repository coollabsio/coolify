# Conventions & Style

## Follow Laravel Naming Conventions

| What | Convention | Good | Bad |
|------|-----------|------|-----|
| Controller | singular | `ArticleController` | `ArticlesController` |
| Model | singular | `User` | `Users` |
| Table | plural, snake_case | `article_comments` | `articleComments` |
| Pivot table | singular alphabetical | `article_user` | `user_article` |
| Column | snake_case, no model name | `meta_title` | `article_meta_title` |
| Foreign key | singular model + `_id` | `article_id` | `articles_id` |
| Route | plural | `articles/1` | `article/1` |
| Route name | snake_case with dots | `users.show_active` | `users.show-active` |
| Method | camelCase | `getAll` | `get_all` |
| Variable | camelCase | `$articlesWithAuthor` | `$articles_with_author` |
| Collection | descriptive, plural | `$activeUsers` | `$data` |
| Object | descriptive, singular | `$activeUser` | `$users` |
| View | kebab-case | `show-filtered.blade.php` | `showFiltered.blade.php` |
| Config | snake_case | `google_calendar.php` | `googleCalendar.php` |
| Enum | singular | `UserType` | `UserTypes` |

## Prefer Shorter Readable Syntax

| Verbose | Shorter |
|---------|---------|
| `Session::get('cart')` | `session('cart')` |
| `$request->session()->get('cart')` | `session('cart')` |
| `$request->input('name')` | `$request->name` |
| `return Redirect::back()` | `return back()` |
| `Carbon::now()` | `now()` |
| `App::make('Class')` | `app('Class')` |
| `->where('column', '=', 1)` | `->where('column', 1)` |
| `->orderBy('created_at', 'desc')` | `->latest()` |
| `->orderBy('created_at', 'asc')` | `->oldest()` |
| `->first()->name` | `->value('name')` |

## Use Laravel String & Array Helpers

Laravel provides `Str`, `Arr`, `Number`, and `Uri` helper classes that are more readable, chainable, and UTF-8 safe than raw PHP functions. Always prefer them.

Strings — use `Str` and fluent `Str::of()` over raw PHP:
```php
// Incorrect
$slug = strtolower(str_replace(' ', '-', $title));
$short = substr($text, 0, 100) . '...';
$class = substr(strrchr('App\Models\User', '\'), 1);

// Correct
$slug = Str::slug($title);
$short = Str::limit($text, 100);
$class = class_basename('App\Models\User');
```

Fluent strings — chain operations for complex transformations:
```php
// Incorrect
$result = strtolower(trim(str_replace('_', '-', $input)));

// Correct
$result = Str::of($input)->trim()->replace('_', '-')->lower();
```

Key `Str` methods to prefer: `Str::slug()`, `Str::limit()`, `Str::contains()`, `Str::before()`, `Str::after()`, `Str::between()`, `Str::camel()`, `Str::snake()`, `Str::kebab()`, `Str::headline()`, `Str::squish()`, `Str::mask()`, `Str::uuid()`, `Str::ulid()`, `Str::random()`, `Str::is()`.

Arrays — use `Arr` over raw PHP:
```php
// Incorrect
$name = isset($array['user']['name']) ? $array['user']['name'] : 'default';

// Correct
$name = Arr::get($array, 'user.name', 'default');
```

Key `Arr` methods: `Arr::get()`, `Arr::has()`, `Arr::only()`, `Arr::except()`, `Arr::first()`, `Arr::flatten()`, `Arr::pluck()`, `Arr::where()`, `Arr::wrap()`.

Numbers — use `Number` for display formatting:
```php
Number::format(1000000);          // "1,000,000"
Number::currency(1500, 'USD');    // "$1,500.00"
Number::abbreviate(1000000);      // "1M"
Number::fileSize(1024 * 1024);    // "1 MB"
Number::percentage(75.5);         // "75.5%"
```

URIs — use `Uri` for URL manipulation:
```php
$uri = Uri::of('https://example.com/search')
    ->withQuery(['q' => 'laravel', 'page' => 1]);
```

Use `$request->string('name')` to get a fluent `Stringable` directly from request input for immediate chaining.

Use `search-docs` for the full list of available methods — these helpers are extensive.

## No Inline JS/CSS in Blade

Do not put JS or CSS in Blade templates. Do not put HTML in PHP classes.

Incorrect:
```blade
let article = `{{ json_encode($article) }}`;
```

Correct:
```blade
<button class="js-fav-article" data-article='@json($article)'>{{ $article->name }}</button>
```

Pass data to JS via data attributes or use a dedicated PHP-to-JS package.

## No Unnecessary Comments

Code should be readable on its own. Use descriptive method and variable names instead of comments. The only exception is config files, where descriptive comments are expected.

Incorrect:
```php
// Check if there are any joins
if (count((array) $builder->getQuery()->joins) > 0)
```

Correct:
```php
if ($this->hasJoins())
```
