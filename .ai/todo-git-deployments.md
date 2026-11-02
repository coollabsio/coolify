# Plan: v5 deployments from public Git repositories

## Goal
A user creates a cluster application from a public Git repository URL and a branch. Coolify gets the commit,
builds an image on a builder Node, pushes it to the cluster registry, and deploys it to the placed Nodes
with the existing `workload.deploy` flow. Private repositories, previews, and webhooks come later.

## Decisions (made)
- New v5 pipeline. Do not port v4 `ApplicationDeploymentJob` (SSH + `docker exec` helper, requires `Server`).
  Copy only pure parts into small v5 classes: Railpack config merge, static nginx template, secrets hash idea.
- One job for each deployment type, each short and readable. They share small actions, not a base god-class.
- `builder` role on Nodes. Default: every Node has the role. A user can turn it off for each Node.
- Cluster registry: zot, one for each cluster.
- Build engine: BuildKit (Railpack needs it), as a container on each builder Node.
- Registry TLS (D3): a separate registry CA for each cluster, signed by Coolify (same PHP code path as
  `IssueFluxCertificate`, with the authority as a parameter). Not the Flux CA: Sentinel accepts any
  Flux-CA certificate whose SAN matches the Flux URL host (`connection.rs:383-390`), and the registry key
  lives on a worker Node. Not Let's Encrypt: the registry has no public name.
  - Name: DNS SAN `registry.cluster.coolify.internal` only (no IP SAN). Sentinel discovery DNS resolves it to the
    current registry Node mesh IP, so a registry move changes only DNS and the server certificate.
  - Nodes trust it with `/etc/containers/certs.d/registry.cluster.coolify.internal:<port>/ca.crt` (Podman) and
    the BuildKit `registry` CA config. Coolify sends the CA with `registry.reconcile`.
  - Server certificate: 90 days, renewed by Coolify like `RenewFluxCertificate`. Key file 0600 on the
    registry Node only. Reuse the Plan B file-permission helpers in `ingress_tls.rs`.
  - Auth: one push credential (builders) and one pull credential (all Nodes) for each cluster, zot htpasswd.
- Registry Node: automatic by default = first active Node by id (like the ingress TLS issuer). Coolify moves
  it when that Node is offline. A user can select a Node instead (override). If the selected Node is
  offline, Coolify does not move it; it shows a warning and builds fail with a clear error until the Node
  is back or the user clears the override. A move loses the stored images; the next deploy rebuilds.

## Open decisions
- [x] D5 Rootless BuildKit with process sandbox, `coolify-buildkit` AppArmor profile (`userns`), seccomp
      unconfined, no `--privileged`. Tested 2026-11-02; see decision 0006.

## Job structure (Coolify)
Jobs (`app/Jobs/Node/`), one for each type:
- `DeployImageWorkloadJob`: today's flow (image -> revision -> `CreateDeploymentOperation`).
- `DeployDockerfileWorkloadJob`: resolve commit -> build with Dockerfile -> publish revision -> deploy.
- `DeployRailpackWorkloadJob`: resolve commit -> `railpack prepare` + build -> publish revision -> deploy.
Shared actions (`app/Actions/Node/Build/`), each one task:
- `ResolveGitCommit` (`git ls-remote`, returns SHA or a clear error)
- `SelectBuilderNode` (builder role, `image.build.v1`, online, fewest active builds)
- `StartNodeBuild` / `WaitForNodeBuild` / `CancelNodeBuild` (Sentinel commands, status + log polling)
- `PublishBuiltRevision` (revision with `image = <registry>/<workload>@sha256:<digest>`)
- the existing `CreateDeploymentOperation` + `DeployNodeWorkloadJob` for the rollout
A job selects the type from the workload source; there is no `if build_pack` chain inside one job.

## Storage (registry and build cache)
- Images are content-addressed. A new build adds only the changed layers. Base layers (node, nginx, ...)
  are stored once for all workloads. zot `dedupe: true` also hard-links equal blobs.
- Retention: keep the last N (default 5) pushed tags for each workload, plus the deployed digest.
  zot `retention.policies[].keepTags[].mostRecentlyPushedCount`, `deleteUntagged: true`, `gc: true`.
  Keep a catch-all policy last.
- Coolify deletes the registry repository when a workload is deleted.
- BuildKit cache on each builder: `buildkitd.toml` gcpolicy `reservedSpace` / `maxUsedSpace` /
  `minFreeSpace` (default limit, for example 20% of the disk). `keepBytes` is deprecated.
- Node image storage: Podman keeps pulled images on each Node already (also without a registry). Add a
  cleanup: remove workload images that are not the current or previous revision.
- Show registry and build cache use on the Node page (disk use from Sentinel), so the user sees the cost.

## Architecture
1. Coolify gets the commit SHA. A retry builds the same source.
2. Sentinel starts a one-shot build container with Podman (image `coolify-builder`: git, git-lfs, railpack,
   buildctl). Logs: `container.logs` with `since`. Status: `container.list`. Cancel: stop.
   Sentinel restart: Podman keeps the container. The build does not block the single command executor.
3. The build container clones the commit and builds with BuildKit on the same Node (`BUILDKIT_HOST`).
4. It pushes to zot on the mesh IP of the registry Node. Firewall: each Node host -> registry port only.
   Workload subnets must not reach the registry.
5. On success Coolify creates the revision with the digest. The reconciler, moves, and recovery never
   see a revision without a built image.

## Slices
### Slice 0: ADR (required by `docs/v5/architecture/primitives.md:85-89`) - DONE: `docs/v5/decisions/0006-node-image-builds.md` (Accepted)
- [x] Scheduling, capacity (one build at a time for each builder Node), logs, artifacts, cancellation,
      restart adoption, registry flow, storage limits, build timeout. Test rootless BuildKit under Podman.

### Slice 1: Sentinel (`/root/devel/sentinel`) - DONE, not committed (2026-11-02)
- [x] Protocol: `image.build.v1`, `builder.reconcile.v1`, `registry.reconcile.v1` (`BUILD_CAPABILITIES`),
      `REGISTRY_HOST = registry.cluster.coolify.internal:5443`. Cancel/remove reuse `workload.lifecycle`.
- [x] `crates/control/src/registry.rs`: CA in `certs.d`, pull auth file, zot `coolify-registry` on the mesh IP.
- [x] `crates/control/src/builder.rs`: AppArmor profile, `coolify-buildkitd` with uid map 2,000,000,000,
      build start (idempotent for one build ID).
- [x] `workload.deploy` adds `--authfile` for cluster registry images.
- [x] Discovery publishes `registry.cluster` while zot runs; discovery DNS answers the `cluster` namespace.
- [x] Flux routes `/v1/commands/{image.build,builder.reconcile,registry.reconcile}` (10 min timeout),
      in `crates/flux/src/internal_api/build.rs`.
- [x] `Dockerfile.builder` + `scripts/builder-entrypoint.sh`; `release-main.yaml` publishes
      `ghcr.io/coollabsio/coolify-builder:main`. Not yet in `release.yaml`.
- [x] Checked on the dev Node VM with the same paths and Podman arguments (not through a running Sentinel):
      Railpack build 30 s, Dockerfile build 6 s, push, pull with `--authfile`, app runs, pull user gets 403.
- Pushed as `0f890c6` + CI fix `9ce945b`. `ghcr.io/coollabsio/coolify-builder:main` =
      `sha256:133ce0763ef27cb54b09a24a907659e50e12a029ebbff1a62a9b2b6788007936` (public).
- Notes for slice 3: Coolify must add firewall rules to 5443 in input and output for every Node (registry
  Node included); order is `registry.reconcile` on all Nodes, then `builder.reconcile`; Coolify generates
  bcrypt htpasswd (PHP `password_hash`), passwords 16 to 256 printable characters.

### Slice 2: Coolify data model - DONE, not committed (2026-11-02)
- [x] Migration `2026_11_02_132734_add_image_builds_to_nodes` (applied in dev with `--path`):
      `nodes.is_builder` (default true); `node_workloads.source_type` (default `image`), `git_repository`,
      `git_branch`, `base_directory`, `dockerfile_location`; tables `node_registries`, `node_builds`,
      `node_build_log_lines`.
- [x] Enums `NodeWorkloadSourceType` (image, dockerfile, railpack; no static type: Railpack serves static
      sites) and `NodeBuildStatus` (queued, running, succeeded, failed, cancelled).
- [x] Models `NodeRegistry` (CA, cert, passwords encrypted; `selected_node_id` override, `node_id` current;
      `HOST`, `PUSH_USERNAME`, `PULL_USERNAME`, `imageReference()`), `NodeBuild` (copy of the source settings,
      `active()` scope, `containerName()`), `NodeBuildLogLine`.
- [x] Relations: `NodeCluster::registry()`, `NodeWorkload::builds()` + `buildsFromSource()`,
      `Node::builds()` + `canBuild()` (builder role and a role that runs workloads).
- [x] `ServerTransferCoverage::NOT_TRANSFERRED` for the 3 new models.

### Slice 3: Coolify flow and UI - DONE, not committed (2026-11-02)
- [x] Sentinel `39a27c8`: `FirewallReconcileRequest.registry_ip` opens tcp 5443 from Node hosts to the registry.
- [x] `Build/EnsureNodeRegistry` (own CA, 90-day cert renewed in reconcile, passwords, stored htpasswd).
- [x] Registry Node: `NodeCluster::desiredRegistryNode()/syncRegistryNode()`, moved by `SyncNodeReachability`.
- [x] `ReconcileNodeClusterNetwork`: `registry_ip` in the firewall; after ingress `registry.reconcile` then
      `builder.reconcile` on Nodes with the capability; errors go to `metadata.build_error`, not network status.
- [x] Flux credential grants the 3 capabilities; operation labels; stale recovery closes them.
- [x] Build: `ResolveGitCommit` (runs in the temp dir), `SelectBuilderNode` (one build per Node),
      `RunImageBuild` (image.build, poll container.list + container.logs, digest from last line, remove container),
      `PublishBuiltRevision`, `StartNodeBuild`, `CancelNodeBuild`; jobs `DeployDockerfileWorkloadJob`,
      `DeployRailpackWorkloadJob` with trait `BuildsNodeWorkloadImage` (waits for a free builder, 1 h).
- [x] UI: Public Git Repository -> cluster targets -> `New/ClusterGitRepository`; app page shows source,
      Deploy starts a build, build page (`/builds/{uuid}`) with live log + cancel, builds list on Deployments;
      cluster Servers: builder switch + role labels; Advanced: registry Node select.
- [x] Browser check on dev: create, missing branch error, deploy -> build fails with "No server ... can build"
      (Nodes run the old Sentinel), cluster pages render, network reconcile still succeeds.
- [ ] Not done: image apps still deploy from `Show::deploy` (no `DeployImageWorkloadJob` refactor); no tests.

### Slice 4: Dev and end-to-end
- [ ] Build a public Railpack sample and a Dockerfile sample, deploy to two Nodes.
- [ ] Failure checks: bad URL, missing branch, failed build, cancel, Sentinel restart during build,
      registry Node offline.

## Later
Private repos (GitHub App / deploy keys; reuse v4 token code), webhooks and auto deploy, build-time
secrets (BuildKit `--secret`), external registries, previews, zot replication to a second Node.
