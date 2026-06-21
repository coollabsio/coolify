# v5 Architecture Overview

Coolify v5 separates product intent from host execution.

- **Coolify Laravel** is the control plane. It owns users, teams, projects,
  environments, resources, deployments, domains, secrets, RBAC, audit-worthy
  state, and the deployment state machines.
- **Flux** is the connection broker. Laravel talks to Flux over a Unix socket.
  coold agents dial Flux over outbound gRPC streams. Flux maps host IDs to
  connected streams and maps request IDs to pending responses.
- **coold** is the per-host executor. It owns local runtime access: Podman,
  host networks, DNS, firewall mutations, service-discovery sync, host facts,
  and builder subprocess supervision.

## Data flow

```text
1. A user/API/webhook asks Coolify to do something.
2. Coolify validates permissions and stores desired state in its database.
3. Coolify's state machine turns that intent into ordered host primitives.
4. Coolify sends each primitive to Flux over /run/coolify/flux.sock.
5. Flux forwards the primitive to the target host's open coold stream.
6. coold executes the primitive locally and returns a typed result.
7. Flux resolves the pending request.
8. Coolify records the result and moves the deployment/resource state forward.
```

## Non-goals for Flux and coold

Flux and coold are not product layers. They do not decide which app to deploy,
which user is allowed to deploy, how to roll back, what domains mean, or where
business audit belongs. They only handle routing and host execution.

## Current implementation note

This directory describes the target v5 architecture. Some primitives are not
implemented in coold yet. Until implemented, docs should label them as target
primitives and code should not pretend they exist.
