# Decision 0016: Publish TCP and UDP ports on every Node of a cluster

**Status: Accepted**

**Date: 2026-11-11**

## Context

Decision 0005 covers HTTP and HTTPS only. Databases and other non-HTTP services
need TCP or UDP access. The removed "Direct port access" feature did not publish
a host port. The plan `docs/superpowers/plans/2026-10-05-cluster-port-publishing.md`
describes a NAT design.

## Decision

- A cluster application can publish a TCP or UDP port. The host port is the
  container port.
- The port is published on every Node of the cluster. A connection to any Node
  IP reaches the application, also when the container runs on another Node.
- Sentinel adds an `ip` NAT table in the same atomic nftables reconciliation as
  the `coolify_cluster` table: DNAT to the container, and masquerade over
  WireGuard when the container is on another Node.
- Coolify sends the published ports in the firewall reconcile command. A change
  needs no redeploy.
- One port and protocol maps to one application in the cluster.

## Consequences

- No custom Caddy build.
- A published port is open on every Node of the cluster.

## Not decided here

- A host port that differs from the container port.
- Publishing on a subset of Nodes.
