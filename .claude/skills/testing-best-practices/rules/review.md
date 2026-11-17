# Reviewing Tests

Check every item in this file. A passing test may still provide no value. For each test, identify the defect it would catch.

Report each finding. Do not delete or rewrite a test without the user's approval. When an issue appears throughout the suite as a convention, report the pattern once rather than every affected file.

## Test Value

Apply this section to behavioral tests. An architecture test states a convention for a directory, so these items do not apply to it.

- [ ] Each test covers observable behavior or an application contract, and passes after a change to the implementation that keeps the behavior.
- [ ] Each tested declaration is exercised through behavior, and no test asserts the behavior of the framework. A test of what this project configures, such as a relation with a constraint, a cast, or a scope, belongs to this project.
- [ ] Each test detects a distinct defect that no other test covers. A duplicate shrinks at the higher layer to the one case that proves the wiring.
- [ ] Every changed decision and each applicable high-value failure mode has coverage.

## Names and Structure

- [ ] Each file has the name `{ClassName}Test.php` and the relative path of the class under test.
- [ ] Each name states a result, the condition that causes it, and the status code for an API error.
- [ ] Each file uses one declaration style consistently, and each `describe()` group holds separate behavior.

## Coverage

- [ ] HTTP tests cover authentication, authorization, role, scope, and validation when applicable.
- [ ] A request for a record of a different tenant gets a status code that does not confirm that the record exists.
- [ ] The complete permission matrix belongs in policy tests, not controller tests.
- [ ] Each validation rule has one test that asserts the user-visible message. When a unit test owns a matrix, reduce duplicate higher-level coverage to one case rather than deleting it.
- [ ] Rendered user input and each dynamic part of a query have a security test.

## Data and Determinism

- [ ] Each test creates its mutable records directly or through a helper that it calls, and every created record arranges the behavior or supports an assertion.
- [ ] Each `beforeEach()` holds configuration only.
- [ ] Each factory state and each relationship gives the meaning of the data.
- [ ] Each call to `make()` is in a test that does not need the database.
- [ ] Time, randomness, sleep, and outbound HTTP are controlled.
- [ ] Each test passes alone, and passes in the complete suite in any order.

## Assertions

- [ ] Each expected value is a known value, and the test does not calculate the value with the logic of the implementation.
- [ ] Each test of a write operation asserts the response, the state in the database, and the side effects.
- [ ] Each fake has one assertion, and gives the class names unless the test asserts the complete result.
- [ ] Each `expect()` chain stays on one subject.
