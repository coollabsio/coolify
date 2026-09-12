# Implement workload.deploy.v1

- [x] Add the typed deploy protocol and capability negotiation.
- [x] Add validated, shell-free Podman deployment in Sentinel.
- [x] Add the Flux deployment endpoint with a Coolify command ID.
- [x] Add the Coolify dispatch action and Laravel deployment job.
- [x] Connect durable operation states and container reconciliation.
- [x] Add a development UI entry point and operation status.
- [x] Test success, failure, duplicate replay, and the live QEMU flow.
- [x] Update architecture notes and search GitHub issues and discussions.

## Review

- Sentinel passed 58 protocol/control/Flux tests and strict Clippy checks.
- Coolify passed 48 focused tests with 160 assertions, Pint, and the Vite production build.
- Sentinel CI and the multi-architecture release completed successfully.
- The live QEMU Node deployed Alpine through Coolify, Flux, TLS gRPC, and Sentinel.
- A simulated lost response recovered with the same operation UUID and runtime ID; Sentinel did not run Podman twice.
- Coolify stored the operation as succeeded and reconciled the container as managed.
- GitHub search found related open v5 issue #5685 and open Sentinel metrics discussion #11431, plus similar closed Podman request #2720. None is fully fixed by this slice.
