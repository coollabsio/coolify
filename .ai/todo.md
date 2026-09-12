# Record permanent dual architecture

- [x] Record that legacy servers and nodes remain permanently supported.
- [x] Define the boundary between the two models.
- [x] Update existing Sentinel and installation decisions for consistency.
- [x] Update the current-state architecture and local lessons.
- [x] Add architecture regression assertions.
- [x] Verify documentation consistency.
- [x] Search related GitHub issues and discussions.

## Review

- Decision 0004 establishes permanent parallel `Server` and `Node` models.
- Legacy Docker servers keep SSH and container Sentinel support.
- Podman nodes use host-native Sentinel and Flux, with SSH for bootstrap and recovery.
- Both types can exist in the same team, project, environment, and Coolify installation.
- Conversion is never required or automatic.
- The current `servers.mode` code is explicitly transitional and will be replaced before release.
- The first implementation step is the `nodes` table; `node_containers` follows with `container.list`.
- Decision and architecture tests passed: 7 tests and 67 assertions.
- No related GitHub issues, pull requests, or discussions were found.
