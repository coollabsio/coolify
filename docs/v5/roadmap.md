# Coolify v5 Roadmap

**Last reviewed:** 2026-11-12

This list sorts the open v5 work. Every item in "Build" has an accepted
decision. The accepted decisions are in [decisions/README.md](decisions/README.md).
Decision [0015](decisions/0015-node-release-criteria.md) defines what beta and
stable need.

## Build for beta

| # | Work | Decision | Notes |
| --- | --- | --- | --- |
| B1 | Pin `sentinel-host` to a release that has the new commands | 0006 to 0013 | `upstream.json` pins `sentinel-host` 1.1.5. Releases v1.1.5 and v1.1.6 do not have private Git, registry login, external push, volume backup and restore, `image.load.v1`, build variables, or inline Dockerfiles. Blocked by B13. |
| B2 | ~~Private registry pulls~~ | 0006 "Before release" | Done (verified on dev): team registry credentials (Keys & Tokens), `--authfile` on tmpfs for `workload.deploy`. |
| B3 | ~~Push built images to an external registry~~ | 0006 "Before release" | Done (verified on dev): image name + tag on Git apps, second push after the cluster push. |
| B4 | ~~Static build source~~ | 0006 | Done: built by the Railpack job with the base directory. Railpack serves files only when the folder has `index.html`, `Staticfile`, or `public/`. |
| B5 | ~~Bring the status documents up to date~~ | — | Done on 2026-11-12. |
| B6 | ~~Merge `next` into `v5-progress`~~ | — | Done on 2026-11-12 (merge `37aefab058`). |
| B7 | ~~Volume backups and restore~~ | [0009](decisions/0009-node-volume-backups.md) | Done (verified on dev with one Node). Restore to another Node is untested; restore from a dead Node is not built. |
| B8 | ~~Private Git sources and webhooks~~ | [0011](decisions/0011-node-git-sources.md) | Done (verified on dev): GitHub App and deploy key; GitHub App, GitHub, GitLab, Gitea, and Bitbucket push webhooks; deploy API. Left: auto-deploy toggle, queue a push during a running build. Previews come after beta. |
| B9 | ~~Build-time secrets~~ | [0012](decisions/0012-node-build-secrets.md) | Done: plain build arguments and BuildKit secret mounts (capability `image.build.variables.v1`). A Dockerfile build does not rerun a cached step when only a secret value changes (same as v4). |
| B10 | Installer scope: control plane plus SSH workers | [0002 amendment](decisions/0002-separate-legacy-upgrades-from-v5-installs.md) | Worker onboarding over SSH verified with a second Node. Blocked for production: the production installer has no Flux service, Flux TLS certificate, or signing keys, and `nodesEnabled()` needs a development instance. Needs a design. |
| B11 | ~~Host Sentinel cluster upgrades~~ | [0013](decisions/0013-host-sentinel-upgrades.md) | Done: one Node at a time for each cluster, reconnect and capability check, SSH rollback, stop at the first failure. |
| B12 | ~~Instance setting for Nodes, labeled Beta~~ | [0015](decisions/0015-node-release-criteria.md) | Done: `is_nodes_enabled` in Settings > Advanced (instance admins only), Beta labels. Existing tests still set the old config key. |
| B13 | Release Sentinel, Flux, and `coolify-builder` with every new command | 0011, 0013 | `main-ea82bdb` images exist. Build variables and inline Dockerfiles are in Sentinel commit `6784fcd`, not pushed yet. |
| B15 | Traffic between applications on different Nodes is dropped | 0005 | Sentinel bug: netavark (Podman 6) masquerades in its own nftables table, so the iptables `RETURN` exemptions in `configure_mesh_nat` (`crates/control/src/network.rs`) do not apply, and the forward chain drops the masqueraded source. |
| B16 | Production Flux (service, TLS certificate, signing keys, firewall) | 0002, 0015 | Needed before Nodes can leave development instances. Needs a design. |
| B14 | ~~Dockerfile without Git (v4 "Dockerfile" type)~~ | 0006 | Done: source type `dockerfile_inline`, capability `image.build.inline_dockerfile.v1`. |

## Build for stable

| # | Work | Decision |
| --- | --- | --- |
| S1 | Databases on Nodes | [0010](decisions/0010-node-databases.md). Create and deploy done (Postgres verified). Left: deploy test of the other 7 engines, `database.dump.v1` backups and restore. |
| S2 | Offline Node notifications and recovery | [0014](decisions/0014-node-placement-and-failure.md) |
| S3 | Revision rollback | [0017](decisions/0017-node-revision-rollback.md) |
| S4 | Exec and terminal | [0018](decisions/0018-node-exec-and-terminal.md) |
| S5 | Deploy without downtime on one Node (v4 rolling update) | No decision. `podman run --replace` stops the old container before the new one starts. |

## Build when there is time

| # | Work | Decision |
| --- | --- | --- |
| L1 | TCP and UDP port publishing | [0016](decisions/0016-node-port-publishing.md) |
| L2 | DNS records of cluster domains | [0019](decisions/0019-node-dns-records.md) |
| L3 | Owner and mode of secret files | [0008 amendment](decisions/0008-node-workload-secrets.md) |
| L4 | Build and place by CPU architecture | [0020](decisions/0020-node-cpu-architecture.md) |
| L5 | Preview deployments | [0011](decisions/0011-node-git-sources.md) |
| L6 | Ctrl+K search entries for cluster settings pages | — |
| L7 | A clear error instead of a 500 when you create a database and the cluster network is not ready | 0010 |
| L8 | Strict "serve files only" mode for Static | 0006. Needs a Sentinel builder change. |

## Postponed

These items have no decision. Decide them after the first release.

| # | Item | Left open in |
| --- | --- | --- |
| P1 | Docker Compose and one-click services on Nodes | 0004 |
| P2 | Converting a legacy server to a Node, also as a docs guide | 0002, 0004 |
| P3 | Multiple Flux instances for Coolify Cloud | current-state |
| P4 | Log export and retention | 0003 |
| P5 | Volume replication, shared volumes, quotas, and resize | 0007 |
| P6 | Combined mode (control plane and Node on one host), and a join without SSH | 0002 amendment |
| P7 | Automatic placement and automatic failover | 0014 |
