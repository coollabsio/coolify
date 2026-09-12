# Decision 0002: Separate legacy upgrades from node installations

**Status: Accepted**

**Date: 2026-09-11**

## Context

Existing Coolify installations run the control plane and, in many cases, user
workloads on the localhost server with Docker Compose. An in-place change from
Docker to Podman would replace the runtime that performs the migration. Adding
WireGuard and new firewall rules at the same time could also disconnect the
control plane from its own host.

Fresh v5 installations do not have this constraint. The installer can prepare
Podman, WireGuard, Sentinel, Corrosion, and the required network policy before
it starts Coolify or user workloads.

Self-hosted Coolify must continue to support a single-server installation. It
must also support a dedicated control plane with separate worker servers.

## Decision

Coolify will not automatically convert the localhost server of an existing
Docker installation to Podman, WireGuard, or the worker-node stack.

When an existing installation upgrades to v5, Coolify will:

- classify localhost as `legacy-control-plane`;
- keep its current Docker control-plane services and existing Docker workloads;
- prevent the v5 scheduler from placing new v5 workloads on localhost;
- not apply the v5 WireGuard or firewall configuration to localhost; and
- allow remote servers to join as `node-worker` nodes.

A fresh v5 installer will support these modes from its first release:

- `node-controller-worker`: the default self-hosted mode. The same server runs the Coolify
  control plane and v5 workloads with Podman;
- `node-controller`: the server runs only the Coolify control plane; and
- `node-worker`: a remote server runs workloads but not the Coolify control
  plane.

The fresh installer owns the initial installation of Podman, WireGuard,
host-native Sentinel, Corrosion, control-plane services, systemd units, storage
paths, and firewall rules. It must finish the host preparation before it makes
the installation available.

In `node-controller-worker` mode, system resources and user workloads must remain separate.
Coolify system containers, networks, names, labels, and storage paths are
reserved. Normal workload commands must not remove, replace, or reconfigure
them. Firewall reconciliation must preserve SSH, the Coolify dashboard,
WireGuard, required outbound access, and the active Sentinel control channel.

Node mode must be stored explicitly. Server ID `0` continues to identify the
Coolify instance, but it does not identify whether the installation is legacy,
combined, or control-plane-only.

An existing installation can become Podman-native only through an explicit
future migration procedure or a fresh v5 installation. This decision does not
promise an in-place conversion. Decision 0004 makes legacy servers and nodes
permanent parallel models; legacy support does not end when node support ships.

## Consequences

- Existing installations have a safe upgrade path without replacing their live
  container runtime or network policy.
- Existing applications on localhost continue to run, but they remain on the
  legacy Docker path.
- New v5 workloads require a worker node when Coolify was upgraded from an
  existing Docker installation.
- Fresh installations keep the simple single-server experience through the
  default `node-controller-worker` mode.
- Operators can isolate the control plane by selecting `node-controller` and
  adding one or more `node-worker` servers.
- Scheduling, installation, health checks, and UI behavior must use the explicit
  node mode instead of assuming that localhost always has the same capabilities.

## Not decided here

This decision does not define:

- the detailed fresh-install command or user interface;
- the exact Podman network and storage layout;
- the WireGuard address allocation and key-rotation protocol;
- the firewall implementation and rollback mechanism;
- the procedure for a future explicit Docker-to-Podman migration.
