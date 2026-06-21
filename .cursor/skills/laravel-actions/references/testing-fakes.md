# Testing and Action Fakes

## Scope

Use this reference when isolating action orchestration in tests.

## Recap

- Summarizes all `AsFake` helpers (`mock`, `partialMock`, `spy`, `shouldRun`, `shouldNotRun`, `allowToRun`).
- Clarifies when to assert execution versus non-execution.
- Covers fake lifecycle checks/reset (`isFake`, `clearFake`).
- Provides branch-oriented test examples for orchestration confidence.

## Core methods

- `mock()`
- `partialMock()`
- `spy()`
- `shouldRun()`
- `shouldNotRun()`
- `allowToRun()`
- `isFake()`
- `clearFake()`

## Recommended pattern

- Test `handle(...)` directly for business rules.
- Test entrypoints for wiring/orchestration.
- Fake only at the boundary under test.

## Methods provided (`AsFake` trait)

### `mock`

Swaps the action with a full mock.

```php
FetchContactsFromGoogle::mock()
    ->shouldReceive('handle')
    ->with(42)
    ->andReturn(['Loris', 'Will', 'Barney']);
```

### `partialMock`

Swaps the action with a partial mock.

```php
FetchContactsFromGoogle::partialMock()
    ->shouldReceive('fetch')
    ->with('some_google_identifier')
    ->andReturn(['Loris', 'Will', 'Barney']);
```

### `spy`

Swaps the action with a spy.

```php
$spy = FetchContactsFromGoogle::spy()
    ->allows('handle')
    ->andReturn(['Loris', 'Will', 'Barney']);

// ...

$spy->shouldHaveReceived('handle')->with(42);
```

### `shouldRun`

Helper adding expectation on `handle`.

```php
FetchContactsFromGoogle::shouldRun();

// Equivalent to:
FetchContactsFromGoogle::mock()->shouldReceive('handle');
```

### `shouldNotRun`

Helper adding negative expectation on `handle`.

```php
FetchContactsFromGoogle::shouldNotRun();

// Equivalent to:
FetchContactsFromGoogle::mock()->shouldNotReceive('handle');
```

### `allowToRun`

Helper allowing `handle` on a spy.

```php
$spy = FetchContactsFromGoogle::allowToRun()
    ->andReturn(['Loris', 'Will', 'Barney']);

// ...

$spy->shouldHaveReceived('handle')->with(42);
```

### `isFake`

Returns whether the action has been swapped with a fake.

```php
FetchContactsFromGoogle::isFake(); // false
FetchContactsFromGoogle::mock();
FetchContactsFromGoogle::isFake(); // true
```

### `clearFake`

Clears the fake instance, if any.

```php
FetchContactsFromGoogle::mock();
FetchContactsFromGoogle::isFake(); // true
FetchContactsFromGoogle::clearFake();
FetchContactsFromGoogle::isFake(); // false
```

## Examples

### Orchestration test

```php
it('runs sync contacts for premium teams', function () {
    SyncGoogleContacts::shouldRun()->once()->with(42)->andReturnTrue();

    ImportTeamContacts::run(42, isPremium: true);
});
```

### Guard-clause test

```php
it('does not run sync when integration is disabled', function () {
    SyncGoogleContacts::shouldNotRun();

    ImportTeamContacts::run(42, integrationEnabled: false);
});
```

## Checklist

- Assertions verify call intent and argument contracts.
- Fakes are cleared when leakage risk exists.
- Branch tests use `shouldRun()` / `shouldNotRun()` where clearer.

## Common pitfalls

- Over-mocking and losing behavior confidence.
- Asserting only dispatch, not business correctness.

## References

- https://www.laravelactions.com/2.x/as-fake.html