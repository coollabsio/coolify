# Decision 0006: Build images on builder Nodes

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
  in a registry that every Node can reach. (Decision 0030 changed this: a Node
  now fetches a local image from a peer Node.)
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
   BuildKit on the same Node. With the `LOCAL` image storage, it loads the
   image into the Podman store of that Node (`image.load.v1`). With the
   `EXTERNAL` image storage, it pushes the image to the registry of the team
   and writes the digest from the `buildctl --metadata-file` output.
4. When the build succeeds, Coolify creates a `NodeWorkloadRevision`. Its
   `image` is `localhost/<workload>:<build>` for a local image, or
   `<repository>@sha256:<digest>` for the tag `<repository>:<workload>-<build>`
   in the external registry. Then the existing `CreateDeploymentOperation`
   flow deploys it.

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
- `builder.reconcile` is idempotent and converges `buildkitd` and its
  configuration. It has only `enabled` and `max_used_space_bytes`. (History:
  `registry.reconcile` converged zot until decision 0030 removed it.)

### Image storage

- Each cluster has an image storage: where its builds go
  (`node_clusters.image_storage`, decision 0030):
  - On the servers (`LOCAL`, the default): each build stays in the Podman
    store of its build Node as `localhost/<workload>:<build>`. Other Nodes
    fetch it from a peer Node (decision 0030).
  - External registry (`EXTERNAL`): one repository of the team, for example
    `ghcr.io/acme/coolify-builds`, with an optional team registry credential
    that can push and pull. Each build is the tag `<workload>-<build>`. One
    repository works with registries that limit the number of private
    repositories. The revision pulls `<repository>@<digest>` with the same
    credential. Coolify does not delete old tags; the registry owns
    retention. Needs `image.build.external_image.v1`: `image.build` accepts
    an `image` from the team, with `image_username` and `image_password` for
    its push.
- A cluster with one Node needs no choice. Its builds stay on that Node,
  unless the user selected an external registry.
- The onboarding of the second server shows the choice one time. Builds do
  not fail while no choice is made (decision 0030).
- A change of the storage does not copy images. The next deploy of each Git
  application builds into the new storage.

### Cluster registry (removed by decision 0030)

History: until decision 0030, a cluster with two or more Nodes could run one
zot registry on one of its Nodes (`BUILT_IN`), at
`registry.cluster.coolify.internal:5443`, with its own CA, htpasswd users for
push and pull, and a retention of the last 10 tags of each workload. Coolify
moved it to another Node after 10 minutes offline, and the move lost its
images. Decision 0030 removed zot, `registry.reconcile.v1`,
`registry.inspect.v1`, the `node_registries` table, port 5443, and the
registry section of the cluster page. Nodes now fetch local images from each
other.

### Storage limits on builder Nodes

- BuildKit garbage collection uses `reservedSpace`, `maxUsedSpace`, and
  `minFreeSpace` in `buildkitd.toml`. The default `maxUsedSpace` is 20% of the
  disk.
- The Node page shows the disk use of the build cache.

## Evidence

On 2026-11-02, a test on a development worker Node (Ubuntu 24.04.5, Podman
4.9.3, AppArmor on, `apparmor_restrict_unprivileged_userns=1`, 2 vCPU, 3 GiB)
used BuildKit v0.34.1, Railpack v0.43.0, and zot v2.1.23. The zot rows are
historical: decision 0030 removed zot.

| Check | Result |
| --- | --- |
| Rootless BuildKit with the default Podman security options, with or without the process sandbox | Fails: `failed to share mount point: /` |
| Rootless BuildKit with seccomp and AppArmor unconfined | Fails: user namespace blocked by `apparmor_restrict_unprivileged_userns` |
| Rootless BuildKit with a `userns` AppArmor profile and seccomp unconfined, process sandbox on | Passes: Railpack build and push in 28 s with a cold cache |
| The same with the default seccomp profile | Fails: the build's `RUN` step exits with code 1 |
| Rootful BuildKit with `--privileged` | Passes: Dockerfile build 4 s, Railpack build 36 s cold and 1 s warm |
| `railpack prepare` + BuildKit `gateway.v0` with `railpack-frontend` | Passes (Node.js sample) |
| (Historical) zot with TLS (DNS SAN only) and htpasswd; anonymous request | Passes; anonymous gets `401` |
| (Historical) Podman pull from zot with `certs.d` CA and auth file, then run the image | Passes; the app answers |
| (Historical) zot pull user tries to push | Denied |
| Storage after the run | BuildKit cache 2.64 GB (mostly the Railpack toolchain); zot data 172 MB for 3 tags (historical); the warm rebuild added no data |

## Consequences

- Git deployments do not depend on SSH, Docker, or the v4 build code.
- Builds of different workloads of one team share the BuildKit cache of a Node.
  Clusters belong to one team, and only users who can update a workload can
  start a build, so this matches the threat model.
- A local image is only on the Nodes that built, run, or keep it. When all of
  them are offline, a Git application must be built again (decision 0030).
- Every builder Node uses disk for the build cache. The limit and the Node page
  make this visible.

## Before release

When this decision was written, cluster applications used the cluster
registry (zot, removed by decision 0030) for built images, and pulled other
images only from public registries. These items must be done
before v5 ships:

- [x] **Private registry pulls.** v4 runs `docker login` on each server over SSH
  (Server → Docker Registries). Nodes have no Docker and no SSH deploy path, so
  v5 needs its own flow: registry credentials for each team in Coolify
  (encrypted), and an optional registry auth on `workload.deploy` that Sentinel
  passes to Podman as a temporary `--authfile` and never journals. The v4
  logins cannot be imported, because they exist only on each v4 server.
- [x] **Push built images to an external registry** (the v4 "Docker registry
  image name" setting). This is now the `EXTERNAL` image storage.
- [ ] **Release Sentinel and Flux** with the build and registry commands. The
  latest Sentinel release does not have them, and **Upgrade Sentinel** installs
  that release. Add the `coolify-builder` image to the Sentinel `release.yaml`
  workflow; today only `release-main.yaml` publishes it.

## Amendment (2026-11-16): Static serves files only

A Static source is built by the Railpack job, but Railpack selects the provider
by itself. Railpack 0.43.0 selects Node when the repository has a
`package.json`, also with `index.html` or `public/`, so a Static application
ran `npm install`, `npm run build`, and `npm run start` (tested on dev: the
container crash-looped on a missing `server.js`).

- **Field.** A Static application has **Publish directory**
  (`node_workloads.publish_directory`), relative to the base directory, on the
  create pages of a Git source and on the **General** page. Empty
  serves the base directory, so every file in it can be downloaded; the help
  text says this.
- **Protocol.** `ImageBuildRequest.static_root` (field 18) and the capability
  `image.build.static.v1` (Sentinel `be0c977`, fix `ec64ac1`). Coolify sends
  `static_root` for every Static build, `.` for an empty field, and needs the
  capability on the builder Node. A Node with `sentinel-host` 1.1.7 cannot
  build Static.
- **Builder.** The entrypoint checks that the folder exists and has files,
  writes a Railpack config outside the source with `"provider": "staticfile"`
  merged into the `railpack.json` of the source (the provider of Coolify
  wins), passes it with `--config-file ../…`, and sets
  `RAILPACK_STATIC_FILE_ROOT`. Railpack makes each `--env` variable a secret
  of the plan, so BuildKit gets that value as a secret too.
- **Limit.** With the staticfile provider, Railpack ignores
  `buildAptPackages` of a `railpack.json`.
- **Verified on dev.** `index.html` + `package.json` with a start script:
  Caddy, no npm, `/index.html` 200. With `dist`: `/` serves `dist/index.html`
  and `/package.json` is 404. A missing folder fails the build with "the
  publish directory missing does not exist".

## Left open

- Private repositories, webhooks, preview builds, and build-time secrets (see
  decisions 0011 and 0012).
- A second copy of each local image on another Node. (Peers that serve
  their Podman store are decided in decision 0030.)
- Builds for a different CPU architecture (see decision 0020).
- The exact `buildkitd.toml` defaults.
- Whether the sandboxed rootless mode needs both `systempaths=unconfined` and
  `/dev/fuse`. The test passed with both and did not check each one alone.
