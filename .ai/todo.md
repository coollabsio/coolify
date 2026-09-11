# Flux server information vertical slice

- [x] Define the existing command and event contracts across Coolify, Flux, and Sentinel.
- [x] Add a failing Sentinel test for the server-information command.
- [x] Implement server information collection in Sentinel.
- [x] Add failing Coolify tests for request, persistence, authorization, and UI.
- [x] Implement the Coolify request and result flow.
- [x] Show durable server information and a refresh action in the UI.
- [x] Run focused tests, formatting, and an end-to-end development check.
- [x] Search related GitHub issues and discussions.
- [x] Record review results.

## Review

- Sentinel implements the existing `system.info.v1` capability and collects host, OS, kernel, CPU, memory, root storage, uptime, boot, and container-runtime data.
- Flux exposes the authenticated internal `POST /v1/commands/system.info` command endpoint.
- Coolify validates the response and saves it in `server_metadata` without removing unrelated metadata such as transfer state.
- The server overview refresh action uses Flux while the development host-agent gate is enabled. The normal SSH path remains unchanged outside that gate.
- The server overview now shows hostname, storage, container runtime, and Sentinel version with the existing server details.
- Sentinel protocol, control, and Flux tests passed (49 tests). Clippy passed with warnings denied.
- Coolify focused tests passed (26 tests, 118 assertions), Pint passed, and the frontend build completed.
- End-to-end development verification used the published `main` images: host Sentinel connected with TLS, `system.info.v1` returned real data, and Coolify persisted it.
- GitHub: #5685 (open) is related to v5. #11256 (closed) is a similar server-detail refresh report. No exact open issue or discussion matched this Flux slice.
