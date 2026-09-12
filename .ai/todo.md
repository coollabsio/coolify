# Runtime-aware server validation

- [x] Review current Docker validation and the accepted v5 server modes.
- [x] Approve the explicit server-mode design.
- [x] Add the server-mode migration and enum.
- [x] Route validation to Docker or Podman by server mode.
- [x] Update v5 QEMU seeding and UI messages.
- [x] Add regression tests and verify the real VM.

## Review

- Existing servers default to `legacy` and keep Docker validation.
- Native `v5-worker` and `v5-combined` servers validate Podman, systemd, and the rootful API socket.
- The QEMU v5 worker is no longer marked usable by its seeder.
- The validation UI shows Podman checkpoints for native workers and hides the Docker Compose checkpoint.
- Focused tests passed: 54 tests and 197 assertions.
- The running `coolify-dev-v5-worker` VM passed the real Podman validation through Coolify.
