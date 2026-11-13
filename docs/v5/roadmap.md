# Coolify v5 Roadmap

**Last reviewed:** 2026-11-13

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
| B10 | ~~Installer scope: control plane plus SSH workers~~ | [0002 amendment](decisions/0002-separate-legacy-upgrades-from-v5-installs.md) | Done: worker onboarding over SSH verified with a second Node; production Flux is B16. |
| B11 | ~~Host Sentinel cluster upgrades~~ | [0013](decisions/0013-host-sentinel-upgrades.md) | Done: one Node at a time for each cluster, reconnect and capability check, SSH rollback, stop at the first failure. |
| B12 | ~~Instance setting for Nodes, labeled Beta~~ | [0015](decisions/0015-node-release-criteria.md) | Done: `is_nodes_enabled` in Settings > Advanced (instance admins only), Beta labels. Tests turn Nodes on with `setNodesEnabled()` (tests/Pest.php); the Node, Flux, Sentinel, and cluster tests pass (2026-11-13). CI runs no PHP tests, so run them locally. |
| B13 | Release Sentinel, Flux, and `coolify-builder` with every new command | 0011, 0013, 0021 | Sentinel `a8bef06` is pushed and published as `main-a8bef06`; `FLUX_IMAGE` in both Dockerfiles pins it (Coolify `aaad15331c`). A versioned Sentinel release (and B1) is still open. |
| B15 | ~~Traffic between applications on different Nodes is dropped~~ | 0005 | Fixed in Sentinel `8ee8341`: a `mesh_nat` chain in `coolify_cluster` (priority srcnat - 5) keeps the workload source IP before the netavark masquerade. Also fixed `ae0217c`: discovery DNS answers every name of its zones and no longer loops through systemd-resolved. |
| B16 | ~~Production Flux~~ | [0021](decisions/0021-production-flux.md) | Done: Flux is an s6 service in the Coolify container, secrets in the database, Flux URL setting, restart after renewal, onboarding reachability check. |
| B14 | ~~Dockerfile without Git (v4 "Dockerfile" type)~~ | 0006 | Done: source type `dockerfile_inline`, capability `image.build.inline_dockerfile.v1`. |

## Build for stable

| # | Work | Decision |
| --- | --- | --- |
| S1 | ~~Databases on Nodes~~ | [0010](decisions/0010-node-databases.md). Done: 8 engines deploy healthy; dump backups and imports for 7 engines (`database.dump.v1`, `database.restore.v1`). ClickHouse dumps left out (its `BACKUP` needs the S3 key on the Node). |
| S2 | ~~Offline Node notifications and recovery~~ | [0014](decisions/0014-node-placement-and-failure.md). Done: "Server offline" state, affected apps in the notification, recovery move from backups, fencing when the Node returns. Recovery from the Node page is not built. |
| S3 | Revision rollback | [0017](decisions/0017-node-revision-rollback.md) |
| S4 | ~~Exec and terminal~~ | [0018](decisions/0018-node-exec-and-terminal.md). Done: scheduled tasks and a container terminal through Flux. |
| S5 | ~~Deploy without downtime on one Node~~ | [0022](decisions/0022-node-rolling-deploys.md). Done: two container slots, make-before-break without a writable volume, stop-then-start with one. Stop-then-start fails no requests in the test (slowest 0.25 s): Caddy holds requests while the only container restarts (Sentinel `011223e`, `a8bef06`: the passive health check only for several upstreams), and Sentinel publishes every second while a health check is `starting` (`ce966b5`). |

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
| L9 | ~~Reverse lookups of external addresses from containers get NXDOMAIN~~ | — | Fixed in Sentinel `26334ea`: the discovery DNS claims only the mesh and workload reverse zones and forwards the rest. A restart of systemd-resolved drops the routing domains and is not detected as drift (older gap). |
| L8 | Strict "serve files only" mode for Static | 0006. Needs a Sentinel builder change. |

## Small gaps

Gaps in finished work that have no row above.

| # | Gap | From |
| --- | --- | --- |
| G1 | A Redis database created before S1 has no debug flag in its start command, so an import fails until the database is deployed again with the new start command. | S1 |
| G2 | `MoveNodeWorkloadJob` has its own copy of the network-revision wait; it should use the shared action. | S2 |
| G3 | The **Sentinel** section of a Node shows the old connection state after **Repair trust** until a reload, because Sentinel reconnects a few seconds after the action. | B16 |
| G4 | Port 7443 is published on every install. If another program on the host uses it, the Coolify container cannot start after the upgrade. `FLUX_PORT` changes the port. | 0021 |

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
