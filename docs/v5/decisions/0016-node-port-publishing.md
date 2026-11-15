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

## Implementation (2026-11-15)

Built: `node_published_ports` (cluster, workload, protocol, host port, container
port, source CIDRs; unique on cluster, protocol, and host port),
`PublishNodeWorkloadPort`, `RemoveNodePublishedPort`, the **Published Ports**
page of a cluster application and a cluster database, and `ports:` of stack
services. These points differ from the decision above or add to it:

- **Reserved ports.** `NodeCluster::reservedHostPorts()` lists them. Each value
  comes from the data or from one constant: the SSH port (`nodes.port`) of each
  Node of the cluster, 53, 80, 443, `node_clusters.wireguard_port`, the
  Corrosion gossip port 8787 and API port 8080
  (`ReconcileNodeClusterNetwork::CORROSION_*`, the values that Sentinel writes
  to the Corrosion configuration), and the registry port 5443
  (`NodeRegistry::PORT`). A reserved port is refused for TCP and for UDP.
- **Source CIDRs.** An address without a prefix becomes `/32`. A CIDR with host
  bits becomes its network (`10.0.0.5/24` is `10.0.0.0/24`). At most 64. IPv4
  only.
- **Payload.** `network.firewall.reconcile` has `published_ports` only for
  Nodes that report `network.firewall.published_ports.v1`. Each entry has
  `protocol`, `host_port`, `destination_ip`, `container_port`, and
  `source_cidrs` (empty is every source). Coolify does not add the ports to
  `ingress_rules`: Sentinel must allow the translated traffic in the forward
  chain, with the source CIDRs.
- **Destination.** One container for each port and Node. Coolify uses only
  running containers when one runs. The container on the receiving Node comes
  first, else the container on the Node with the lowest id. The address is the
  address of the running slot of a rolling deploy. A workload without a
  container address is left out.
- **When the destination changes.** A change of the published ports queues a
  network revision. A new placement (first deploy or move) queues one, as for
  ingress. A rolling deploy applies a revision before it removes the old
  container, so the ports go to the new container first. The removal of the
  source placement after a move queues one. The delete of a workload queues one
  for the clusters of its ports. The rows go with the workload (cascade).
- **Compose.** `ports:` makes a stack "Not available on Nodes" until every
  usable Node of the cluster reports the capability
  (`NodeCluster::supportsPublishedPorts()`, also in the template cache key).
  With it and `discovery.names.underscore.v1`, 47 templates are unavailable,
  not 85. 38 templates are added, not 27. Short
  syntax `HOST:CONTAINER[/PROTOCOL]` and the long syntax (`target`,
  `published`, `protocol`, `host_ip`) are read after interpolation with the
  stack variables. A host address other than `0.0.0.0`, an entry without a
  host port, a range, or a protocol other than TCP and UDP is not published,
  with a note on the service. A port that a Node uses, that another
  application publishes, or that two services publish stops the creation of the
  stack and the Compose sync, with the reason.
- **Compose sync.** The published ports of a service are one value of the
  three-way rule: when the user changed one port, the service keeps its whole
  list. The ports of a removed service are freed in the sync transaction.
- **Not done.** IPv6 Node addresses, a port on a subset of Nodes, ranges, and
  an edit of a published port (remove it and publish it again).
