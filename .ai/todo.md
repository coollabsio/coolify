# Flux system ping vertical slice

- [x] Add failing Sentinel tests for ping execution, expiry, and duplicate command IDs.
- [x] Add failing Flux tests for command routing, offline state, timeout, and result correlation.
- [x] Implement the internal authenticated Flux command endpoint.
- [x] Add failing Coolify tests for the Flux ping action and development-only Livewire UI.
- [x] Implement the Coolify ping action and Test connection UI.
- [x] Publish Sentinel `main`, wait for GHCR images, and verify the full development flow.
- [x] Run formatters, focused tests, static checks, and image checks.
- [x] Search GitHub issues and discussions and record the result.

## Review

- Sentinel commit `e0d924d` is on `main`; its release workflow completed successfully.
- Rust protocol, control, and Flux tests pass (33 tests); Clippy and the Flux image build pass.
- Coolify focused tests pass (10 tests, 36 assertions); Pint passes.
- Live development ping passed through Coolify, Flux, gRPC, and host Sentinel in 12 ms.
- GitHub has no exact match. Issue #5685 (open) is related to Coolify v5. Discussion #11599 (open) is similar because it concerns a host monitoring agent.
