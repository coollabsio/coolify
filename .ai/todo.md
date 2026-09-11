# Task 5 — Sentinel Flux trust bootstrap and repair

- [x] Read task contract, existing CA models/actions, and Sentinel remote-process conventions.
- [x] Add focused failing Pest tests for installer and repair trust guarantees; run and confirm red.
- [x] Implement atomic CA trust install and repair, including safe command construction and rollback.
- [x] Run focused tests, authorization regression suite, and Pint; inspect the diff.
- [x] Search GitHub issues/discussions; write task report and commit.

## Review

- The installer stages and validates the public CA and positive version before it atomically replaces either live trust file or enables/restarts Sentinel.
- The repair action uses the same staged trust flow, keeps the existing token and endpoint lines, never downloads or replaces the Sentinel binary, and restores prior files plus the service after a validation failure.
- Focused feature, PKI lifecycle, and assignment authorization regressions pass. Generated shell scripts pass `bash -n`.
