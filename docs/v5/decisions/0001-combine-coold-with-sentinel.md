# Decision 0001: Combine coold with Sentinel

**Status: Accepted**

**Date: 2026-09-09**

## Context

Sentinel currently runs as a Docker container on servers managed by Coolify v4.
It collects host and container metrics, reports container state, provides a
heartbeat, stores metrics, and can collect traffic analytics.

The previous v5 prototype introduced coold as a separate host agent. coold
maintains an outbound connection to Flux and performs validated host-local
operations such as Podman container management, service-discovery sync,
embedded DNS, firewall reconciliation, ingress management, and host credential
rotation.

Sentinel will become mandatory. The transition can temporarily run the current
container and the host-native process together, but the final architecture
must not keep two different agent products.

## Decision

V4 continues to run Sentinel as a Docker container.

V5 runs Sentinel as a mandatory host-native binary managed by systemd.

coold functionality will move into the Sentinel project. The host agent keeps
the Sentinel name. Container and host deployments use the same Sentinel product and executable.
The Sentinel project will produce the v4 container image and the v5 host-native
Linux artifacts from the same source revision.

The current container and host-native Sentinel both can run during a controlled transition.
They have separate capability ownership:

- the container Sentinel continues current metrics, container observation, and
  traffic behavior;
- the host-native Sentinel first owns the v5 control connection and new typed
  host operations; and
- Coolify explicitly records which deployment owns each capability so that the
  two processes do not perform the same operation.

Coolify will move observational capabilities to the host-native Sentinel in
small steps. It will retire the container after capability parity, health
validation, and rollback validation. The final v5 state has one host-native
Sentinel process.

The v4 container retains Sentinel's current monitoring responsibilities. The
v5 binary adds the host-agent responsibilities previously implemented by coold,
including:

- the outbound Flux connection;
- validated runtime commands;
- service-discovery synchronization and embedded DNS;
- firewall reconciliation;
- ingress operations; and
- host credential rotation.

Moving coold into Sentinel does not move Coolify product decisions onto the
managed host. Coolify remains responsible for users, teams, authorization,
applications, projects, environments, placement, deployment orchestration,
secrets, billing, notifications, and durable product state.

The combined agent must preserve coold's typed commands and local safety checks.
It must not expose unrestricted shell, Docker, or Podman passthrough.

## Consequences

- Coolify has one long-term agent product and one agent project to release and
  support.
- V4 remains compatible with the existing Sentinel container deployment.
- A transition can contain two Sentinel processes, but they must not own the
  same capability at the same time.
- V5 gains a mandatory agent that can safely perform privileged host-local
  operations.
- The v5 package and installer must install, configure, update, and supervise a
  systemd service.
- Sentinel must keep observational failures isolated from privileged command
  execution where possible.
- The Sentinel project must accommodate its current Docker integration and the
  Podman integration moved from coold until a later decision defines the final
  runtime strategy.

## Not decided here

This decision does not define:

- the final internal crate or module layout;
- whether v5 ultimately supports Docker, Podman, or both;
- the detailed installation, upgrade, rollback, or v4-to-v5 migration flow;
- changes to the Flux protocol or deployment topology;
- the final capability and authentication formats; or
- the detailed capability migration schedule and the exact point when the v4
  Sentinel container can be retired.

Those subjects require separate decisions before implementation commits to
them.
