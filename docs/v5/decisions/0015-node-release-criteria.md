# Decision 0015: Release Nodes as beta behind an instance setting

**Status: Accepted**

**Date: 2026-11-11**

## Context

Nodes are behind the `SENTINEL_HOST_ENABLED` environment variable, which
defaults to `false`. Nobody tests Nodes before the gate goes away.

## Decision

- An instance setting replaces `SENTINEL_HOST_ENABLED`. Only instance admins can
  turn it on. The UI labels Nodes **Beta** until the stable criteria are done.
- Beta needs:
  - the build items of `roadmap.md`: pin a `sentinel-host` release with the
    volume, secret, and build commands; private registry pulls; push to an
    external registry; static build source; up-to-date status documents; and a
    merge of `next`;
  - volume backups (0009), private Git sources and webhooks without previews
    (0011), build-time secrets (0012), the installer scope (0002 amendment), and
    host Sentinel upgrades (0013).
- Stable also needs databases (0010), placement and failure recovery (0014),
  revision rollback (0017), and exec and terminal (0018).

## Consequences

- Users can run stateless and Git applications on Nodes early, with backups.
- Databases and Compose stacks are not in beta.
