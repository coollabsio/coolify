---
name: testing-best-practices
description: "Laravel test design and review. Use when selecting coverage, naming or structuring tests, choosing assertions or test data, isolating dependencies, testing HTTP or security boundaries, improving suite performance, or reviewing test value. Use framework guidance or search-docs for Pest and PHPUnit syntax."
license: MIT
metadata:
  author: laravel
---

# Testing Best Practices

This skill provides rules for designing Laravel tests. Each rule file explains what to do and why. Use `search-docs` for Laravel and Pest API syntax.
This project uses Pest. Follow the corresponding guidance in each rule.

## Consistency First

Read nearby tests before you choose syntax and organization.

A pattern repeated throughout the project is a convention, and project conventions take precedence over this skill. Follow them and give new tests the same structure.

These rules govern the tests you write now. An existing test that follows a project convention is not defective merely because it conflicts with this skill. Do not delete or rewrite it. If the convention has drawbacks, explain them and let the user decide.

## What to Test

Read this section before you write a test.

- Test observable behavior and application contracts. A test must pass after an implementation change if the behavior stays the same.
- Cover every changed decision and each applicable high-value failure mode. A decision is a branch, a validation, a calculation, or an authorization.
- Exercise declarations through behavior instead of repeating their text.
- Leave framework behavior to framework tests. Testing project configuration is not testing the framework. A constrained relationship, cast, scope, or validation rule belongs to this project.
- Keep every test that can detect a distinct defect. When two tests detect the same defect, trim the higher-layer test to one case and report the duplication. Do not delete an existing test.
- Write a feature test first. Write a unit test only for logic that does not use the framework.
- Write a browser test only for behavior in JavaScript that a feature test cannot reach. Put a browser test in `tests/Browser`, and call `assertNoJavaScriptErrors()` in it.
- Judge an architecture test by the convention it protects, not by the rules above. An `arch()` test declares a rule for an entire directory, such as the parent class of every model, the classes that may use an enum, or the methods every factory declares. It intentionally checks declarations and fails when a new file breaks the convention.
- Use the test tools that the project installs. Add a new test dependency, plugin, or browser only after the user asks for it.

## How to Apply

1. Read the code under test. Read the tests in the same directory. Identify every decision in the code.
2. Select every applicable branch in the rule index. Read every selected rule file.
3. Report each defect in the code before you write a test. Examples are a method with no body, a policy that no action calls, and a write action with no validation. Test the actual behavior. Report the defect to the user.
4. Write the tests. Run the smallest set of tests that covers the change. The tests must pass.
5. Check every applicable item in `rules/review.md` and every selected rule file. Resolve every mismatch before completion.

## Rule Index

Most changes need more than one rule file.

| Subject | Rule File |
| --- | --- |
| Test framework features that may already do the work | [`rules/finding-features.md`](rules/finding-features.md) |
| File layout, test names, and groups | [`rules/naming.md`](rules/naming.md) |
| Arrange-act-assert and choosing the correct assertion | [`rules/assertions.md`](rules/assertions.md) |
| Endpoint coverage, authentication, authorization, tenant isolation, validation, and browser tests | [`rules/endpoint-tests.md`](rules/endpoint-tests.md) |
| Factories, test data ownership, and repeated input values | [`rules/test-data.md`](rules/test-data.md) |
| Fakes, mocks, outbound HTTP, time, randomness, and databases | [`rules/isolation.md`](rules/isolation.md) |
| Escaping, injection, cross-tenant access, and privilege checks | [`rules/security.md`](rules/security.md) |
| Environment and CI settings for a slow suite | [`rules/performance.md`](rules/performance.md) |
| Reviewing a test or suite | [`rules/review.md`](rules/review.md) |
