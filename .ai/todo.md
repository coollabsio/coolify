# Implement container.list.v1

- [x] Grant and request the new capability through the existing Flux assignment.
- [x] Fetch and validate Node container inventory from Flux.
- [x] Reconcile the complete snapshot into `node_containers`.
- [x] Show read-only containers and manual refresh on the Node page.
- [x] Test the Coolify flow and live QEMU TLS path.
- [x] Update architecture notes and search GitHub issues and discussions.

## Review

- Sentinel commit `6507b6d` passed workspace tests and strict Clippy checks.
- Sentinel CI and the multi-architecture GHCR release completed successfully.
- Coolify passed 35 focused tests with 137 assertions, Pint, Blade compilation,
  and the production frontend build.
- The QEMU Node installed the new host binary, connected to the new Flux image
  over TLS, listed a live Podman container, and stored it as external.
- GitHub issue, pull request, and discussion searches found no matching items.
