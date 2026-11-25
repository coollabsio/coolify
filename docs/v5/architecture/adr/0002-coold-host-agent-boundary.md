# ADR 0002: Keep coold as a narrow host agent

## Status

Accepted.

## Context

Coolify v5 needs a process on each managed host that can reach local runtime
surfaces such as Podman, Corrosion, DNS bind addresses, and firewall state. That
process must run close to privileged host APIs, but Coolify's product model,
RBAC, deployment history, billing, and audit state belong in the Laravel control
plane.

If coold grows app-aware behavior, it becomes a second control plane with local
copies of product rules. If Coolify reaches around coold with raw host access,
the privileged boundary disappears and host behavior becomes harder to validate.

## Decision

coold is a per-host agent with a narrow, explicit primitive surface. It executes
host-local operations requested through Flux, reports typed results, and owns
host safety checks for those operations.

coold owns local runtime integration: Podman access, service-discovery sync,
embedded DNS, Corrosion writes for this host's endpoints, host facts, and the
firewall mutation/reconciliation surface when those primitives are active.

coold must not own Coolify product concepts. It does not decide what an
application, project, team, deployment, domain, rollback, billing event, or audit
record means. Those concepts remain in Coolify Laravel. coold also must not
expose raw Podman passthrough; every operation needs an explicit primitive with
validation and a stable protocol shape.

Builder supervision is intentionally deferred. When it returns, it should be
recorded in a separate ADR/API because scheduling, capacity, logs, artifacts,
cancellation, restart adoption, and registry flow need their own trade-off.

## Consequences

### Positive

- coold can be reasoned about as reusable host infrastructure, not a Coolify app
  runtime.
- Privileged host access has one narrow boundary with explicit validation.
- The primitive API can evolve through reviewed protocol additions instead of
  ad hoc Podman command exposure.
- Coolify Laravel remains the only owner of durable product state and business
  audit.

### Negative

- New deploy features may require new coold primitives before they can ship.
- Some operations are more verbose than raw Podman passthrough.
- Cross-repo changes must keep Coolify, Flux, and coold protocol expectations in
  sync.

## Boundary rules

- If the question is "is this user/team allowed to do this?", Coolify answers.
- If the question is "is this host operation safe to execute here?", coold may
  reject it.
- If the operation only makes sense because of Coolify's product model, it stays
  out of coold.
- If another orchestrator could safely reuse the same host operation, it is a
  candidate coold primitive.
