# ADR 0001: Split v5 into Coolify control plane, Flux broker, and coold host agent

## Status

Accepted.

## Context

Coolify v5 needs to manage many user resources across many user-owned hosts,
including hosts behind NAT or restrictive firewalls. The Laravel application
must own product behavior and durable state, but it should not hold thousands of
long-lived agent streams or directly expose host runtime sockets.

Host operations also need a narrow privileged boundary. Podman, firewall, DNS,
and Corrosion require local host privileges that should not be spread across the
Laravel app or arbitrary scripts. Future builder supervision belongs behind the
same boundary, but its active primitive/API shape is deferred to a separate
decision.

## Decision

Coolify v5 uses three distinct building blocks:

1. **Coolify Laravel control plane** owns user intent, durable product state,
   RBAC, deployment state machines, placement decisions, secrets, proxy config
   rendering, notifications, and audit.
2. **Flux** owns long-lived agent connectivity. Laravel talks to Flux over a
   Unix socket. coold agents dial Flux over outbound gRPC. Flux routes typed
   primitive requests to connected hosts and resolves pending responses.
3. **coold** runs once per host. It exposes a closed set of host primitives and
   owns privileged local execution through Podman, firewall, DNS, Corrosion,
   and host facts.

coold must not expose raw Podman passthrough. Every supported operation must be
an explicit primitive with validation and a stable protocol shape.

## Consequences

### Positive

- Coolify keeps product complexity in one durable control plane.
- Hosts can live behind NAT because coold dials out to Flux.
- Privileged host access is isolated to coold.
- Flux can scale connection handling separately from Laravel request workers.
- The primitive surface can be tested independently from Coolify's app model.

### Negative

- More moving parts than a direct SSH/Docker model.
- Protocol changes require coordination across Coolify, Flux, and coold.
- Some deploy features require new primitives before the v5 path is complete.

## Boundary rules

- Coolify may say: "deploy this nginx resource for this team to host H1." coold
  may not.
- Coolify may send: `images.pull`, `containers.create`, `containers.start`,
  `services.register`, `firewall.allow`. coold may execute those.
- Flux may route requests but may not make product decisions.
- coold may reject dangerous host operations even when Coolify requested them.

## First reference flow

The first validation flow is deploying a Docker image app using `nginx:alpine`.
That flow requires no builder and exercises the minimum runtime path:

```text
Coolify state machine
  → Flux dispatch
  → coold image pull
  → coold container create/start
  → coold status
  → coold service registration
  → coold firewall
  → Coolify proxy config/reload
  → Coolify marks deployment running
```
