# Naming and Structure

## File Layout

- Name each test file `{ClassName}Test.php`.
- Place each test file at the same relative path as the class under test. The class `app/Actions/DeleteTeam.php` gets the test `tests/Unit/Actions/DeleteTeamTest.php`.
- Follow the project's convention for fixture files. If none exists, put fixtures in `tests/Fixtures/` and load them by path.
- Move large literal values out of the test body and into fixture files.

## Test Function

Use the test function used by other files in the same directory. If no neighboring test files exist:

- Use `it()` for the behavior of the code, and write the name as a verb phrase.
- Use `test()` for a declarative fact, such as a grant in a policy, the labels of an enum, or the shape of a serialized model.

Use one Pest declaration style in each file. Use either `it()` or `test()` consistently.

## Naming Tests

The name of a test is a specification. State the user-visible result and the condition that causes it.

- Name the behavior, and not the method under test. The file name already gives the class.
- Give the exact status code in the name of a test for an API error.
- Do not write `Given`, `When`, or `Then` in the name.

```php
it('returns 401 when no token is provided', function () { ... });
it('does not include deployments from deleted environments', function () { ... });
it('falls back to the default region when none is configured', function () { ... });
```

Use a verb that describes a result, such as `returns`, `renders`, `creates`, `dispatches`, `rejects`, `forbids`, `falls back`, or `does not`.

Do not write `it('works correctly')` or `it('returns data')`, because neither specifies a meaningful result. Do not write `it('handleMethod creates record')`, because it names a method rather than behavior.

## Grouping

Use `describe()` if one file covers separate actions in a lifecycle. An example is a controller with the actions `index`, `show`, `store`, `update`, and `destroy`.

Do not use `describe()` in these cases:

- The file covers one action or one flow.
- The tests are different only in the input value. Use a dataset instead.
- The group adds a level but does not make the file easier to read.
