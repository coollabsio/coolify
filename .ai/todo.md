# Implement durable Node operations

- [x] Add the Node operation state model, schema, factory, and relationships.
- [x] Add atomic operation creation and state-transition actions.
- [x] Add retention rules: 30 days for success and 90 days for other final states.
- [x] Schedule daily cleanup without deleting active operations.
- [x] Add authorization and focused tests.
- [x] Add the Sentinel durable execution journal and restart-safe replay.
- [x] Verify both repositories and update the v5 architecture notes.
- [x] Search GitHub issues and discussions.

## Review

- Coolify passed 36 focused tests with 131 assertions and Pint.
- The development database migration and scheduled cleanup registration work.
- A live development operation completed with its attempt, result, and timestamps stored.
- Sentinel passed its full workspace suite, formatting, and strict Clippy checks.
- File-backed Sentinel tests prove result replay after a process restart.
- Sentinel CI and the multi-architecture GHCR release completed successfully.
- The new host binary created its SQLite journal on the QEMU Node. Two commands
  completed across a Sentinel restart, and both durable records remained.
- GitHub issue, pull request, and discussion searches found no related matches.
