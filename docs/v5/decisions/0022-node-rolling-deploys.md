# Decision 0022: Deploy without downtime on one Node

**Status: Accepted**

**Date: 2026-11-13**

## Context

v4 has rolling updates: the new container starts next to the old one, the
proxy switches to it when it is healthy, and then the old container stops. An
application with a port mapped to the host, or with a consistent container
name, does not get them.

On a Node, `workload.deploy.v1` runs `podman run --replace --name
coolify-<uuid>-main`. Podman removes the old container before the new one
starts, so each redeploy has downtime. A redeploy with a broken health check
leaves the application down.

Ingress and internal DNS already route to every endpoint that Sentinel
publishes in Corrosion, one row per container IP, and skip endpoints that are
`starting` or `unhealthy`. `workload.lifecycle.v1` with `stop` or `remove`
already drains an endpoint before the container stops (`removing` state).

## Decision

- An application deploys make-before-break on the same Node when all of these
  are true:
  - The Node reports `workload.rolling_deploy.v1`.
  - The Node is in a cluster, so the container has a workload IP.
  - The application has no writable volume.
  - A container of the application runs on the Node.
- Otherwise the deploy stops the old container and then starts the new one,
  as before.
- Each placement has two container slots: `main` (`coolify-<uuid>-main`, the
  pivot column `container_ip`) and `next` (`coolify-<uuid>-next`, the pivot
  column `next_container_ip`). A rolling deploy starts the new container in the
  slot that does not run. The next one switches back.
- Both addresses of a placement are always in the firewall rules. The first
  rolling deploy of a placement allocates the second address and waits until
  every Node applied the network revision before it starts the new container.
- The deploy waits for the new container like today: until its health check
  passes, or for the settle time when it has no health check.
- Then Coolify removes the old container with `workload.lifecycle.v1`
  `remove`. Sentinel drains its endpoint first. Ingress and DNS then send new
  requests to the new container only. The old container stops after the drain.
- When the new container does not get healthy, Coolify removes it. The old
  container keeps serving. The operation fails with the reason and says that
  the previous version keeps running. The reconciler does not retry that
  revision on its own while an older container runs.
- The deploy stores the chosen slot in the operation request, so a retry of
  the same operation uses the same container name.
- Coolify finds the container of an application on a Node from the inventory
  (the running container of the two slot names, else the newest), not from a
  fixed name. Logs, lifecycle, backups with `stop_during_backup`, exec, and
  the end-to-end command use this. A `remove` removes both slots.
- `workload.rolling_deploy.v1` is a capability, not a command. With it,
  Sentinel drains an endpoint before a stop or remove whenever another endpoint
  of the workload serves, also for a workload without a domain. Internal DNS
  answers with a TTL of 5 seconds, so a cached answer expires within the drain.

## Alternatives

- Make-before-break inside Sentinel, as one command. Sentinel would wait for
  the health check itself, but a command would block for minutes, and the
  health wait, the failure text, and the retry rules are in Coolify today.
- A revision suffix in the container name instead of two slots. It needs a new
  address for each deploy, so each deploy needs a network revision.
- Rolling deploys for applications with a writable volume. Two containers
  would write the same data, which decision 0007 rules out.

## Consequences

- A redeploy without a writable volume has no failed requests through
  ingress, and DNS clients that respect the TTL get the new address.
- A redeploy with a broken health check leaves the old version running.
- During a rolling deploy, the application uses twice its memory and CPU on
  the Node.
- Each placement uses two addresses of the workload subnet of its Node.
- A client that keeps a connection open, or caches DNS longer than the TTL,
  can still reach the old container until it stops.

## Not decided here

- Rolling deploys across Nodes, or more than one replica.
- A drain time that the user can set, like v4.
- Published ports (decision 0016) target the container IP. When they are
  built, they must follow the running slot.
