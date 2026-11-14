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
| [0001: Combine coold with Sentinel](0001-combine-coold-with-sentinel.md) | Accepted, amended 2026-11-11 | Keep one Sentinel product with a container deployment for legacy servers and a host-native deployment for nodes; retire only a node's temporary container after capability handover. |
| [0002: Separate legacy upgrades from native node installations](0002-separate-legacy-upgrades-from-v5-installs.md) | Accepted, amended 2026-11-11 | Keep upgraded Docker localhost servers on the legacy path, while fresh v5 installs support combined, control-plane-only, and worker modes. |
| [0003: Transport Sentinel logs on demand](0003-transport-sentinel-logs-on-demand.md) | Accepted | Keep a bounded structured log buffer in Sentinel, read it through Flux on demand, and retain SSH plus journald as the failure-path fallback. |
| [0004: Support legacy servers and nodes permanently](0004-support-legacy-servers-and-nodes-permanently.md) | Accepted | Keep legacy Docker servers and Podman nodes as permanent parallel models, with shared product ownership but separate runtime state and control paths. |
| [0005: Serve public HTTP through ingress Nodes](0005-node-ingress.md) | Accepted, amended 2026-11-12 | Run Caddy on each ingress Node, configured by its Sentinel from Corrosion; Coolify writes intent only. HTTPS uses ACME HTTP-01 with certificates in Corrosion and one issuer Node for each cluster. |
| [0006: Build images on builder Nodes and store them in a cluster registry](0006-node-image-builds.md) | Accepted | Build Git sources with rootless BuildKit in one-shot Podman containers on builder Nodes (all Nodes by default), push to a per-cluster zot registry with its own CA, and create a revision only from a built digest. |
| [0007: Keep each volume on one Node and copy it on a move](0007-node-volumes.md) | Accepted | Pin each workload volume to one Node; a move with volumes stops the source and copies the data Node-to-Node with two rsync passes over WireGuard, and keeps the source volume for 7 days as a rollback. |
| [0008: Keep runtime variables in the environment and add opt-in secret files](0008-node-workload-secrets.md) | Accepted, amended 2026-11-11 | Runtime variables stay `--env`; a variable can be a file at `/run/secrets/<key>`, stored as a Podman secret per revision and deleted on remove; gate on `workload.secrets.v1`; journal a digest of each request, not the request. |
| [0009: Back up Node volumes to S3](0009-node-volume-backups.md) | Accepted | Reuse the v4 `ScheduledVolumeBackup`; Sentinel streams `tar` + `zstd` to team S3 with presigned multipart URLs; restore to a new volume on any Node of the cluster. |
| [0010: Run databases on Nodes](0010-node-databases.md) | Accepted | A database is a typed cluster application with a fixed image and one volume on one Node; backups are dumps through `database.dump.v1`; Postgres first. |
| [0011: Build private Git repositories](0011-node-git-sources.md) | Accepted | Reuse v4 Git sources and webhooks; send a short-lived credential for each build; previews ship later. |
| [0012: Pass build-time secrets as secret mounts](0012-node-build-secrets.md) | Accepted | Secret build variables are BuildKit secret mounts only, never build args. |
| [0013: Upgrade host Sentinel one Node at a time](0013-host-sentinel-upgrades.md) | Accepted | Pinned version; manual cluster upgrade one Node at a time; SSH rollback after 5 minutes without a reconnect. |
| [0014: Keep explicit placement](0014-node-placement-and-failure.md) | Accepted | The user selects Nodes; no automatic failover; notify, then move or restore from backup. |
| [0015: Release Nodes as beta](0015-node-release-criteria.md) | Accepted | An instance setting replaces `SENTINEL_HOST_ENABLED`; lists the beta and stable criteria. |
| [0016: Publish TCP and UDP ports](0016-node-port-publishing.md) | Accepted | Host port = container port on every Node of the cluster, with nftables NAT and no redeploy. |
| [0017: Roll back by image digest](0017-node-revision-rollback.md) | Accepted | Revisions store the digest; the registry keeps 10 images; rebuild or pull when the image is gone. |
| [0018: Add typed exec and terminal commands](0018-node-exec-and-terminal.md) | Accepted | `workload.exec.v1` for scheduled tasks and `workload.terminal.v1` over Flux; no host shell. |
| [0019: Manage DNS records of cluster domains](0019-node-dns-records.md) | Accepted | Reuse v4 managed DNS records for the ingress Node addresses. |
| [0020: Build and place by CPU architecture](0020-node-cpu-architecture.md) | Accepted | Build on a Node of the target architecture without emulation; place by architecture. |
| [0021: Run Flux as a service in the Coolify container](0021-production-flux.md) | Accepted | Flux is an s6 service in the Coolify container with a pinned binary, Coolify-generated secrets in the database, a Flux URL setting, an internal API on 127.0.0.1, and a restart after each certificate renewal. |
| [0022: Deploy without downtime on one Node](0022-node-rolling-deploys.md) | Accepted | Without a writable volume, a redeploy starts the new container in a second slot, waits until it is healthy, drains and removes the old one, and keeps the old one when the new one fails. |
| [0023: Run Compose services on Nodes as stacks of workloads](0023-node-compose-stacks.md) | Proposed | A stack groups one workload for each Compose service, with names by service, traffic allowed inside the stack, `depends_on` order, and file mounts. 316 of 388 templates in the first step. |

## Adding a decision

- Use the next four-digit number and a short descriptive filename.
- Record the context, decision, consequences, and choices intentionally left
  open.
- Do not copy an archived decision without reviewing it against the current v5
  direction.
- Add every new decision to the table above.
