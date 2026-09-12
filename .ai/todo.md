# Rename native servers to nodes

- [x] Rename all active node-mode values.
- [x] Rename the eligibility API from `isNativeV5()` to `isNode()`.
- [x] Add a data migration for existing `v5-*` rows.
- [x] Rename the QEMU profile, domain, UUID, labels, and automatic startup.
- [x] Update active architecture documents, tests, and lessons.
- [x] Recreate the development QEMU node and restart the stack.
- [x] Verify code, database state, VM state, Sentinel, and Flux.

## Review

- Runtime modes are now `legacy`, `node-worker`, `node-controller-worker`, and `node-controller`.
- Existing `v5-*` database values migrate to the matching node modes.
- The development VM is now `coolify-dev-node-worker` with UUID `development-qemu-node-worker`.
- Reset removes the old `coolify-dev-v5-worker` domain and files.
- The recreated node runs host-native Sentinel and reconnects to Flux over TLS after the Coolify and Flux containers restart.
- Focused verification passed: 77 tests and 366 assertions.
