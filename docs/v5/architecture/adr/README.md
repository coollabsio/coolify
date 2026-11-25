# Architecture Decision Records

This directory records architecture decisions for the Coolify v5 ecosystem:
Coolify Laravel, Flux, coold, and the contracts between them.

ADRs are for decisions that are hard to reverse, surprising without context, and
made after a real trade-off. If a note is just reference material, put it in the
main architecture docs instead.

## Index

| ADR | Decision |
| --- | --- |
| [0001](0001-control-plane-flux-coold-split.md) | Split v5 into Coolify control plane, Flux broker, and coold host agent. |
| [0002](0002-coold-host-agent-boundary.md) | Keep coold as a narrow host agent with explicit primitives. |
| [0003](0003-flux-connection-broker-boundary.md) | Keep Flux as the connection broker between Coolify and coold. |
| [0004](0004-coolify-control-plane-boundary.md) | Keep Coolify Laravel as the product control plane. |

## Format

Use the next sequential number and a short slug:

```text
0005-short-decision-slug.md
```

Start small. A useful ADR can be one paragraph:

```md
# ADR 0005: Short title of the decision

Coolify v5 will ... because ... This trades ... for ...
```

Add optional sections only when they clarify the decision:

- **Status**: `Proposed`, `Accepted`, `Deprecated`, or `Superseded by ADR NNNN`.
- **Context**: what forced the decision.
- **Decision**: what we chose.
- **Consequences**: non-obvious benefits, costs, or follow-up constraints.
- **Considered options**: rejected alternatives worth remembering.

## Scope guide

- Coolify ADRs cover product/control-plane state, RBAC, deployments, billing,
  APIs, UI-visible behavior, and durable audit/history.
- Flux ADRs cover connection brokerage, host stream routing, request/response
  correlation, backpressure, and transport-level authentication.
- coold ADRs cover host primitives, privileged execution, Podman/firewall/DNS,
  local safety rules, and agent lifecycle.
- Cross-cutting ADRs belong here when the decision changes contracts between
  Coolify, Flux, and coold.
