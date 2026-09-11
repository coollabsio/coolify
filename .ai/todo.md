# Task 5 — Fix round 1

- [x] Add executable failure-path tests for fresh install, update, and repair rollback; confirm red.
- [x] Restore Sentinel active and enabled state exactly after failure.
- [x] Correct unsafe input coverage with valid independent inputs.
- [x] Run focused and combined tests, shell syntax checks, Pint, and review the diff.
- [x] Append the Task 5 report and commit the fix.

## Review

- Executed shell-harness tests prove rollback stops the failed replacement before it restores files.
- Rollback now restores prior active and enabled state for fresh installs, updates, and repairs.
- Unsafe token, endpoint, image, CA, and version validation each have independent coverage.
