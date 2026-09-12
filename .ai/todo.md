# Complete node rename across Coolify and Sentinel

- [x] Rename the shared credential purpose to `node-control-channel` in issuer and verifier.
- [x] Update Sentinel tests and architecture specification.
- [x] Remove the obsolete QEMU old-name cleanup from Coolify.
- [x] Verify no old runtime or protocol names remain.
- [x] Run focused Coolify and Sentinel tests.
- [x] Commit both repositories and push Sentinel `main` to publish images.
- [x] Pull the published images and restart the development services.
- [x] Verify the live TLS connection with the new credential purpose.
- [x] Search related GitHub issues and discussions.

## Review

- Coolify now issues credentials with purpose `node-control-channel`.
- Sentinel now accepts only `node-control-channel` credentials.
- The obsolete old-name QEMU cleanup was removed; no old runtime identifiers remain.
- Sentinel commit `6f795f5` was pushed to `main`; CI and the multi-architecture image publication passed.
- The development Flux image and host Sentinel binary were updated from GHCR.
- The worker node reconnected to Flux over TLS and Sentinel is active.
- Sentinel verification passed: 15 Flux tests.
- Coolify verification passed: 35 tests and 131 assertions.
- No matching GitHub issues, pull requests, or discussions were found.
