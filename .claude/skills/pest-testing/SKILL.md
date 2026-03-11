---
name: pest-testing
description: >-
  Tests applications using the Pest 4 PHP framework. Activates when writing tests, creating unit or feature
  tests, adding assertions, testing Livewire components, browser testing, debugging test failures,
  working with datasets or mocking; or when the user mentions test, spec, TDD, expects, assertion,
  coverage, or needs to verify functionality works.
---

# Pest Testing 4

## When to Apply

Activate this skill when:

- Creating new tests (unit, feature, or browser)
- Modifying existing tests
- Debugging test failures
- Working with browser testing or smoke testing
- Writing architecture tests or visual regression tests

## Documentation

Use `search-docs` for detailed Pest 4 patterns and documentation.

## Test Directory Structure

- `tests/Feature/` and `tests/Unit/` — Legacy tests (keep, don't delete)
- `tests/v4/Feature/` — New feature tests (SQLite :memory: database)
- `tests/v4/Browser/` — Browser tests (Pest Browser Plugin + Playwright)
- `tests/Browser/` — Legacy Dusk browser tests (keep, don't delete)

New tests go in `tests/v4/`. The v4 suite uses SQLite :memory: with a schema dump (`database/schema/testing-schema.sql`) instead of running migrations.

Do NOT remove tests without approval.

## Running Tests

- All v4 tests: `php artisan test --compact tests/v4/`
- Browser tests: `php artisan test --compact tests/v4/Browser/`
- Feature tests: `php artisan test --compact tests/v4/Feature/`
- Specific file: `php artisan test --compact tests/v4/Browser/LoginTest.php`
- Filter: `php artisan test --compact --filter=testName`
- Headed (see browser): `./vendor/bin/pest tests/v4/Browser/ --headed`
- Debug (pause on failure): `./vendor/bin/pest tests/v4/Browser/ --debug`

## Basic Test Structure

<code-snippet name="Basic Pest Test Example" lang="php">

it('is true', function () {
    expect(true)->toBeTrue();
});

</code-snippet>

## Assertions

Use specific assertions (`assertSuccessful()`, `assertNotFound()`) instead of `assertStatus()`:

| Use | Instead of |
|-----|------------|
| `assertSuccessful()` | `assertStatus(200)` |
| `assertNotFound()` | `assertStatus(404)` |
| `assertForbidden()` | `assertStatus(403)` |

## Mocking

Import mock function before use: `use function Pest\Laravel\mock;`

## Datasets

Use datasets for repetitive tests:

<code-snippet name="Pest Dataset Example" lang="php">

it('has emails', function (string $email) {
    expect($email)->not->toBeEmpty();
})->with([
    'james' => 'james@laravel.com',
    'taylor' => 'taylor@laravel.com',
]);

</code-snippet>

## Browser Testing (Pest Browser Plugin + Playwright)

Browser tests use `pestphp/pest-plugin-browser` with Playwright. They run **outside Docker** — the plugin starts an in-process HTTP server and Playwright browser automatically.

### Key Rules

1. **Always use `RefreshDatabase`** — the in-process server uses SQLite :memory:
2. **Always seed `InstanceSettings::create(['id' => 0])` in `beforeEach`** — most pages crash without it
3. **Use `User::factory()` for auth tests** — create users with `id => 0` for root user
4. **No Dusk, no Selenium** — use `visit()`, `fill()`, `click()`, `assertSee()` from the Pest Browser API
5. **Place tests in `tests/v4/Browser/`**
6. **Views with bare `function` declarations** will crash on the second request in the same process — wrap with `function_exists()` guard if you encounter this

### Browser Test Template

<code-snippet name="Coolify Browser Test Template" lang="php">
<?php

use App\Models\InstanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::create(['id' => 0]);
});

it('can visit the page', function () {
    $page = visit('/login');

    $page->assertSee('Login');
});
</code-snippet>

### Browser Test with Form Interaction

<code-snippet name="Browser Test Form Example" lang="php">
it('fails login with invalid credentials', function () {
    User::factory()->create([
        'id' => 0,
        'email' => 'test@example.com',
        'password' => Hash::make('password'),
    ]);

    $page = visit('/login');

    $page->fill('email', 'random@email.com')
        ->fill('password', 'wrongpassword123')
        ->click('Login')
        ->assertSee('These credentials do not match our records');
});
</code-snippet>

### Browser API Reference

| Method | Purpose |
|--------|---------|
| `visit('/path')` | Navigate to a page |
| `->fill('field', 'value')` | Fill an input by name |
| `->click('Button Text')` | Click a button/link by text |
| `->assertSee('text')` | Assert visible text |
| `->assertDontSee('text')` | Assert text is not visible |
| `->assertPathIs('/path')` | Assert current URL path |
| `->assertSeeIn('.selector', 'text')` | Assert text in element |
| `->screenshot()` | Capture screenshot |
| `->debug()` | Pause test, keep browser open |
| `->wait(seconds)` | Wait N seconds |

### Debugging

- Screenshots auto-saved to `tests/Browser/Screenshots/` on failure
- `->debug()` pauses and keeps browser open (press Enter to continue)
- `->screenshot()` captures state at any point
- `--headed` flag shows browser, `--debug` pauses on failure

## SQLite Testing Setup

v4 tests use SQLite :memory: instead of PostgreSQL. Schema loaded from `database/schema/testing-schema.sql`.

### Regenerating the Schema

When migrations change, regenerate from the running PostgreSQL database:

```bash
docker exec coolify php artisan schema:generate-testing
```

## Architecture Testing

<code-snippet name="Architecture Test Example" lang="php">

arch('controllers')
    ->expect('App\Http\Controllers')
    ->toExtendNothing()
    ->toHaveSuffix('Controller');

</code-snippet>

## Common Pitfalls

- Not importing `use function Pest\Laravel\mock;` before using mock
- Using `assertStatus(200)` instead of `assertSuccessful()`
- Forgetting datasets for repetitive validation tests
- Deleting tests without approval
- Forgetting `assertNoJavaScriptErrors()` in browser tests
- **Browser tests: forgetting `InstanceSettings::create(['id' => 0])` — most pages crash without it**
- **Browser tests: forgetting `RefreshDatabase` — SQLite :memory: starts empty**
- **Browser tests: views with bare `function` declarations crash on second request — wrap with `function_exists()` guard**
