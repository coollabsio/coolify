# Flux connection-only vertical slice

- [x] Add Coolify tests for Flux configuration, EdDSA assignment credentials, enabled assignments, and connection events.
- [x] Add `FLUX_PORT` with default 7443 and optional `FLUX_PUBLIC_URL`; keep the feature development-only.
- [x] Issue 15-minute EdDSA credentials with the accepted claims and return enabled assignments.
- [x] Add authenticated internal connection-event ingestion with bounded Redis state.
- [x] Add the GHCR Flux image and direct port mapping to the v5 development stack.
- [x] Show transport, endpoint, protocol, connection time, and last heartbeat in the development Sentinel UI.
- [x] Run Pint, focused Pest tests, and a live end-to-end check after Jean reported no configured Run environment.
- [x] Search GitHub issues and discussions, record results, and review the complete diff.

## Review

- Focused tests pass. Pint passes. The Compose configuration renders successfully.
- Jean reported no configured Run environment. The repository Compose stack connected the published host Sentinel `main` binary to the published Flux `main` image and recorded a heartbeat in Coolify.
- GitHub search found no fully fixed issue. Related: open issue #6050 and open discussion #11431. Similar: open issues #11111 and #11539.
