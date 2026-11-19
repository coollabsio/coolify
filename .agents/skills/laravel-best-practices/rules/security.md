# Security Best Practices

## Control Mass Assignment

Define `$fillable` when a model is populated from request-derived arrays, or deliberately guard attributes by another consistent model convention. Laravel models guard all attributes by default; `$guarded = []` opts out of that protection.

```php
class User extends Model
{
    protected $fillable = [
        'name',
        'email',
        'password',
    ];
}
```

Do not pass untrusted request data to a model with `$guarded = []`. Mass-assignment protection controls which attributes `create()`, `fill()`, and `update()` may set; it does not validate values or authorize the operation.

## Authorize Protected Actions

Use policies, gates, or form request authorization for actions that depend on the current user's permissions. Authentication alone does not establish permission, and validation is not authorization.

```php
public function update(UpdatePostRequest $request, Post $post): RedirectResponse
{
    Gate::authorize('update', $post);

    $post->update($request->validated());

    return redirect()->route('posts.show', $post);
}
```

Authorization may instead live in the form request:

```php
public function authorize(): bool
{
    return $this->user()?->can('update', $this->route('post')) ?? false;
}
```

Public actions intentionally available to everyone do not need a redundant authorization check.

## Bind Query Parameters

Use Eloquent, the query builder, or explicit bindings instead of interpolating untrusted values into Structured Query Language (SQL). Bindings protect values, not identifiers such as column names or sort directions; map user-selected identifiers to an allow-list.

Incorrect:

```php
DB::select("SELECT * FROM users WHERE name = '{$request->name}'");
```

Correct:

```php
User::where('name', $request->name)->get();
User::whereRaw('LOWER(name) = ?', [$request->string('name')->lower()->toString()])->get();
```

## Escape Output in Its Context

Blade's `{{ }}` syntax HTML-escapes output. Use `{!! !!}` only for content that has been sanitized for the exact HTML context in which it is rendered. Escaping rules differ for HTML, URLs, JavaScript, and Cascading Style Sheets.

Incorrect for untrusted content:

```blade
{!! $user->bio !!}
```

Correct:

```blade
{{ $user->bio }}
```

## Apply Cross-Site Request Forgery Protection

Include `@csrf` in state-changing Blade forms handled by Laravel's `web` middleware. Routes intentionally excluded from cross-site request forgery (CSRF) verification, such as validated third-party webhooks, need their own authenticity check.

```blade
<form method="POST" action="/posts">
    @csrf
    <input type="text" name="title">
</form>
```

Inertia applications commonly use Axios, which returns the encrypted `XSRF-TOKEN` cookie in the `X-XSRF-TOKEN` header. Confirm equivalent configuration when using another HTTP client. Do not disable CSRF protection merely to fix a token mismatch.

## Rate Limit Sensitive Endpoints

Apply suitable rate limits to login attempts, password recovery, verification messages, and expensive or abuse-prone application programming interface (API) routes. Choose the limiter key deliberately; an Internet Protocol (IP) address alone can unfairly group users behind a shared network, while an account identifier alone can enable targeted denial of service.

```php
RateLimiter::for('login', function (Request $request) {
    return Limit::perMinute(5)->by(Str::transliterate(
        Str::lower($request->string('email')).'|'.$request->ip()
    ));
});

Route::post('/login', LoginController::class)->middleware('throttle:login');
```

Rate limiting reduces abuse; it does not replace authentication, authorization, or upstream denial-of-service protection.

## Validate and Store Uploads Safely

Validate expected content type, dimensions where relevant, and size. Laravel's `mimes` rule reads the file contents and guesses a Multipurpose Internet Mail Extensions (MIME) type corresponding to the listed extensions; it does not validate the user-assigned filename extension. The `extensions` rule checks that extension and should not be used by itself.

```php
public function rules(): array
{
    return [
        'avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
    ];
}
```

Use Laravel's storage methods to generate a filename, and store untrusted files outside a publicly executable location. Public files can require additional controls, such as image re-encoding, content-disposition headers, and explicit blocking of active formats.

```php
$path = $request->file('avatar')->store('avatars');
```

## Keep Secrets Out of Application Code

Do not commit populated environment files or hard-code credentials. Read environment variables in configuration files, then use `config()` in application code so configuration caching works correctly. See the configuration rules for encrypted environment files and external secret stores.

## Audit Dependencies

Run `composer audit` regularly and in continuous integration. Review findings for exploitability and update or mitigate affected packages promptly.

```bash
composer audit
```

## Encrypt Sensitive Attributes When Appropriate

Use an `encrypted` cast for sensitive values that must be recoverable, and use `$hidden` to omit them from array and JavaScript Object Notation (JSON) serialization. Hidden attributes remain accessible in PHP, and encryption does not replace access control. Encrypted values cannot be meaningfully queried and should use a `TEXT` or larger column because ciphertext length is variable.

```php
class Integration extends Model
{
    protected $hidden = ['api_key', 'api_secret'];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'api_secret' => 'encrypted',
        ];
    }
}
```
