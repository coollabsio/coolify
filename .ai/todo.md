# Automatic development QEMU worker

- [x] Find the normal development startup entry point and existing environment conventions.
- [x] Add a failing test for the opt-in reset and seed behavior.
- [x] Add one disabled-by-default environment variable.
- [x] Run the QEMU reset only when the variable is enabled.
- [x] Verify disabled and enabled startup behavior.

## Review

- Added `DEVELOPMENT_QEMU_AUTO_START=false` to the development environment example.
- Jean now runs `scripts/dev-stack`, which reads the Compose environment and only recreates `v5-worker` when the variable is enabled.
- The startup waits for Coolify, recreates the VM, waits for cloud-init, and seeds the server before following Compose logs.
- Flux PKI initialization now waits for Postgres health as well as Coolify health.
- The full enabled startup completed and seeded `v5-worker` at `192.168.122.50`.
- Focused tests passed: 16 tests and 75 assertions.
