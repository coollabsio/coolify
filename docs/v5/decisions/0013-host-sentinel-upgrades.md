# Decision 0013: Upgrade host Sentinel one Node at a time with an automatic rollback

**Status: Accepted**

**Date: 2026-11-11**

## Context

Coolify pins the host Sentinel release with a version and digest
(`sentinel-host` in `upstream.json` and `versions.json`). The installer keeps
the previous binary and service files and restores them when an install fails.
There is no policy for upgrading the Nodes of a cluster.

## Decision

- Coolify installs only the pinned `sentinel-host` version and digest.
- **Upgrade cluster** upgrades one Node at a time. After each Node, Coolify waits
  until the Node reconnects to Flux and reports its capabilities. It stops at the
  first failure.
- When an upgraded Node does not reconnect within 5 minutes, Coolify restores the
  previous Sentinel over SSH.
- Upgrades are manual in v5.0.
- Coolify keeps capability gating. A Node that runs the previous version keeps
  working, and features that need a new capability are not available on it.

## Consequences

- An upgrade never takes all Nodes of a cluster down at once.
- SSH stays necessary as the recovery path.

## Not decided here

- Automatic upgrades.
- The oldest Sentinel version that Coolify supports.
