# Decision 0006: Build images on builder Nodes and store them in a cluster registry

**Status: Accepted**

**Date: 2026-11-02**

## Context

Cluster applications deploy only prebuilt images today. Users also need to
deploy from a public Git repository. `docs/v5/architecture/primitives.md`
keeps the builder out of the Flux and Sentinel primitive set until a decision
covers scheduling, capacity, logs, artifacts, cancellation, restart adoption,
and the registry flow. This decision covers these items.

These facts from the current system limit the design:

- Each Node pulls its own image with `podman run --pull`. A built image must be
  in a registry that every Node can reach.
- Sentinel runs one command at a time. The Flux internal API is synchronous,
  with a 10-minute maximum for deploys. A build cannot be one long command.
- The reconciler, Node moves, and operation recovery deploy the latest revision
  without a user. A revision must not exist before its image exists.
- The v4 build (`ApplicationDeploymentJob`) runs shell commands over SSH in a
  helper container that uses the host Docker socket. Nodes have no Docker and
  no SSH deploy path (decision 0004).

## Decision

### Pipeline

Coolify has one deployment job for each source type: image, Dockerfile,
Railpack, and static. The jobs share small actions: resolve the commit, select
a builder, start the build, wait for it, publish the revision, and deploy. The
v4 build job is not reused. Only pure parts (Railpack config merge, the static
nginx template) are copied into v5 classes.

1. Coolify resolves the branch to a commit SHA with `git ls-remote`. Every
   attempt of a build uses this SHA.
2. Coolify selects a builder Node: online, with the `builder` role and the
   `image.build.v1` capability, and with the fewest active builds.
3. Sentinel starts a one-shot build container with Podman. It clones the
   commit, runs `railpack prepare` for Railpack sources, and builds with
   BuildKit on the same Node. It pushes the image to the cluster registry and
   writes the digest from the `buildctl --metadata-file` output.
4. When the build succeeds, Coolify creates a `NodeWorkloadRevision` with
   `image = registry.cluster.coolify.internal:5443/<workload>@sha256:<digest>`. Then
   the existing `CreateDeploymentOperation` flow deploys it.

### Builder Nodes

- Every Node has the `builder` role by default. A user can turn it off for
  each Node.
- A builder Node runs one long-lived `buildkitd` container. Its state is in a
  named volume, so cache garbage collection and removal are explicit.
- `buildkitd` is the rootless image with the process sandbox on. Its uid range
  starts at host uid 2,000,000,000, so the BuildKit user (container uid 1000)
  is not a real host user. With rootful Podman and no uid map, it would be host
  uid 1000, which is often a user with sudo on cloud images. It does not
  use `--privileged`. It needs `seccomp=unconfined`, `systempaths=unconfined`,
  and `/dev/fuse`. Where AppArmor restricts unprivileged user namespaces (the
  Ubuntu 24.04 default), Sentinel installs the `coolify-buildkit` AppArmor
  profile, which allows `userns` for this container only. Sentinel does not
  change the host sysctl.
- Capacity: one build at a time for each builder Node. Others wait in the
  Coolify queue. Default build timeout: 30 minutes.

### Sentinel protocol

- New capability `image.build.v1`. It starts the build container
  `coolify-build-<build id>` and returns when the container runs. It does not
  wait for the build. A repeated request for the same build ID returns the
  existing container.
- The build container has the label `coolify.build=<build uuid>`. Coolify reads
  the status and exit code with `container.list` and the log with
  `container.logs` and `since`. This follows decision 0003 (logs on demand).
- Cancel stops the build container, and Coolify removes it when it no longer
  needs the log. Both use `workload.lifecycle`.
- The last log line of a successful build is
  `COOLIFY_BUILD_RESULT {"digest":"sha256:..."}` (from
  `buildctl --metadata-file`).
- The build container (`coolify-builder` image, published from the Sentinel
  repository) runs with the same uid range as `buildkitd`, as uid 1000, with
  all capabilities dropped and `no-new-privileges`. It reaches BuildKit only
  through the socket in `/var/lib/coolify/builder/run`.
- Restart adoption: Podman keeps the build container when Sentinel restarts.
  Coolify finds a build again by its label. A build container that has no
  Coolify build record is removed.
- `builder.reconcile` and `registry.reconcile` are idempotent and converge
  `buildkitd`, zot, and their configuration.

### Cluster registry

- One zot registry for each cluster, on one Node. By default this is the first
  active Node by ID. Coolify moves the registry when that Node is offline. A
  user can select a Node instead. Coolify does not move a selected Node; it
  shows a warning, and builds fail until the Node is back or the user clears
  the selection. A move loses the stored images. The next deploy rebuilds them.
- The name is `registry.cluster.coolify.internal:5443`. The registry Node
  publishes an endpoint row in the `cluster` namespace while zot runs, and
  Sentinel discovery DNS answers it like a Node name. zot listens on the mesh
  IP of that Node. The cluster firewall drops traffic from and to the cluster
  range in both the `input` and the `output` chain, also from a Node to
  itself. Coolify must add a rule to port 5443 for every Node, the registry
  Node included. Workload subnets cannot reach it.
- TLS: a separate registry CA for each cluster, signed by Coolify. The server
  certificate has only the DNS name `registry.cluster.coolify.internal`, is
  valid for
  90 days, and Coolify renews it. Nodes trust the CA through
  `/etc/containers/certs.d/<host:port>/ca.crt` and the BuildKit registry
  configuration. The Flux CA is not used: Sentinel accepts any Flux CA
  certificate whose name matches the Flux URL host, and the registry key is on
  a worker Node.
- Auth: zot htpasswd with a push user for builders and a pull user for all
  Nodes. Anonymous access is denied. `workload.deploy` passes the pull
  credential to Podman with `--authfile` for images in the cluster registry.
- Storage: `dedupe`, `gc`, and a retention policy that keeps the last 5 pushed
  tags of each workload and removes untagged manifests. Coolify deletes a
  workload's repository when the workload is deleted.

### Storage limits on builder Nodes

- BuildKit garbage collection uses `reservedSpace`, `maxUsedSpace`, and
  `minFreeSpace` in `buildkitd.toml`. The default `maxUsedSpace` is 20% of the
  disk.
- The Node page shows the disk use of the build cache and the registry.

## Evidence

On 2026-11-02, a test on a development worker Node (Ubuntu 24.04.5, Podman
4.9.3, AppArmor on, `apparmor_restrict_unprivileged_userns=1`, 2 vCPU, 3 GiB)
used BuildKit v0.34.1, Railpack v0.43.0, and zot v2.1.23:

| Check | Result |
| --- | --- |
| Rootless BuildKit with the default Podman security options, with or without the process sandbox | Fails: `failed to share mount point: /` |
| Rootless BuildKit with seccomp and AppArmor unconfined | Fails: user namespace blocked by `apparmor_restrict_unprivileged_userns` |
| Rootless BuildKit with a `userns` AppArmor profile and seccomp unconfined, process sandbox on | Passes: Railpack build and push in 28 s with a cold cache |
| The same with the default seccomp profile | Fails: the build's `RUN` step exits with code 1 |
| Rootful BuildKit with `--privileged` | Passes: Dockerfile build 4 s, Railpack build 36 s cold and 1 s warm |
| `railpack prepare` + BuildKit `gateway.v0` with `railpack-frontend` | Passes (Node.js sample) |
| zot with TLS (DNS SAN only) and htpasswd; anonymous request | Passes; anonymous gets `401` |
| Podman pull with `certs.d` CA and auth file, then run the image | Passes; the app answers |
| Pull user tries to push | Denied |
| Storage after the run | BuildKit cache 2.64 GB (mostly the Railpack toolchain); zot data 172 MB for 3 tags; the warm rebuild added no data |

## Consequences

- Git deployments do not depend on SSH, Docker, or the v4 build code.
- Builds of different workloads of one team share the BuildKit cache of a Node.
  Clusters belong to one team, and only users who can update a workload can
  start a build, so this matches the threat model.
- The registry Node is a single point for new builds and pulls. Running
  containers continue when it is offline.
- Every builder Node uses disk for the build cache. The limit and the Node page
  make this visible.

## Left open

- Private repositories, webhooks, preview builds, and build-time secrets.
- External registries and registry replication to a second Node.
- Builds for a different CPU architecture.
- The registry port and the exact `buildkitd.toml` defaults.
- Whether the sandboxed rootless mode needs both `systempaths=unconfined` and
  `/dev/fuse`. The test passed with both and did not check each one alone.
