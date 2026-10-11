# Assertions

## Arrange, Act, Assert

Write each test in three parts: setup, one action, and assertions. Put one blank line between them so readers can identify each part without comments.

Keep each test self-contained. Do not use values created by another test.

## How to Find the Correct Assertion

First identify the subject of the check, then find an assertion designed for it. A subject-specific assertion identifies the incorrect value when the test fails.

1. Search Laravel's assertions for framework subjects such as responses, the database, sessions, models, queues, events, mail, and notifications.
2. Fetch `https://pestphp.com/docs/expectations.md` for the expectations of Pest for a plain value, a type, a format, or a shape.
3. Build the check by hand only if no assertion exists for the subject.
4. Confirm the name in the documentation before you use it. Do not write an assertion that you did not confirm.

Use the assertion in this table for each subject.

| Subject | Assertion to use |
| --- | --- |
| A return value, the state of an object, or a transformation of a value | an `expect()` chain |
| An HTTP status, JSON, a session, or Inertia | a Laravel response assertion |
| The state in the database | a Laravel database assertion |
| The existence of a model | `assertModelExists($model)` rather than `assertDatabaseHas('users', ['id' => $user->id])` |

Use a PHPUnit assertion only if no Pest expectation and no Laravel assertion exists for the subject.

Assert each fact once. Do not assert a 200 status before `assertSee`, because `assertSee` already shows that the page rendered.

## Named Response Assertions

Use a named response assertion, such as `assertNotFound()`, rather than `assertStatus(404)`. A failure then identifies the broken contract. Laravel provides named assertions for commonly tested status codes.

Keep one `expect()` chain on one subject. Start a new chain when the subject changes, or when the chain is difficult to read.

## Assert a Known Value

Write the expected value in the test, or calculate the expected value by a different method. Do not calculate the expected value with the logic of the implementation, because the test then passes when that logic is wrong.

```php
// The test calculates the value with the logic of the implementation...
$expected = now()->subHours(24)->floorSeconds(30)->toJson();
expect($from)->toBe($expected);

// The test sets a fixed input and asserts a known value...
travelTo('2025-01-01 00:00:00');
expect($from)->toBe('2024-12-31T00:00:00.000000Z');
```

## Assert the Complete Result

A status code is not the complete result of a write operation. Assert each of the following if the operation changes it:

- The response or the return value.
- The state in the database.
- The jobs and the events that the operation dispatches.
- The notifications and the mail that the operation sends.

On the failure path, assert that the operation makes none of these changes. A test that asserts only `assertOk()` passes even when the application saves no record.
