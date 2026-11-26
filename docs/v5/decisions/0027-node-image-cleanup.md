# Decision 0027: Clean up unused images on Nodes

**Status: Accepted**

**Date: 2026-11-16**

## Context

A Node keeps every image that it pulls. Nothing removes them:

- Each deploy pulls the image of its revision. Old revisions stay on the Node.
- Each builder pin (`constants.node.builder_image`) and each `sentinel-host`
  install pulls a new image. The old ones stay.
- A build container that ends with the Node (for example a shutdown) stays as
  an exited `coolify-build-*` container.
- The journal grows to the systemd default of 10% of the disk.

On 2026-11-16, dev Node 1 had 3.0 GB free of 19 GB (84%). 4.4 GB were images
that no container used, and the journal used 916 MB. A stack deploy failed
because its image did not fit. A manual cleanup freed 2.6 GB of images and
750 MB of journal.

Other storage already has limits:

- The cluster registry kept `keep_tags` tags of each repository (decision
  0006). Decision 0030 removed it.
- A local build (`image.load.v1`) keeps the newest 10 local tags of each
  application on its build Node (`keep_tags`, set by Coolify). Since decision
  0030 these local images are also the source of the copies to other Nodes.
- BuildKit garbage collection limits the build cache (decision 0006, "Storage
  limits on builder Nodes").

v4 has Docker cleanup for servers (`CleanupDocker`): a schedule
(`docker_cleanup_frequency`), a disk threshold (`docker_cleanup_threshold`),
a kept number of rollback images, and options for volumes and networks.

## Decision

- **Sentinel command.** `image.prune.v1` (new capability) removes:
  - images that no container uses, in any state, except the images that
    Coolify sends in a keep list;
  - exited `coolify-build-*` containers whose build Coolify reports as
    finished;
  - dangling images.

  It does not touch volumes, networks, or the build cache.
  It returns the freed bytes and the removed image references.
- **Keep list.** Coolify sends:
  - the images of the deployed revision and of the last 2 earlier revisions of
    each workload on the Node (the v4 default of kept rollback images);
  - on a build Node, also the last 3 successful local builds of each
    workload;
  - the pinned infrastructure images: builder, BuildKit, ingress, and the
    current `sentinel-host`.

  A rollback to an older revision fetches its local image from a Node that
  keeps it (decision 0030), pulls it from its external registry by digest, or
  builds the same commit again (decision 0017).
- **When.** Like v4 for servers: a daily run, and a run when the disk use of
  the Node goes above a threshold (default 80%). The Node page has
  **Clean up now** and the settings. Each run is a Node operation with its
  result. A run waits while a build or a deploy runs on the Node.
- **Journal.** `PrepareNodeHost` writes a journald drop-in with
  `SystemMaxUse=500M`.

## Implementation notes

Built on 2026-11-16. The code changed these points of the proposal:

- **Infrastructure images.** BuildKit is pinned in Sentinel
  (`BUILDKIT_IMAGE`), not in Coolify, so Sentinel keeps it itself. (History:
  zot was pinned as `ZOT_IMAGE` until decision 0030 removed it.) The ingress is the Caddy binary of `coolify-ingress.service`, not a
  container, so it has no image to keep. Sentinel also keeps the newest
  `sentinel-host` image when Coolify does not send one. Coolify sends the
  pinned builder image and the `sentinel-host` image of the last upgrade of the
  Node (else `constants.sentinel.host_image`).
- **Keep list.** For each workload that runs or ran on the Node, Coolify keeps
  the revision of the last successful deploy or move on that Node, the 2
  revisions before it, and the newest revision. A build Node also keeps the
  last 3 successful local builds of each workload, so that other Nodes can
  fetch them (decision 0030). Images that a container uses, in any state,
  always stay.
- **Build containers.** Coolify sends the `coolify-build-<uuid>` names of the
  active builds of the Node. Sentinel removes every other `coolify-build-*`
  container that does not run.
- **Local builds.** A build without a registry (`image.load.v1`) has no copy in
  a registry. A rollback to such a revision fetches the image from a Node that
  keeps it (decision 0030). When the cleanup removed it on every Node, the
  rollback needs a new build.
- **Rollback.** A rollback deploys a local image when a Node still keeps it,
  and the deploy fetches it from that Node. The deploy uses the pull policy
  `missing`, so Podman starts the local image. (History: before decision 0030,
  a rollback checked the cluster registry first.)
- **No forced removal.** Sentinel never uses `--force`. An image that a
  container starts to use during the cleanup is skipped and listed in the
  result.
- **Settings.** `nodes.image_cleanup_frequency` (default `0 0 * * *`, in the
  server timezone) and `nodes.image_cleanup_threshold` (default 80). The
  scheduler runs the daily cleanup with the v4 Docker cleanup
  (`ScheduledJobManager`, type `docker-cleanups`). The threshold uses the disk
  use of the last `system.info` (every minute) and starts at most one cleanup
  per hour.
- **Waiting.** A queued cleanup waits while a build, deploy, move, or lifecycle
  action runs on the Node, for at most 2 hours. Sentinel also runs it in the
  serial command lane, so a deploy that starts later waits for it.
- **Server transfer.** Nodes are not in the scope of a server transfer
  (`ServerTransferCoverage::NOT_TRANSFERRED`), so the new columns need no
  transfer decision.

## Options compared

| Option | Good | Bad |
| --- | --- | --- |
| **A. A Sentinel command with a keep list from Coolify (proposed)** | Coolify knows the revisions and the rollback targets. One code path for the schedule, the threshold, and the button. Works without SSH. | A new Sentinel command and capability. |
| **B. A systemd timer with `podman image prune -a` on each Node** | No Coolify change. | Removes rollback images. No result in Coolify. No threshold. |
| **C. The v4 cleanup over SSH** | Reuses v4 code. | Nodes work through Sentinel, not SSH. Docker commands do not fit Podman. |

## Consequences

- The disk of a Node stops filling with old images.
- A rollback to a revision older than the kept ones can be slower, because it
  pulls the image again.
- Coolify needs the revision images of each Node to build the keep list.

## Not decided here

- Removal of unused volumes. A volume can hold data, so it needs its own
  decision.
- Size limits for container logs (the Podman log driver).
- A cluster-wide setting instead of one per Node.
