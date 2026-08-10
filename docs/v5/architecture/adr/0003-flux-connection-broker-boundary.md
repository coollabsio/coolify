# ADR 0003: Keep Flux as the connection broker

## Status

Accepted.

## Context

Coolify v5 needs to send host primitive requests to many managed hosts, including
hosts behind NAT, firewalls, or corporate networks. coold can solve the host
inbound problem by dialing out, but Laravel request workers should not own
thousands of long-lived HTTP/2 agent streams or in-memory pending response maps.

The system also needs a place to translate between Coolify's short-lived local
request/response lane and coold's long-lived outbound stream without turning that
place into another product control plane.

## Decision

Flux is the central connection broker between Coolify Laravel and coold agents.
Laravel talks to Flux over a local Unix socket. coold agents dial Flux over an
outbound authenticated gRPC stream. Flux keeps the connected-host stream
registry, routes requests to the selected host stream, tracks pending request IDs,
and resolves typed responses back to Laravel.

Flux owns transport concerns: stream lifecycle, request correlation, timeouts,
disconnected-host responses, pending-request caps, late-result handling, and
host-agent authentication for inbound coold streams.

Flux must not own Coolify product concepts. It does not decide which user may
deploy, which host should run an application, what a domain means, how rollback
works, or how deployment state advances. Flux treats `containers.start` or
`images.pull` as protocol frames routed to a host, not as product actions.

## Consequences

### Positive

- Hosts can remain behind NAT because coold dials out to Flux.
- Laravel stays focused on durable product state and short-lived request work.
- Long-lived stream management can scale and fail independently from PHP-FPM
  workers.
- Backpressure, timeouts, and disconnected-host behavior have one transport
  boundary.
- Flux can be tested as a protocol router without needing Coolify's app model.

### Negative

- The architecture has one more runtime component to deploy and monitor.
- Protocol changes must stay coordinated across Coolify, Flux, and coold.
- Flux outage blocks dispatch to connected hosts even when Laravel and hosts are
  otherwise healthy.
- Flux must stay intentionally narrow; adding product decisions would create a
  second control plane.

## Boundary rules

- Coolify chooses the target host; Flux only routes to that host's connected
  stream.
- coold authenticates to Flux as a host agent; Laravel reaches Flux through the
  local Unix-socket lane.
- Flux may answer transport failures such as disconnected host, timeout, or
  pending-cap overflow.
- Flux may not inspect product ownership, RBAC, billing, deployment state,
  domains, secrets, or audit meaning.
