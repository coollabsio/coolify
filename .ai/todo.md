# Publish and verify reverse DNS

- [x] Confirm both feature commits are present on their remote branches.
- [x] Monitor the Sentinel CI and release build for commit `8ddd4f1`.
- [x] Confirm the published Sentinel host artifact contains the reverse-DNS build.
- [x] Verify Coolify branch CI for commit `a29a84d33`.
- [x] Reinstall or upgrade Sentinel on the test Nodes from the published artifact.
- [x] Test forward DNS, reverse DNS over UDP and TCP, restart recovery, and SSH repair.
- [x] Search related GitHub issues and discussions.
- [x] Record the final verification results.

## Review

- Sentinel CI and Sentinel Release Main passed for `8ddd4f1`.
- `ghcr.io/coollabsio/sentinel-host:main` published manifest `sha256:c84e0ad943ba2de39d5a2280d68ee8686cd760690f9a918dcb1bd027319f34f0`.
- The Coolify installer pulled the published image and reinstalled Sentinel on both QEMU Nodes.
- Restart and SSH repair checks found and fixed an AWK variable error that omitted peer PTR routes; fix commit `5672452f5` is pushed.
- Both Nodes now route both exact reverse names and resolve both workloads through UDP and TCP. Forward A records also pass.
- Coolify has no workflow runs configured for `v5-progress`; local Pint and 29 focused tests passed.
- No matching Coolify or Sentinel issues were found. Related open discussion: https://github.com/coollabsio/coolify/discussions/9377.
