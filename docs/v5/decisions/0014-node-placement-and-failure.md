# Decision 0014: Keep explicit placement and recover failed Nodes manually

**Status: Accepted**

**Date: 2026-11-11**

## Context

Today the user selects the Nodes of each cluster application. Decision 0007 has
no automatic failover. Automatic failover of a stateful workload needs fencing:
if a Node is only partly down, two containers can write the same data.

## Decision

- In v5.0, the user selects the Nodes of each application. Coolify does not
  place workloads automatically.
- When a Node goes offline, Coolify does not fail over automatically. It shows
  the state of each affected application and sends a notification.
- The user recovers with **Move to another Node**. A stateless application moves
  at once. An application with volumes restores each volume from its latest
  backup (decision 0009) on the new Node.

## Consequences

- An outage of a Node needs a person to act.
- No split brain is possible.

## Amendment (2026-11-16): placement when the user does not select a Node

When the user does not select a Node on the create page, Coolify places the new
application on the allowed Node with the fewest workloads
(`placementNode()`: role, network, CPU architecture, capacity, and the access
grants of the user). A new stack can use **Spread across the cluster**: each
group of services that share a volume goes to one Node, and the other
services go to the least loaded Nodes (decision 0023, step 3). This happens
only when a resource is created. Coolify still does not move a running
workload by itself, and there is no automatic failover (P7).

## Not decided here

- Automatic placement ("any Node").
- Automatic failover and fencing.
