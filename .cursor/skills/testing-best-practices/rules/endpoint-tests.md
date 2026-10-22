# Endpoint Tests

## How to Write the Test

Fetch `https://laravel.com/framework/docs/http-tests` for the request helpers, the authentication helpers, and the response assertions. Confirm the name before you use it, and do not guess an assertion.

Choose an assertion based on the subject of the check: the status, a header, a redirect, the JSON body, the session, a validation error, or the view. Laravel provides a named assertion for each subject that identifies the incorrect value.

## Endpoint Coverage

Write a test for each applicable case:

- The request has missing or invalid authentication.
- The request comes from a different tenant, team, or organization.
- The user has an insufficient role or permission.
- The request does not satisfy a route or scope constraint.
- The request fails the validation.
- The request is valid. Assert both the response and the persisted state.

Assert the application's actual behavior rather than a generic status code. An API returns `401` for a missing or invalid token, while a browser endpoint redirects to the sign-in route.

## Tenant Isolation

Assert the status code returned for a cross-tenant request. Use `404` rather than `403` when one tenant must not learn that another tenant's record exists, because `403` confirms its existence.

## Test Authorization at the Policy Level

An HTTP test shows that the endpoint performs authorization. It cannot identify which mechanism refused the request because middleware, a policy, and a call to `abort()` can all return `403`.

- Assert the complete matrix of the permissions against the policy or the gate. A failure then names the rule that is not correct.
- Write one HTTP test for one refused role, which shows that the endpoint calls the authorization.
- Use the helper of the project that asserts the ability and the arguments of the gate, if such a helper exists.

## Browser Tests

Write a browser test only for JavaScript behavior that an HTTP test cannot reach, such as modal interaction, drag-and-drop, live search, or client-side validation. Browser tests are slower than HTTP tests and can fail for reasons unrelated to the code under test.

- Assert the state that the user can see, and assert the state in the database that the interaction saves.
- Wait until the test reaches the required state. Do not wait for a fixed number of seconds, which can fail on a slower machine.
- Call `assertNoJavaScriptErrors()` in each browser test. An error in the console is a defect.

### Where a Browser Test Lives and How to Run It

The plugin runs browser tests as normal Pest tests, so they need no separate suite. Put them in `tests/Browser` to separate them from faster tests and run the directory with one command.

- Run a browser test with `vendor/bin/pest tests/Browser`, and add `--parallel` for the complete suite.
- Run `vendor/bin/pest --debug` to open the window of the browser and to pause at a failure. Use `--headed` to watch a run that passes.
- Add `--browser firefox` or `--browser safari` to run the test in a different browser. The default browser is Chrome.
- The run needs Playwright and a browser on the machine. Follow the plugin documentation for local and CI installation commands.
- Fetch `https://pestphp.com/docs/browser-testing` for the interactions, the assertions, and the devices that the plugin gives.

### Browser Test Pitfalls

- The plugin waits five seconds for an element. Raise the value with `pest()->browser()->timeout(10000)` in `Pest.php` for a page that is slower, and do not add a wait for a number of seconds to the test.
- Apply `RefreshDatabase` to the browser tests in `Pest.php`. A browser test hits the application through a real request, and the records that it leaves break the next test.
- Add `tests/Browser/Screenshots` to `.gitignore`. A failure writes a screenshot, and the file is not part of the repository.
- Give `withKeyDown()` a key code, such as `KeyA`. A letter such as `'a'` gives the lowercase character, whatever modifier the test holds.
- Interact inside the callback of `withinFrame()`. An interaction outside the callback does not reach the frame.

## Testing Validation

- Write one test for each validation rule when each failure represents a separate contract.
- Write one test with an empty payload to assert several required fields together.
- Assert the text of the message that the user gets. A message that is present but wrong is a defect.
- Use a dataset for input values that need the same setup and the same assertions.

Send an input value that is not valid through the application, and assert the error. Do not assert that an array of rules contains a string, because that assertion tests the declaration and not the behavior. Use such an assertion only for a rule that no request can reach, and write the reason in the test.

### Which Layer Owns Which Case

The rule-class test owns the matrix of values that pass and fail. The endpoint test proves that the endpoint applies the rule and that the user receives the message.

When both tests contain the matrix, move it to the rule-class test and retain one case in the endpoint test. Never remove the last case, because the rule-class test still passes if the request omits the rule. The same division applies to policies, scopes, and other classes called by a request.
