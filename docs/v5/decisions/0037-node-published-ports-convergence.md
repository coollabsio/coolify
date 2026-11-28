# Decision 0037: Published ports in the Sentinel convergence

**Status: Accepted**

**Date: 2026-11-28**

## Context

Decision 0026 moved the workload convergence into Sentinel. Coolify sends the
desired set of each Node, and Sentinel deploys, restarts, and removes the
containers itself. One group of workloads stays on the old command path: the
workloads with a published port (decision 0016). Because of them,
`ReconcileNodeWorkloads` and `RecoverStaleOperations` stay in Coolify (0026,
step 5).

How a published port works today:

- Each Node of the cluster has a DNAT rule for the port in the `coolify_nat`
  table (`render_published_ports`). The rule matches each local address of the
  Node (`fib daddr type local`) and translates to one container address.
- Coolify selects the address for each Node
  (`ReconcileNodeClusterNetwork::publishedPorts()`): the running container on
  the same Node first, else the running container on the Node with the lowest
  id. A connection to a container on another Node goes over WireGuard and is
  masqueraded, so the client address is lost.
- Each placement has 2 fixed slot addresses (`container_ip` and
  `next_container_ip`). A rolling deploy starts the new container in the other
  slot. A restart keeps the slot and its address.
- Coolify sends the rules in `network.firewall.reconcile`, as a network
  revision of the cluster.

A rolling deploy of a workload with a published port on Node B:

1. Sentinel on Node B starts the new container in the other slot and waits
   until it is healthy.
2. Coolify makes a network revision that points the port to the new slot.
3. Each Node of the cluster applies the revision, and Coolify waits for all of
   them.
4. Sentinel on Node B removes the old container.

Steps 2 and 3 need Coolify, Horizon, and Flux in the middle of each deploy, and
a change on every Node of the cluster. Sentinel cannot do this alone, so these
workloads cannot converge in Sentinel.

## Decision

Split the forward of a published port into 2 levels:

```
Client -> Node A :port -DNAT-> Node B mesh address :port -DNAT-> container slot
Client -> Node B :port ---------------------------------DNAT-> container slot
```

- **Remote rule.** A Node without a running container of the workload
  translates the port to the WireGuard mesh address of a Node that runs it,
  with the same host port. Coolify selects the Node: the Node with the lowest
  id that has a running container, as today. Coolify sends these rules in the
  network revision. They change only when the set of Nodes with a running
  container changes: the first deploy, a move, a removal, a stop, or a failed
  container that Sentinel reports.
- **Local rule.** A Node with a running container of the workload translates
  the port to the active slot of the local container. Sentinel owns this rule.
  It is part of the desired set entry of the workload (the port list), not of
  the network revision.
- **Rolling deploy in Sentinel.** Sentinel starts the new slot, waits until it
  is healthy, points the local rule to the new slot in one atomic nftables
  change, and then removes the old container. No other Node changes, because
  the other Nodes forward to the mesh address of this Node.
- **Second hop.** The existing DNAT already matches the mesh address of the
  Node, because it is a local address. A connection from another Node comes
  from the mesh address of that Node (masquerade). The entry Node checks the
  source CIDRs of the port. The Node that runs the container accepts the
  translated connection from the mesh addresses of the cluster without a second
  CIDR check.
- **Capability.** Sentinel reports `network.published_ports.local.v1`. A Node
  without it keeps the rules of decision 0016 and the command path. A
  workload with published ports goes to the Sentinel convergence only when
  every usable Node of its cluster reports the capability.
- **Clean-up.** When the pinned Sentinel has the capability and no cluster
  needs the command path, remove the command path for these workloads. Then
  `ReconcileNodeWorkloads` and `RecoverStaleOperations` can go (0026, step 5).

### Before and after

| Topic | Before (0016) | After |
| --- | --- | --- |
| Rule on a Node without the container | DNAT to the container slot | DNAT to the mesh address of the Node that runs it |
| Rule on the Node with the container | DNAT to the container slot, set by Coolify | DNAT to the active slot, set by Sentinel |
| Rolling deploy | Network revision on every Node, sent by Coolify, in the middle of the deploy | One local nftables change on one Node, by Sentinel |
| Coolify or Flux down during a deploy | The deploy cannot switch the port | The deploy finishes |
| Move, first deploy, removal | Network revision | Network revision (no change) |
| Client address on the Node with the container | Kept | Kept |
| Client address on another Node | Lost (masquerade) | Lost (masquerade) |
| Hops for a connection to another Node | WireGuard to the container | WireGuard to the Node, then a local DNAT |

## Options compared

| Option | Good | Bad |
| --- | --- | --- |
| **A. Remote rules to the Node, local rule in Sentinel (proposed)** | A deploy needs no other Node. Uses the existing DNAT that matches every local address. Removes the command path. | One more DNAT on the Node with the container. The remote rules still change when a Node stops running the workload. |
| **B. A port switch phase in the desired set** | One hop, as today. | Coolify must confirm the switch in each deploy, so the deploy still waits for Coolify, Flux, and every Node. Little gain over the command path. |
| **C. Keep the command path for these workloads** | No work. | Two convergence paths stay, also in a Rust Coolify. |

## Consequences

- Each workload of a Node converges in Sentinel, also with published ports.
- A connection that enters on a Node without the container gets one more
  DNAT and conntrack entry on the Node with the container.
- The remote rules of a cluster change less often: not on a deploy, only when
  the Nodes that run the workload change.

## Answers to the open questions (2026-11-28)

1. **The selected Node stops running the workload.** Coolify reacts to the
   status event of Sentinel (decision 0026). When a Node reports that its last
   running container of a workload with a published port stopped or failed,
   Coolify queues a network revision of the cluster. The other Nodes then
   forward to the next Node with a running container. Sentinel does not forward
   to another Node itself: it knows only the desired set of its own Node, and
   2 Nodes could forward to each other in a loop. Today a crash queues no
   revision, so this also closes that gap.
2. **A Node with only a stopped container.** Coolify decides for each Node if
   the rule is local or remote, from the desired state. A Node whose container
   has the desired state `running` gets a local rule. A Node whose container
   has the desired state `stopped` gets a remote rule to a Node with a running
   container. Sentinel decides only the slot of a local rule. A crash with a
   restart is a short gap, as today. This keeps the rule of decision 0016:
   only running containers count while one runs.
3. **Prefer the local container.** Yes. A Node with a running container of the
   workload translates to it, with no extra hop and with the client address.
   Only a Node without one gets a remote rule.

## Not decided here

- Keeping the client address for a connection to another Node (for example
  with policy routing instead of masquerade).
- Load balancing of a port over more than one Node.
- A host port that differs from the container port (decision 0016).
