# How to Find Test Framework Features

Pest adds features faster than this skill can list them. Find an existing feature before implementing the behavior by hand.

- Give `search-docs` the capability you need rather than the name of a function you remember. It returns features available in the installed version.
- Fetch `https://pestphp.com/llms.txt` for the complete feature list and additions in each release.
- If a search returns no results, tell the user that the installed version does not provide the feature. Do not write an API that you have not confirmed.

Search for a feature in this table before you write the code by hand.

| Work that you need | Term to search for |
| --- | --- |
| Run one test with many input values | datasets, bound datasets |
| Assert over many values or over a collection | higher-order expectations |
| Remove the same setup from each test in a file | hooks, higher-order tests |
| Apply a convention to the complete codebase | architecture testing |
| Measure if the suite finds a defect | mutation testing |
| Find code with no types | type coverage |
| Reduce the time of a slow suite | parallel, profiling |
| Run one test while you debug | filtering, `--bail`, `--dirty` |

## Built-in Laravel Assertion Methods

Laravel provides assertions for each part of the framework. Fetch `https://laravel.com/framework/docs/testing` for the complete list, and search for an assertion before building a check by hand. Examples include `assertDatabaseHas()`, `assertModelExists()`, `assertSoftDeleted()`, response assertions such as `assertRedirectToRoute()` and `assertJsonPath()`, and fake assertions such as `Queue::assertPushed()` and `Notification::assertSentTo()`.

A hand-built check fails with `false is not true`, which identifies nothing. A framework assertion names the incorrect table, value, or response, so the failure indicates what to fix.

```php
// The failure says that false is not true. Instead of this...
expect(User::where('email', 'taylor@laravel.com')->exists())->toBeTrue();

// Use this... the failure names the table and the attributes that it did not find...
$this->assertDatabaseHas('users', ['email' => 'taylor@laravel.com']);
```
