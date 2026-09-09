# v5 Responsibility Split

Use this as the first check when deciding where a new feature belongs.

## Rule of thumb

| Question | Owner |
| --- | --- |
| What should happen for this user, team, app, database, or deployment? | Coolify |
| Which connected host should receive this request? | Coolify chooses, Flux routes |
| How do we get a command to a NATed host? | Flux |
| Do this concrete operation on this host. | coold |
| Is this allowed for this Coolify user/team? | Coolify |
| Is this host operation dangerous even if Coolify asked for it? | coold deny filter |

## Coolify owns

- Users, teams, roles, RBAC, API tokens, sessions, SSO/OAuth.
- Projects, environments, applications, services, databases, servers.
- Resource configuration: source, image, build settings, env vars, domains,
  ports, health checks, resource limits, volumes, schedules, webhooks.
- Deployment state machines: pending, building, pulling, creating, starting,
  health waiting, cutover, running, failed, rollback, cleanup.
- Placement and scheduling decisions.
- Proxy/ingress configuration rendering and TLS intent.
- Secret storage, encryption, resolution, and injection at deploy time.
- Business audit, event history, deployment logs, and user-facing status.
- Notifications, billing/subscriptions, cloud-provider integration.

## Flux owns

- Long-lived coold stream registry keyed by host ID.
- Pending request registry keyed by request ID.
- Request routing from Laravel's Unix-socket lane to the selected coold stream.
- Timeouts, disconnected-host responses, pending-cap protection, and late result
  handling.
- Host-agent authentication for inbound coold streams.

Flux does not inspect product meaning. `containers.start` is just a frame to a
host; Flux does not know it is part of an nginx deployment.

## coold owns

- Local Podman access.
- Host runtime primitives: images, containers, volumes, networks, logs, exec,
  health checks, host facts.
- Firewall mutation and reconciliation as the sole kernel firewall writer.
- Embedded DNS and service-discovery sync.
- Writing this host's service endpoint rows to Corrosion.
- Builder subprocess supervision when the host advertises builder capability.
- Host-level safety checks such as container-create deny filters.

coold does not store Coolify secrets, users, teams, app ownership, deployment
history, billing data, or business audit.

## Boundary test

If a different orchestrator could reuse the same operation with its own app
model, it probably belongs in coold. If the operation only makes sense because
of Coolify's product model, it belongs in Coolify.
