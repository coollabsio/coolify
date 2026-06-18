---
name: laravel-actions
description: Build, refactor, and troubleshoot Laravel Actions using lorisleiva/laravel-actions. Use when implementing reusable action classes (object/controller/job/listener/command), converting service classes/controllers/jobs into actions, orchestrating workflows via faked actions, or debugging action entrypoints and wiring.
---

# Laravel Actions or `lorisleiva/laravel-actions`

## Overview

Use this skill to implement or update actions based on `lorisleiva/laravel-actions` with consistent structure and predictable testing patterns.

## Quick Workflow

1. Confirm the package is installed with `composer show lorisleiva/laravel-actions`.
2. Create or edit an action class that uses `Lorisleiva\Actions\Concerns\AsAction`.
3. Implement `handle(...)` with the core business logic first.
4. Add adapter methods only when needed for the requested entrypoint:
   - `asController` (+ route/invokable controller usage)
   - `asJob` (+ dispatch)
   - `asListener` (+ event listener wiring)
   - `asCommand` (+ command signature/description)
5. Add or update tests for the chosen entrypoint.
6. When tests need isolation, use action fakes (`MyAction::fake()`) and assertions (`MyAction::assertDispatched()`).

## Base Action Pattern

Use this minimal skeleton and expand only what is needed.

```php
<?php

namespace App\Actions;

use Lorisleiva\Actions\Concerns\AsAction;

class PublishArticle
{
    use AsAction;

    public function handle(int $articleId): bool
    {
        return true;
    }
}
```

## Project Conventions

- Place action classes in `App\Actions` unless an existing domain sub-namespace is already used.
- Use descriptive `VerbNoun` naming (e.g. `PublishArticle`, `SyncVehicleTaxStatus`).
- Keep domain/business logic in `handle(...)`; keep transport and framework concerns in adapter methods (`asController`, `asJob`, `asListener`, `asCommand`).
- Prefer explicit parameter and return types in all action methods.
- Prefer PHPDoc for complex data contracts (e.g. array shapes), not inline comments.

### When to Use an Action

- Use an Action when the same use-case needs multiple entrypoints (HTTP, queue, event, CLI) or benefits from first-class orchestration/faking.
- Keep a plain service class when logic is local, single-entrypoint, and unlikely to be reused as an Action.

## Entrypoint Patterns

### Run as Object

- (prefer method) Use static helper from the trait: `PublishArticle::run($id)`.
- Use make and call handle: `PublishArticle::make()->handle($id)`.
- Call with dependency injection: `app(PublishArticle::class)->handle($id)`.

### Run as Controller

- Use route to class (invokable style), e.g. `Route::post('/articles/{id}/publish', PublishArticle::class)`.
- Add `asController(...)` for HTTP-specific adaptation and return a response.
- Add request validation (`rules()` or custom validator hooks) when input comes from HTTP.

### Run as Job

- Dispatch with `PublishArticle::dispatch($id)`.
- Use `asJob(...)` only for queue-specific behavior; keep domain logic in `handle(...)`.
- In this project, job Actions often define additional queue lifecycle methods and job properties for retries, uniqueness, and timing control.

#### Project Pattern: Job Action with Extra Methods

```php
<?php

namespace App\Actions\Demo;

use App\Models\Demo;
use DateTime;
use Lorisleiva\Actions\Concerns\AsAction;
use Lorisleiva\Actions\Decorators\JobDecorator;

class GetDemoData
{
    use AsAction;

    public int $jobTries = 3;

    public int $jobMaxExceptions = 3;

    public function getJobRetryUntil(): DateTime
    {
        return now()->addMinutes(30);
    }

    public function getJobBackoff(): array
    {
        return [60, 120];
    }

    public function getJobUniqueId(Demo $demo): string
    {
        return $demo->id;
    }

    public function handle(Demo $demo): void
    {
        // Core business logic.
    }

    public function asJob(JobDecorator $job, Demo $demo): void
    {
        // Queue-specific orchestration and retry behavior.
        $this->handle($demo);
    }
}
```

Use these members only when needed:

- `$jobTries`: max attempts for the queued execution.
- `$jobMaxExceptions`: max unhandled exceptions before failing.
- `getJobRetryUntil()`: absolute retry deadline.
- `getJobBackoff()`: retry delay strategy per attempt.
- `getJobUniqueId(...)`: deduplication key for unique jobs.
- `asJob(JobDecorator $job, ...)`: access attempt metadata and queue-only branching.

### Run as Listener

- Register the action class as listener in `EventServiceProvider`.
- Use `asListener(EventName $event)` and delegate to `handle(...)`.

### Run as Command

- Define `$commandSignature` and `$commandDescription` properties.
- Implement `asCommand(Command $command)` and keep console IO in this method only.
- Import `Command` with `use Illuminate\Console\Command;`.

## Testing Guidance

Use a two-layer strategy:

1. `handle(...)` tests for business correctness.
2. entrypoint tests (`asController`, `asJob`, `asListener`, `asCommand`) for wiring/orchestration.

### Deep Dive: `AsFake` methods (2.x)

Reference: https://www.laravelactions.com/2.x/as-fake.html

Use these methods intentionally based on what you want to prove.

#### `mock()`

- Replaces the action with a full mock.
- Best when you need strict expectations and argument assertions.

```php
PublishArticle::mock()
    ->shouldReceive('handle')
    ->once()
    ->with(42)
    ->andReturnTrue();
```

#### `partialMock()`

- Replaces the action with a partial mock.
- Best when you want to keep most real behavior but stub one expensive/internal method.

```php
PublishArticle::partialMock()
    ->shouldReceive('fetchRemoteData')
    ->once()
    ->andReturn(['ok' => true]);
```

#### `spy()`

- Replaces the action with a spy.
- Best for post-execution verification ("was called with X") without predefining all expectations.

```php
$spy = PublishArticle::spy()->allows('handle')->andReturnTrue();

// execute code that triggers the action...

$spy->shouldHaveReceived('handle')->with(42);
```

#### `shouldRun()`

- Shortcut for `mock()->shouldReceive('handle')`.
- Best for compact orchestration assertions.

```php
PublishArticle::shouldRun()->once()->with(42)->andReturnTrue();
```

#### `shouldNotRun()`

- Shortcut for `mock()->shouldNotReceive('handle')`.
- Best for guard-clause tests and branch coverage.

```php
PublishArticle::shouldNotRun();
```

#### `allowToRun()`

- Shortcut for spy + allowing `handle`.
- Best when you want execution to proceed but still assert interaction.

```php
$spy = PublishArticle::allowToRun()->andReturnTrue();
// ...
$spy->shouldHaveReceived('handle')->once();
```

#### `isFake()` and `clearFake()`

- `isFake()` checks whether the class is currently swapped.
- `clearFake()` resets the fake and prevents cross-test leakage.

```php
expect(PublishArticle::isFake())->toBeFalse();
PublishArticle::mock();
expect(PublishArticle::isFake())->toBeTrue();
PublishArticle::clearFake();
expect(PublishArticle::isFake())->toBeFalse();
```

### Recommended test matrix for Actions

- Business rule test: call `handle(...)` directly with real dependencies/factories.
- HTTP wiring test: hit route/controller, fake downstream actions with `shouldRun` or `shouldNotRun`.
- Job wiring test: dispatch action as job, assert expected downstream action calls.
- Event listener test: dispatch event, assert action interaction via fake/spy.
- Console test: run artisan command, assert action invocation and output.

### Practical defaults

- Prefer `shouldRun()` and `shouldNotRun()` for readability in branch tests.
- Prefer `spy()`/`allowToRun()` when behavior is mostly real and you only need call verification.
- Prefer `mock()` when interaction contracts are strict and should fail fast.
- Use `clearFake()` in cleanup when a fake might leak into another test.
- Keep side effects isolated: fake only the action under test boundary, not everything.

### Pest style examples

```php
it('dispatches the downstream action', function () {
    SendInvoiceEmail::shouldRun()->once()->withArgs(fn (int $invoiceId) => $invoiceId > 0);

    FinalizeInvoice::run(123);
});

it('does not dispatch when invoice is already sent', function () {
    SendInvoiceEmail::shouldNotRun();

    FinalizeInvoice::run(123, alreadySent: true);
});
```

Run the minimum relevant suite first, e.g. `php artisan test --compact --filter=PublishArticle` or by specific test file.

## Troubleshooting Checklist

- Ensure the class uses `AsAction` and namespace matches autoload.
- Check route registration when used as controller.
- Check queue config when using `dispatch`.
- Verify event-to-listener mapping in `EventServiceProvider`.
- Keep transport concerns in adapter methods (`asController`, `asCommand`, etc.), not in `handle(...)`.

## Common Pitfalls

- Putting HTTP response/redirect logic inside `handle(...)` instead of `asController(...)`.
- Duplicating business rules across `as*` methods rather than delegating to `handle(...)`.
- Assuming listener wiring works without explicit registration where required.
- Testing only entrypoints and skipping direct `handle(...)` behavior tests.
- Overusing Actions for one-off, single-context logic with no reuse pressure.

## Topic References

Use these references for deep dives by entrypoint/topic. Keep `SKILL.md` focused on workflow and decision rules.

- Object entrypoint: `references/object.md`
- Controller entrypoint: `references/controller.md`
- Job entrypoint: `references/job.md`
- Listener entrypoint: `references/listener.md`
- Command entrypoint: `references/command.md`
- With attributes: `references/with-attributes.md`
- Testing and fakes: `references/testing-fakes.md`
- Troubleshooting: `references/troubleshooting.md`