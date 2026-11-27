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
| [0004: Support legacy servers and nodes permanently](0004-support-legacy-servers-and-nodes-permanently.md) | Accepted, amended | Keep legacy Docker servers and Podman nodes as permanent parallel models, with shared product ownership but separate runtime state and control paths. |
| [0005: Serve public HTTP through ingress Nodes](0005-node-ingress.md) | Accepted, amended 2026-11-12 | Run Caddy on each ingress Node, configured by its Sentinel from Corrosion; Coolify writes intent only. HTTPS uses ACME HTTP-01 with certificates in Corrosion and one issuer Node for each cluster. |
| [0006: Build images on builder Nodes](0006-node-image-builds.md) | Accepted | Build Git sources with rootless BuildKit in one-shot Podman containers on builder Nodes (all Nodes by default), keep the image in the Podman store of the build Node or push it to an external registry, and create a revision only from a finished build. The per-cluster zot registry was removed by decision 0030. |
| [0007: Keep each volume on one Node and copy it on a move](0007-node-volumes.md) | Accepted | Pin each workload volume to one Node; a move with volumes stops the source and copies the data Node-to-Node with two rsync passes over WireGuard, and keeps the source volume for 7 days as a rollback. |
| [0008: Keep runtime variables in the environment and add opt-in secret files](0008-node-workload-secrets.md) | Accepted, amended 2026-11-11 | Runtime variables stay `--env`; a variable can be a file at `/run/secrets/<key>`, stored as a Podman secret per revision and deleted on remove; gate on `workload.secrets.v1`; journal a digest of each request, not the request. |
| [0009: Back up Node volumes to S3](0009-node-volume-backups.md) | Accepted | Reuse the v4 `ScheduledVolumeBackup`; Sentinel streams `tar` + `zstd` to team S3 with presigned multipart URLs; restore to a new volume on any Node of the cluster. |
| [0010: Run databases on Nodes](0010-node-databases.md) | Accepted | A database is a typed cluster application with a fixed image and one volume on one Node; backups are dumps through `database.dump.v1`; Postgres first. |
| [0011: Build private Git repositories](0011-node-git-sources.md) | Accepted | Reuse v4 Git sources and webhooks; send a short-lived credential for each build; previews ship later. |
| [0012: Pass build-time secrets as secret mounts](0012-node-build-secrets.md) | Accepted | Secret build variables are BuildKit secret mounts only, never build args. |
| [0013: Upgrade host Sentinel one Node at a time](0013-host-sentinel-upgrades.md) | Accepted | Pinned version; manual cluster upgrade one Node at a time; SSH rollback after 5 minutes without a reconnect. |
| [0014: Keep explicit placement](0014-node-placement-and-failure.md) | Accepted, amended | The user selects Nodes; no automatic failover; notify, then move or restore from backup. |
| [0015: Release Nodes as beta](0015-node-release-criteria.md) | Accepted | An instance setting replaces `SENTINEL_HOST_ENABLED`; lists the beta and stable criteria. |
| [0016: Publish TCP and UDP ports](0016-node-port-publishing.md) | Accepted, amended | Host and container port on every Node of the cluster, with nftables NAT, reserved ports, an optional source allow-list, and no redeploy. |
| [0017: Roll back by image digest](0017-node-revision-rollback.md) | Accepted | Revisions store the digest; the registry keeps 10 images; rebuild or pull when the image is gone. |
| [0018: Add typed exec and terminal commands](0018-node-exec-and-terminal.md) | Accepted | `workload.exec.v1` for scheduled tasks and `workload.terminal.v1` over Flux; no host shell. |
| [0019: Manage DNS records of cluster domains](0019-node-dns-records.md) | Accepted | Reuse v4 managed DNS records for the ingress Node addresses. |
| [0020: Build and place by CPU architecture](0020-node-cpu-architecture.md) | Accepted | Build on a Node of the target architecture without emulation; place by architecture. |
| [0021: Run Flux as a service in the Coolify container](0021-production-flux.md) | Accepted | Flux is an s6 service in the Coolify container with a pinned binary, Coolify-generated secrets in the database, a Flux URL setting, an internal API on 127.0.0.1, and a restart after each certificate renewal. |
| [0022: Deploy without downtime on one Node](0022-node-rolling-deploys.md) | Accepted | Without a writable volume, a redeploy starts the new container in a second slot, waits until it is healthy, drains and removes the old one, and keeps the old one when the new one fails. |
| [0023: Run Compose services on Nodes as stacks of workloads](0023-node-compose-stacks.md) | Accepted | A stack groups one workload for each Compose service, with names by service, traffic allowed inside the stack, `depends_on` order, and file mounts. 316 of 388 templates in the first step. |
| [0024: Share a volume between the services of one stack](0024-node-stack-shared-volumes.md) | Accepted | A named volume that several services of one stack mount is a stack volume on one Node; the services that share it are placed, moved, and restored together. 18 more templates. |
| [0025: Deploy each pull request as a preview workload](0025-node-preview-deployments.md) | Accepted | A preview is a child cluster application with its own build, empty volumes, and a domain from the template. Pull request events open, update, and close it. Fork previews get no production values, build secrets, or outbound rules. No limit on the number of previews. |
| [0026: Keep Horizon and move workload convergence into Sentinel](0026-sentinel-workload-convergence.md) | Accepted | No new queue in v5.0; jobs carry only IDs; Coolify sends the desired set of each Node through Flux, Sentinel converges and reports the status of each revision behind `workload.reconcile.v1`; placement, cross-Node order, builds, and one-shot commands stay in Coolify. |
| [0027: Clean up unused images on Nodes](0027-node-image-cleanup.md) | Accepted | A Sentinel `image.prune.v1` command removes images that no container uses, except a keep list from Coolify (the last 3 revisions of each workload and the pinned infrastructure images); a daily run and a disk threshold like v4, **Clean up now** on the Node page, and a journald limit; volumes stay out. |
| [0028: Compose keys that Nodes ignore today](0028-node-stack-ignored-compose-keys.md) | Accepted | Map `shm_size`, `ulimits`, `tmpfs`, `stop_grace_period`, `tty`, and `stdin_open` to new validated Sentinel fields behind `workload.runtime_options.v1`, and `deploy.resources` to the existing CPU and memory fields; `sysctls` and the isolation keys stay out. |
| [0029: REST API for cluster resources](0029-node-api.md) | Accepted | `/api/v1/cluster-applications`, `/cluster-databases`, `/cluster-stacks`, `/clusters`, and `/nodes` with the v4 conventions; every write calls the action of the Livewire page, the policy of the resource authorizes the token user, and secret values follow `ApiSensitiveData`. |
| [0030: Copy local images between Nodes without a registry](0030-node-peer-image-fetch.md) | Accepted | A Node that needs a local image fetches it from a peer Node: `image.serve.v1` exports it with `podman save --format oci-dir --uncompressed` and serves it on the WireGuard address for one transfer, `image.fetch.v1` pulls only the missing layers and keeps the image ID. `FetchNodeImage` runs in every deploy. The image storage is `LOCAL` (default with two or more Nodes) or `EXTERNAL`; the built-in zot registry is removed. |
| [0031: Proxy options for cluster domains](0031-node-ingress-proxy-options.md) | Accepted | Structured route options behind `ingress.route_options.v1`: basic auth with bcrypt hashes only, an IP allow list, request and response headers, compression, and path strip. Fail closed on older Sentinels. No free-form Caddy config; Compose proxy labels get a note. |
| [0032: Container runtime options of cluster applications](0032-node-container-runtime-options.md) | Accepted | Parse **Custom Docker Options** on save into typed fields behind `workload.runtime_options.v2`, refuse the other flags; GPU with CDI devices on Nodes that a team admin opens for GPU; pre- and post-deployment commands with `workload.exec.v1`; user labels without the reserved prefixes. |
| [0033: Watch paths, submodules, and LFS of cluster applications](0033-node-git-source-options.md) | Accepted | Submodule, LFS, and shallow clone toggles (on by default) behind `image.build.git_options.v1`; an LFS failure fails the build; one watch-path list for pushes and previews, and a build when the changed files are unknown. A fork preview does not use the credential for submodules or LFS. Replaces the watch-path text of 0025. |
| [0034: SSL and import from an upload for cluster databases](0034-node-database-ssl-and-upload-import.md) | Accepted | One CA for each cluster, its key encrypted in Coolify only; the certificate of each database as secret files (0008). An upload uses the v4 chunked upload and a one-time Flux download for one Node, and Sentinel `database.import.v1`. |
| [0035: Concurrent builds on builder Nodes](0035-node-concurrent-builds.md) | Accepted | `nodes.concurrent_builds` (default 1, 1 to 16) with a locked slot claim and a FIFO queue for each cluster; Sentinel checks it behind `image.build.concurrent.v1`; memory, CPU, pids, and cache limits on `coolify-buildkitd`. |
| [0036: Isolation keys that lock templates on Nodes](0036-node-stack-isolation-keys.md) | Accepted | Three tiers behind `workload.isolation_options.v1`: safe capabilities, `no-new-privileges`, and container sysctls for every user; `NET_ADMIN` and a short list for team admins; `privileged`, host network and PID, and system host paths refused. 12 more templates. |

## Adding a decision

- Use the next four-digit number and a short descriptive filename.
- Record the context, decision, consequences, and choices intentionally left
  open.
- Do not copy an archived decision without reviewing it against the current v5
  direction.
- Add every new decision to the table above.
