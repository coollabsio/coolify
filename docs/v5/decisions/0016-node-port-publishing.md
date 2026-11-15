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

## Amendment (2026-11-15)

27 of the 85 templates that cannot run on Nodes are blocked only by `ports:`.
13 of them publish a host port that is not the container port, for example
Gitea `22222:22`. With "host port = container port", Git over SSH would take
port 22 from the sshd that Coolify uses to manage the Node. These points
replace or add to the decision above:

- **Host port.** A published port has a host port and a container port. They
  can differ, as in Compose `HOST:CONTAINER`. The default host port is the
  container port.
- **Reserved ports.** Coolify refuses a host port that a Node of the cluster
  uses: the SSH port of each Node, 53 (DNS), 80 and 443 (ingress), the
  WireGuard port of the cluster, the Corrosion ports, and the registry port.
  Coolify reads these values from the cluster and its Nodes. Sentinel also
  refuses its own ports, so an old or wrong request cannot take them.
- **Source allow-list.** A published port can have a list of source CIDRs.
  Only these sources can connect. An empty list allows every source. The UI
  says that an empty list opens the port to everyone who can reach a Node.
- **Client address.** A connection to the Node that runs the container keeps
  the client address. A connection to another Node is masqueraded over
  WireGuard, and the application sees the address of that Node. The UI says
  this. Point DNS at the Node that runs the application when the client
  address matters.
- **Contract.** `network.firewall.reconcile` has `published_ports` (protocol,
  host port, destination workload IP, container port, source CIDRs) for
  Nodes that report `network.firewall.published_ports.v1`. Sentinel renders
  `table ip coolify_cluster_nat` in the same atomic reconcile.
- **Compose.** A `ports:` entry `HOST:CONTAINER[/PROTOCOL]` of a stack service
  is a published port of that service. An entry with a host address (for
  example `127.0.0.1:8080:80`), without a host port, or with a range is not
  published, with a note. A port that another application of the cluster
  publishes makes the stack "Not available on Nodes".
- **IPv4 only.** IPv6 Node addresses do not publish ports.
