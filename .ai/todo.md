# Implement permanent Node model

- [x] Add the node schema, model, role enum, factory, relationships, and policy.
- [x] Move QEMU node seeding and validation from Server to Node.
- [x] Move Flux assignment, events, ping, information, and metadata to Node.
- [x] Support Node SSH bootstrap, install, update, trust repair, and restart.
- [x] Add a development Node Sentinel page and keep legacy Server UI unchanged.
- [x] Remove ServerMode, servers.mode, and all Server node branches.
- [x] Reinitialize the development database record and host Sentinel.
- [x] Run focused and regression tests, then verify the live TLS flow.
- [x] Search related GitHub issues and discussions.

## Review

- Added a permanent `nodes` boundary. Legacy `servers` remain Docker and SSH managed.
- The development QEMU worker is now a `Node`; it validates Podman and runs host Sentinel through systemd.
- Flux assignment, connection state, ping, and system information resolve only `Node` records.
- Added the development-only `/node/{node_uuid}` Sentinel and Flux control page.
- Removed the unreleased `servers.mode`, `ServerMode`, and mixed Server/node code paths.
- Verified 158 focused tests with 594 assertions. The repository-wide Pest command is blocked by the existing duplicate test-case declaration in `tests/Feature/S3StorageFormTest.php`.
- Built frontend assets. The first build exposed a missing optional Rolldown package; `npm install --include=optional` restored it without tracked dependency changes.
- Live QEMU verification passed: Podman validation, Sentinel systemd installation, TLS Flux connection, ping, and system information.
- GitHub issue and discussion searches found no matching Sentinel, Flux, Node, or Podman reports.
