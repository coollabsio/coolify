# Real-time Node workload status

- [x] Trace current Sentinel-to-Flux transport, inventory updates, and Coold status events.
- [x] Define a minimal typed event for managed container state and health changes.
- [x] Add tests before implementation in Sentinel and Coolify.
- [x] Publish events from Sentinel and apply them safely in Coolify.
- [x] Keep scheduled inventory as the recovery path.
- [x] Run focused and broader tests, format, and lint.
- [x] Release, install, and smoke test on the running dev stack and KVM Nodes.
- [x] Record results, clean up, commit, and push.

## Review

- Sentinel watches the Podman container event stream and sends a small typed runtime-change notification over the existing Flux stream.
- Flux forwards the notification through the existing authenticated internal event endpoint. Coolify queues the existing full inventory refresh, so there is only one reconciliation path.
- Old-connection events are ignored. The scheduled minute inventory remains the repair path for missed events.
- Live stop and start changes on KVM Node B reached Coolify in about one to two seconds.
- Sentinel CI and release passed. The released Flux and Sentinel builds are installed in the local development environment.
