# Test Suite Performance

These settings apply to the project and CI, not to individual tests. Read `rules/isolation.md` for choices within a test.

Fetch `https://pestphp.com/docs/optimizing-tests` for Pest options that make test runs faster.
Verify each flag in the documentation before adding it to CI.

Measure before changing a setting. Find the slow test first, and apply a project-wide setting only after identifying the costly work.

## Test Environment

- Set `BCRYPT_ROUNDS=4` in `.env.testing` or in `phpunit.xml`. The default value is 12, and the hash then takes most of the time of each test that signs a user in.
- Disable XDebug. Disable pcov also, unless the run needs the coverage.
- Disable packages that perform work on every request in the test environment. Examples are Pulse, Telescope, and Nightwatch.
- Use the `WithCachedConfig` and `WithCachedRoutes` traits, so the run does not parse the configuration and the routes for every test.
- Call `withoutVite()`, or `withoutMix()`, so the framework does not resolve a built asset.

## Global Fakes

Put these three calls in the base `Pest.php` of the project:

- `Http::preventStrayRequests()`, because one request that reaches the network can slow the suite. This catches requests made through Laravel's HTTP client. Check direct Guzzle and cURL usage separately.
- `Sleep::fake(syncWithCarbon: true)`, so a retry and a backoff do not sleep.
- `Exceptions::fake()`, so the suite does not report an exception to an external service.

## How to Run the Suite in Parallel

Run `vendor/bin/pest --parallel` to spread tests across the machine's CPU cores. Add `--processes=N` if the default count is unsuitable for the machine or CI.

A parallel run gives each process a separate database. Tests must meet these conditions; a test that fails only in parallel breaks one of them:

- The test creates each record that it reads. It does not read a record that another test creates.
- The test does not depend on the order of the run.
- The test does not share a file, a cache key, or a queue with another test. Give each process a separate name for such a resource.

## How to Find a Slow Test

Run `vendor/bin/pest --profile` to list the slowest tests. Start with the ten slowest tests, because the same cause often applies to the complete suite.

If the cause of a slow test is unclear, add an event listener or temporary log entry to identify its work.

## Common Errors

- The run loads XDebug for a test that does not need it.
- `BCRYPT_ROUNDS` keeps the default value, because the project has no `.env.testing`.
- The code under test calls the real `sleep()`, and `Sleep::fake()` then does not help.
