# v5 Host Primitives

Coolify deploy logic must send explicit primitives to coold through Flux. There
must be no raw Podman passthrough.

This file is the target primitive catalog. Implementations may lag behind this
catalog; code should only call primitives that exist in the current protocol.

## Images

| Primitive | Purpose |
| --- | --- |
| `images.pull` | Pull an OCI image ref onto a host. |
| `images.list` | List local images. |
| `images.delete` | Remove a local image when safe. |

## Containers

| Primitive | Purpose |
| --- | --- |
| `containers.create` | Create a container from an explicit spec. |
| `containers.start` | Start an existing container. |
| `containers.stop` | Stop a container with an optional timeout. |
| `containers.restart` | Restart a container. |
| `containers.delete` | Delete a stopped or forced container. |
| `containers.inspect` | Return detailed runtime state. |
| `containers.list` | Return host container summaries. |
| `containers.logs` | Stream or read container logs. |
| `containers.exec` | Run a command inside a container. |
| `containers.healthcheck.run` | Trigger or read a runtime health check. |

`containers.create` must enforce a deny filter for dangerous host options such
as privileged mode, unsafe host mounts, host networking, and disallowed
capabilities unless the host is explicitly configured to allow them.

## Volumes

| Primitive | Purpose |
| --- | --- |
| `volumes.create` | Create an idempotent named volume. |
| `volumes.inspect` | Inspect a host volume. |
| `volumes.delete` | Delete an unused volume. |

## Networks

| Primitive | Purpose |
| --- | --- |
| `networks.create` | Create an idempotent Podman network. |
| `networks.list` | List host networks. |
| `networks.delete` | Delete an unused network. |

Bootstrap-created mesh namespace networks are managed by the v5 cluster init
flow. Per-resource or compose networks are runtime primitives.

## Firewall

| Primitive | Purpose |
| --- | --- |
| `firewall.allow` | Add an allow tuple and persist it. |
| `firewall.revoke` | Remove an allow tuple by ID. |
| `firewall.list` | List active/persisted allow rules. |
| `firewall.reconcile` | Flush and restore firewall state from snapshots. |

coold is the sole writer for both firewall planes: iptables for cross-host
traffic and nft bridge rules for same-bridge traffic.

## Service discovery and DNS

| Primitive | Purpose |
| --- | --- |
| `services.register` | Register this host's container endpoint in Corrosion. |
| `services.unregister` | Remove an endpoint from Corrosion. |
| `services.endpoints` | Read known endpoints for diagnostics. |
| `dns.lookup` | Diagnose internal DNS resolution. |
| `dns.stats` | Return DNS server status. |

## Host facts

| Primitive | Purpose |
| --- | --- |
| `host.info` | Return host/runtime facts for scheduling and debugging. |
| `host.stats` | Return CPU, memory, disk, and container stats snapshot. |
| `host.containers` | Return host container summaries. |

## Builder (deferred)

Builder is intentionally not part of the active v5 Flux/coold primitive
surface. Before reintroducing it, add an ADR/API covering scheduling, capacity,
logs, artifacts, cancellation, restart adoption, and registry flow.

## Not primitives

These belong in Coolify, not coold:

- `deploy.application`
- `rollback.deployment`
- `create.preview`
- `configure.domain`
- `authorize.user`
- `send.notification`
- `render.proxy.config`
