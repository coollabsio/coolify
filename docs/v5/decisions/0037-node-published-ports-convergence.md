# Decision 0037: Published ports in the Sentinel convergence

**Status: Accepted, built**

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

## Implementation notes (2026-11-28)

Built in Coolify and in Sentinel `ce6e4a8`, `13b243d`, and `78b11ae`
(`network.published_ports.local.v1`).

### Changes to the decision

- **The local rules have their own table.** Sentinel writes the table
  `ip coolify_cluster_ports` with one nftables transaction, at the priority
  `dstnat - 1`, so a local rule comes before a rule of the network revision.
  The rules mark the connection (`ct mark 0x01000000`), and the firewall
  accepts marked connections in its forward and output chains. Because of
  this, a Node needs a firewall of the new Sentinel before its local rules
  work. A change of the capability on a Node queues a network revision of the
  cluster and a sync of the desired sets.
- **A remote rule has the old shape.** It is a published port of
  `network.firewall.reconcile` with the mesh address of the other Node as the
  destination and the host port as the container port. Sentinel accepts this
  destination only when it is in the cluster CIDR and is not the own address.
  The rule does not match connections from the WireGuard interface, so 2
  Nodes cannot forward a port to each other.
- **The second hop.** When a port has source CIDRs, the local table has a
  second rule for connections from the cluster CIDR on the WireGuard
  interface, without the source CIDRs.
- **The trigger is the container inventory.** Answer 1 says that a status
  event queues the revision. A status event (`healthy`, `failed`, `stopped`,
  `removed`) refreshes the inventory, and `FetchContainers` compares the
  workloads with a published port that have a running container on the Node
  before and after the refresh. A change in either direction queues a network
  revision. A container that starts again also queues one, so the Nodes go
  back to the Node with the lowest id. A crash with a fast restart changes
  nothing, as answer 2 says. While no container runs, the other Nodes forward
  to a Node with a local rule, where Sentinel starts the container again, and
  not to a stopped container.
- **No status event is lost.** `RefreshNodeContainersJob` releases its unique
  lock when it starts (`ShouldBeUniqueUntilProcessing`), so an event during a
  run queues one more refresh. The refresh of a status event has its own unique
  key, so the refresh of each minute, which waits up to 59 s in the queue, does
  not hide it. Runs for the same Node never overlap (`WithoutOverlapping`, a
  waiting run is released again). Before, an event during a run or during the
  wait of the minute refresh was dropped, and the forward changed up to one
  minute later.
- **Sentinel reports a container that waits for its backoff.** Before, Sentinel
  sent no status while a crashed container waited for the next start (10 s,
  then up to 10 minutes). It now sends `stopped` (autonomous) at the start of
  the wait. This is in the deployment history.
- **The first rolling deploy that uses the other slot makes one revision.**
  The slot gets its address and its firewall allows
  (`DispatchWorkloadDeployment`). The next rolling deploys make none.

### Sentinel

- `WorkloadSetEntry` has `published_ports` (protocol, host port, container
  port, source CIDRs) and `replaces_container_ip`. Sentinel validates that the
  container addresses are in the workload network of the Node and that each
  protocol and host port is unique.
- Each convergence pass computes the local rules from the stored set and the
  containers. A rule points to `deploy.container_ip`, or to
  `replaces_container_ip` while the new container is not healthy and the old
  one runs. When the new container is healthy, Sentinel changes the table
  first and then removes the old container. If the change fails, the old
  container stays. The executor applies the table only when it changed or is
  missing, so a restart or a reboot of the Node applies it again without
  Flux.
- Flux maps the new fields. It refuses a put with ports (409) for a Node
  without the capability.

### Coolify

- `NodeCluster::convergesPublishedPorts()`: every usable Node reports
  `network.published_ports.local.v1` and `workload.reconcile.v1`. Only then
  `SyncNodeWorkloadSet::converges()` is true for a workload with published
  ports. Else the cluster keeps the rules of decision 0016 and the command
  path.
- `SyncNodeWorkloadSet::localPublishedPorts()` gives the ports of the entries
  with the desired state `running` on a Node. They are in the entry and in its
  hash. An entry without ports keeps its old hash.
- `ReconcileNodeClusterNetwork::publishedPorts()` sends no rule to a Node with
  a local rule. Another Node gets a rule to the mesh address of the selected
  Node when that Node has a local rule, else the rule of decision 0016.
- A port change and a change of the capability sync the desired sets of the
  Nodes of the cluster. An adopted workload with published ports queues a
  network revision.
- A container on an unreachable Node does not count as running, because
  Coolify cannot refresh the inventory of an offline Node. A change of
  `is_reachable` of a Node that runs a workload with a published port queues
  a network revision (`Node::booted()`).
- `IssueFluxCredential` grants `network.published_ports.local.v1`.
- `ReconcileNodeWorkloads` and `RecoverStaleOperations` stay. Nodes without
  the capability (`upstream.json`, cluster 2 of dev on 1.1.5) still need the
  command path. Remove them when a released Sentinel has the capability.

### Checked on dev (cluster 1, Sentinel `main-78b11ae`)

- **Old path.** With Sentinel `main-db2612c` on both Nodes, a `traefik/whoami`
  workload with port 18080 on Node 1 deployed through the command path, and
  both Nodes had the rules of decision 0016.
- **Upgrade.** After the install of the new Sentinel, the workload was adopted
  into the desired set. Node 1 then had only its local rule, and Node 2 a
  remote rule to the mesh address of Node 1. Through Node 1 the client address
  stayed. Through Node 2 the connection came from the mesh address of Node 2.
- **Rolling deploys.** A client looped connections through each Node (about 1
  250 each). The first rolling deploy had 0 failures and 1 network revision,
  because the other slot got its address. The second one had 0 failures in
  1 016 connections each and no network revision.
- **Stop.** With the workload on both Nodes, a stop on Node 2 gave 3 failed
  connections through Node 2, then Node 2 forwarded to Node 1. A start on
  Node 2 gave a local rule again, with 0 failures.
- **Crash.** A kill with a fast restart gave a gap of about 10 s and no
  revision. In a crash loop, Sentinel reported `stopped` during the backoff,
  and Coolify queued a revision. Node 2 kept forwarding to Node 1, because no
  other Node ran the workload.
- **Failover with 3 Nodes (Node 3 `node-onboarding-c`, no container).** In a
  crash loop on Node 1, Node 3 forwarded to Node 2 2 s after the `stopped`
  event, and back to Node 1 2 s after `healthy`. Sentinel sees a crash only
  at its next pass (up to 15 s), so the `stopped` event came 14 s after the
  kill. A stop of Node 1 through Coolify moved Node 1 and Node 3 to Node 2
  with 5 and 6 failed connections, and the start moved them back with 0. A
  power-off of Node 1 moved Node 3 to Node 2 after 21 s (the heartbeat
  timeout), and the start of Node 1 moved it back with 0 failures. Before the
  fix for unreachable Nodes, Node 3 kept forwarding to the powered-off Node.
- **A network run waited 11 minutes.** A `builder.reconcile.v1` reached Node 2
  in the same second as a refresh of its Flux credential. Flux kept the
  command until its timeout (660 s), and the network lock of the cluster
  blocked each revision, also a failover. Flux `b24863e` now fails a command
  with 504 when the connection that got it closes. On dev, a command to a
  killed Sentinel failed in the same second, and the next run converged
  8 s later.
- **Source CIDRs.** With `10.221.5.1/32`, the host connected through both
  Nodes, and a connection from another address was dropped on the entry Node.
- **Reboot without Flux.** With Flux stopped, Node 1 rebooted. Its local rule
  and the remote rule of Node 2 came back, and the port answered through both
  Nodes.
- **Removal.** The delete of the workload removed the local table and the
  rules on both Nodes.
