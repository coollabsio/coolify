# Coolify v5 Roadmap

**Last reviewed:** 2026-11-12

This list sorts the open v5 work. Every item in "Build" has an accepted
decision. The accepted decisions are in [decisions/README.md](decisions/README.md).
Decision [0015](decisions/0015-node-release-criteria.md) defines what beta and
stable need.

## Build for beta

| # | Work | Decision | Notes |
| --- | --- | --- | --- |
| B1 | Pin `sentinel-host` to a release that has the volume, secret, health check, and build commands | 0006, 0007, 0008 | `upstream.json` pins `sentinel-host` 1.1.3. The volume commands are first in Sentinel v1.1.5. A Node that installs or upgrades Sentinel gets 1.1.3, so volumes, moves with volumes, and secret files fail on it. |
| B2 | ~~Private registry pulls~~ | 0006 "Before release" | Done (uncommitted, verified on dev): team registry credentials (Keys & Tokens), `--authfile` on tmpfs for `workload.deploy`. |
| B3 | ~~Push built images to an external registry~~ | 0006 "Before release" | Done (uncommitted, verified on dev): image name + tag on Git apps, second push after the cluster push. |
| B4 | ~~Static build source~~ | 0006 | Done (uncommitted): built by the Railpack job with the base directory. Railpack serves files only when the folder has `index.html`, `Staticfile`, or `public/`. |
| B5 | Bring the status documents up to date | — | `architecture/current-state.md` still lists HTTPS ingress, volumes, secrets, and health gates as not implemented. Decision 0005 still says "HTTP only". Check that HTTPS follows the 0005 plan first. |
| B6 | Merge `next` into `v5-progress` | — | On 2026-11-11 the branch was 248 commits behind `next`. |
| B7 | ~~Volume backups and restore~~ | [0009](decisions/0009-node-volume-backups.md) | Done (uncommitted, verified on dev with one Node). Restore to another Node is untested; restore from a dead Node is not built. |
| B8 | ~~Private Git sources and webhooks~~ | [0011](decisions/0011-node-git-sources.md) | Done (uncommitted, verified on dev): GitHub App and deploy key; GitHub App, GitHub, GitLab, Gitea, and Bitbucket push webhooks; deploy API. Left: auto-deploy toggle, queue a push during a running build. Previews come after beta. |
| B9 | Build-time secrets | [0012](decisions/0012-node-build-secrets.md) | |
| B10 | Installer scope: control plane plus SSH workers | [0002 amendment](decisions/0002-separate-legacy-upgrades-from-v5-installs.md) | Mostly done. Check the install flow end to end. |
| B11 | Host Sentinel cluster upgrades | [0013](decisions/0013-host-sentinel-upgrades.md) | |
| B12 | Instance setting for Nodes, labeled Beta | [0015](decisions/0015-node-release-criteria.md) | Replaces `SENTINEL_HOST_ENABLED`. |
| B13 | Release Sentinel, Flux, and `coolify-builder` with private Git and the Podman 6 health fix | 0011, 0013 | Built and tested only as local images on the dev Node. B1 pins the release. |
| B14 | Dockerfile without Git (v4 "Dockerfile" type) | 0006 | Needs an inline Dockerfile field in `image.build`. |

## Build for stable

| # | Work | Decision |
| --- | --- | --- |
| S1 | Databases on Nodes | [0010](decisions/0010-node-databases.md). Create and deploy done (uncommitted, Postgres verified). Left: deploy test of the other 7 engines, `database.dump.v1` backups and restore. |
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
