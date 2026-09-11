# Coolify v5 Architecture

This directory contains the active high-level architecture references for the
new v5 implementation.

The current control path is:

```text
User / API
    ↓
Coolify Laravel control plane
    ↓ authenticated internal HTTP
Flux
    ⇅ outbound TLS gRPC stream
Host-native Sentinel managed by systemd
    ↓
Current diagnostics; planned runtime, network, firewall, DNS, and Corrosion capabilities
```

Coolify owns product intent, authorization, and durable product state. Flux
routes typed requests to connected hosts. Sentinel validates and performs
explicit host-local operations. SSH remains the bootstrap and recovery path.

## Documents

| Document | Purpose |
| --- | --- |
| [Current state](current-state.md) | What is implemented now, how it works, and what remains. |
| [Responsibility split](responsibility-split.md) | Ownership boundaries between Coolify, Flux, and Sentinel. |
| [Primitives](primitives.md) | Target typed host-operation surface. Implementations can lag behind this catalog. |
| [Active decisions](../decisions/README.md) | Accepted architecture and migration decisions. |
| [Flux TLS design](../../superpowers/specs/2026-09-11-flux-tls-design.md) | Private-CA trust, renewal, recovery, and planned rotation. |

Files under `docs/v5/archive/`, `docs/v5/migrations/`, `docs/v5/ui/`, and
`docs/v5/architecture/adr/` are historical references. They do not define the
current architecture.
