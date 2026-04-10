# Testing Best Practices

## Use `LazilyRefreshDatabase` Over `RefreshDatabase`

`RefreshDatabase` runs all migrations every test run even when the schema hasn't changed. `LazilyRefreshDatabase` only migrates when needed, significantly speeding up large suites.

## Use Model Assertions Over Raw Database Assertions

Incorrect: `$this->assertDatabaseHas('users', ['id' => $user->id]);`

Correct: `$this->assertModelExists($user);`

More expressive, type-safe, and fails with clearer messages.

## Use Factory States and Sequences

Named states make tests self-documenting. Sequences eliminate repetitive setup.

Incorrect: `User::factory()->create(['email_verified_at' => null]);`

Correct: `User::factory()->unverified()->create();`

## Use `Exceptions::fake()` to Assert Exception Reporting

Instead of `withoutExceptionHandling()`, use `Exceptions::fake()` to assert the correct exception was reported while the request completes normally.

## Call `Event::fake()` After Factory Setup

Model factories rely on model events (e.g., `creating` to generate UUIDs). Calling `Event::fake()` before factory calls silences those events, producing broken models.

Incorrect: `Event::fake(); $user = User::factory()->create();`

Correct: `$user = User::factory()->create(); Event::fake();`

## Use `recycle()` to Share Relationship Instances Across Factories

Without `recycle()`, nested factories create separate instances of the same conceptual entity.

```php
Ticket::factory()
    ->recycle(Airline::factory()->create())
    ->create();
```