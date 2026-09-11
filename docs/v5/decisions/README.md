# Active Coolify v5 Decisions

This directory contains the active architecture decisions for Coolify v5.
Each accepted decision records only what has been agreed and leaves unrelated
or unsettled design choices for later decisions.

Files under `docs/v5/archive/`, `docs/v5/migrations/`, `docs/v5/ui/`, and
`docs/v5/architecture/adr/` are historical references. They do not define the
current v5 architecture.

## Decisions

| Decision | Status | Summary |
| --- | --- | --- |
| [0001: Combine coold with Sentinel](0001-combine-coold-with-sentinel.md) | Accepted | Keep one Sentinel product: retain the v4 container during a controlled transition, introduce the host-native v5 deployment, move capabilities to it, and then retire the container. |
| [0002: Separate legacy upgrades from native v5 installations](0002-separate-legacy-upgrades-from-v5-installs.md) | Accepted | Keep upgraded Docker localhost servers on the legacy path, while fresh v5 installs support combined, control-plane-only, and worker modes. |
| [0003: Transport Sentinel logs on demand](0003-transport-sentinel-logs-on-demand.md) | Accepted | Keep a bounded structured log buffer in Sentinel, read it through Flux on demand, and retain SSH plus journald as the failure-path fallback. |

## Adding a decision

- Use the next four-digit number and a short descriptive filename.
- Record the context, decision, consequences, and choices intentionally left
  open.
- Do not copy an archived decision without reviewing it against the current v5
  direction.
- Add every new decision to the table above.
