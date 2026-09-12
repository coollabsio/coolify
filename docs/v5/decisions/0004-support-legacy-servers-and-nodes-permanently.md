# Decision 0004: Support legacy servers and nodes permanently

**Status: Accepted**

**Date: 2026-09-12**

## Context

Coolify must continue to manage existing Docker servers through SSH. The new
architecture uses Podman, host-native Sentinel, and Flux. Adding both execution
models to the existing `servers` model would spread runtime checks through the
code and make each model difficult to change safely.

Legacy support is a product requirement, not a temporary migration state.
Users must be able to add and operate both server types in the same Coolify
installation.

## Decision

Coolify will support two permanent management models:

- `Server` and the `servers` table represent legacy Docker hosts managed
  primarily through SSH. They keep the existing container Sentinel path.
- `Node` and the `nodes` table represent Podman hosts managed primarily through
  host-native Sentinel and Flux. SSH remains available for bootstrap and
  recovery.

The models can belong to the same team, project, and environment. The UI must
show both resource types without implying that a legacy server must become a
node.

New node-specific state will use stable domain names such as `nodes` and
`node_containers`. It will not use release-number prefixes. Existing shared
models, including teams, projects, environments, authentication, audit data,
notifications, and Flux PKI, remain shared.

There will be no automatic conversion between the two models. A future
conversion tool can create a node only after explicit operator action and
compatibility checks. A legacy server remains fully supported when the
operator does not convert it.

The current unreleased `servers.mode` implementation is transitional. Before
the node model is released, Coolify will remove that mode column and move the
QEMU node, Flux assignment, node metadata, and node-only UI to the `Node`
model.

## Consequences

- Legacy Docker support does not have a planned removal date.
- New node work does not add node-only columns or relationships to `servers`.
- Commands must accept either a `Server` or a `Node` only when the operation is
  intentionally shared. Node-only Flux commands must not accept `Server`.
- Tests must cover legacy-only, node-only, and mixed installations.
- The first node data slice will add `nodes`. The container inventory slice
  will add `node_containers` when `container.list` is implemented.
- Workload, deployment, credential, event, and health-history tables will be
  added only with the vertical slice that needs them.

## Not decided here

This decision does not define:

- the final node installer;
- an optional explicit server-to-node conversion procedure;
- cluster scheduling and placement;
- workload and deployment schemas; or
- long-term container health-history storage.
