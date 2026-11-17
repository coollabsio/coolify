# Decision 0020: Build and place workloads by CPU architecture

**Status: Accepted**

**Date: 2026-11-11**

## Context

A cluster can have Nodes with different CPU architectures (`uname -m`, recorded
when Coolify inspects a Node). Decision 0006 leaves builds for a different CPU
architecture open. Emulation with QEMU is slow.

## Decision

- Coolify builds an image on a builder Node with the target CPU architecture. It
  does not use emulation.
- Coolify places a workload only on Nodes with the architecture of its image.

## Consequences

- A cluster with Nodes of two architectures needs a builder Node of each.

## Not decided here

- Multi-architecture images.

## Implementation

- Coolify uses the OCI names of architectures: `amd64`, `arm64`, `arm/v7`,
  `arm/v6`, `386`, `ppc64le`, `s390x`, and `riscv64`.
  `App\Support\CpuArchitecture::normalize()` changes the `uname -m` names
  (`x86_64`, `aarch64`, `armv7l`) to these names. It keeps an unknown name
  as it is. `Node::cpuArchitecture()` reads `metadata.arch`, which
  onboarding and each Flux system report write.
- An unknown architecture is null. A null architecture fits every Node, and
  a Node with a null architecture fits every image. Thus an old Node, a
  registry that is down, or a private image without a login does not stop a
  deploy. Coolify writes a warning to the log when it cannot read an image.
  The application page also shows a warning with the image and the reason
  (`ReadImageArchitectures::warning()`). The page reads only the cache. A
  later read that works removes the warning, and it ends after 7 days.
- A build records the architecture of its builder Node in
  `node_builds.architecture`. The migration sets this column for earlier
  builds from their builder Node. A built image (`localhost/<workload>:<build>`,
  or `<repository>@digest` in an external registry) has the architecture of
  the build with the same tag or digest. Revisions do not change, so the configuration hash and rollbacks
  stay the same.
- `ReadImageArchitectures` reads the architectures of other images from the
  registry manifest, with the Docker Registry v2 API over HTTPS:
  - An index gives each `linux` platform. Attestation entries
    (`unknown/unknown`) are ignored. A single manifest gives the
    architecture in its config blob.
  - A `401` gets one retry with a Bearer token from the realm of the
    challenge, or with Basic auth. The login is the registry credential of
    the workload, as for the pull. When a new application is created, there
    is no credential yet, so a private image is unknown.
  - Each URL, also of a token realm or of a redirect, must use HTTPS and
    must pass `SafeWebhookUrl`. Coolify follows redirects itself. Only the
    registry host gets the `Authorization` header.
  - The digest of a tag comes from a `HEAD` request, which Docker Hub does
    not count as a pull. It is cached for 10 minutes. The architectures of
    a digest are cached for 30 days, and a failure for 5 minutes. The cache
    keys contain the login identity (an HMAC), so teams do not share private
    results.
- `SelectBuilderNode` uses only builder Nodes with the architecture of the
  Nodes that run the workload. When there is no such builder, the build
  fails with the reason. A builder of unknown architecture fits.
- Placement checks the architectures in these places:
  - `CreateClusterDockerImageWorkload::placementNode()` takes the
    architectures. Automatic placement uses only Nodes that fit. A selected
    Node that does not fit gives the reason. The image, database, Git,
    inline Dockerfile, and stack create actions read the architectures
    before their transaction. A Git application uses
    `NodeCluster::builderArchitectures()`. A stack uses the architectures
    that all of its images have in common, because its services run on one
    Node. An image name with a variable is not counted.
  - `SyncNodeStackCompose` checks each service that it adds.
  - `CreateMoveOperation` checks the target for each service of the volume
    group. This includes recovery moves and volume restores to another
    Node.
  - `CreateDeploymentOperation` refuses a deploy to a Node that does not
    fit. This covers every other path: the reconciler, rollbacks, previews,
    a changed image, and the deploy step of a move.
  - The move dialogs of the application page and of the server page show
    each target with its architecture. A target that does not fit is
    disabled and shows the reason.
- The server tables of the Servers page and of the cluster page show the
  architecture of each Node as a small pill. The deployment target list of
  the create pages shows it under each server, and the architectures of the
  cluster under automatic placement.
- Sentinel needs no change. `buildctl build` in
  `scripts/builder-entrypoint.sh` gives no `--platform`, so BuildKit builds
  for its own Node. The builder and BuildKit images are multi-architecture
  (amd64 and arm64).

### Deviations

- The create pages do not hide a server when the image does not fit,
  because the image is not known when the list loads. The create action
  refuses with the reason.
- The reconciler reports a refused deploy each minute, like other refused
  deploys (pressure, capability).
