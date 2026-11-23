# Decision 0035: Concurrent builds on builder Nodes

**Status: Proposed**

**Date: 2026-11-23**

## Context

Decision 0006 set the capacity of a builder Node to one build at a time. A
team with many applications in one cluster waits for each build in turn.
v4 has a setting for this. v5 has none.

### v4

- `server_settings.concurrent_builds` (migration
  `2024_01_24_095449_add_concurrent_number_of_builds_per_server`) has the
  default 2. `Server\Advanced` validates `min:1` with no maximum. The API
  (`ServersController`) accepts the same field.
- `server_settings.dynamic_timeout` (default 3600 s) is the timeout of
  `ApplicationDeploymentJob`. `deployment_queue_limit` (default 25) refuses a
  new deployment when the server has 25 queued deployments.
- `queue_application_deployment()` in `bootstrap/helpers/applications.php`
  locks the application and server rows. It skips a deployment of the same
  commit, pull request, and image tag that is queued or in progress.
  `cancel_superseded_deployments()` cancels older webhook deployments, as the
  application settings allow.
- `next_queuable()` allows one deployment in progress for each application and
  pull request. Then it compares `active_builds_on_server()` with
  `concurrent_builds`. With build servers, it locks them and
  `least_busy_build_server()` takes the server with the fewest builds and a
  free slot, at random among equals. It stores the choice in `build_server_id`.
- `start_next_queued_deployments()` starts queued deployments in `created_at`
  order when a deployment ends or is cancelled.

### v5 today

- `StartNodeBuild` creates a `NodeBuild` with the status `queued`. A workload
  has one active build. A second push makes a build with `is_waiting`.
  `StartWaitingNodeBuild` starts it when the first build ends. A webhook build
  for the same commit returns the existing build. A newer push cancels older
  webhook builds by the v4 settings of the workload. A manual build returns the
  active build. So the v4 dedupe exists already.
- `BuildsNodeWorkloadImage` (the trait of `DeployDockerfileWorkloadJob` and
  `DeployRailpackWorkloadJob`) calls `SelectBuilderNode`. That action takes a
  builder Node of the workload's cluster with a running BuildKit, the
  capabilities of the build, the CPU architecture of the workload (decision
  0020), and `placeResource` for the user who started the build (roadmap G10).
  It takes only a Node with zero started builds. The limit of 1 is hard-coded.
- When all builders are busy, the job calls `release(15)`. `retryUntil()` is
  one hour. After one hour, the build fails. The order between workloads is
  the order in which Horizon takes the released jobs, not FIFO.
- `SelectBuilderNode` counts builds without a lock. `RunImageBuild` sets
  `node_id` and `started_at` later. Two jobs at the same time can both select
  the same Node.
- A running build holds one Horizon worker. `RunImageBuild` polls
  `container.list` and `container.logs` every 3 s until the build ends. The job
  timeout is 2400 s. `constants.node.build_timeout_minutes` (env
  `NODE_BUILD_TIMEOUT_MINUTES`, default 30) stops the build. The jobs use the
  `default` queue. A self-hosted Horizon has at most 4 processes
  (`HORIZON_MAX_PROCESSES`) for `high,default`.
- A cluster belongs to one team (`node_clusters.team_id`, `nodes.team_id`).

### Sentinel (`crates/control/src/builder.rs`)

- `builder.reconcile.v1` runs one long-lived `coolify-buildkitd` container
  (`--restart always`, rootless, uid range from 2,000,000,000). All `RUN`
  steps of all builds run inside this container.
- `image.build.v1` starts one `coolify-build-<id>` container. It clones the
  commit, runs `railpack prepare`, and calls `buildctl` through the socket.
- Neither container has `--memory`, `--cpus`, or `--pids-limit`.
- `buildkitd.toml` sets `gc = true`, `maxUsedSpace`, and `reservedSpace`
  (a quarter of `maxUsedSpace`, at most 10 GiB). `BuilderReconcileRequest`
  has `max_used_space_bytes`. 0 means 20% of the root disk. Coolify sends only
  `enabled` (`ReconcileNodeClusterNetwork::reconcileBuilder`), so every Node
  uses 20%. A change of the config hash replaces the BuildKit container.
- `remove_stale_outputs()` deletes the image archive of each build whose
  container is gone. Its comment says that this is safe because Coolify runs
  one build at a time. `RunImageBuild` removes the container before
  `image.load`. So with two builds on one Node, the start of the second build
  can delete the archive of the first build before Coolify loads it.

## Decision

### Setting

- Add `nodes.concurrent_builds` (unsigned tiny integer, default 1, range 1 to
  16). The **Builds** section of the Node page shows it. Only a user who passes
  `NodePolicy::update` (team admin or owner) can change it. The API uses the
  same rule.
- The default stays 1, not the v4 default 2. Every Node is a builder by
  default (decision 0006), and the same Node runs workloads. A user who adds a
  large builder raises the value.
- Add `node_clusters.build_timeout_minutes` (default 30, range 5 to 180). It
  replaces the env value. The job timeout is this value plus 10 minutes. This is
  the v4 `dynamic_timeout` for builds.

### Enforcement

Coolify enforces the limit. Sentinel refuses as a second check.

- Coolify claims a slot in one transaction. It locks the candidate builder
  Nodes (`lockForUpdate`, ordered by `id`), counts their active builds with a
  `node_id`, and sets `node_id`, `status = running`, and `started_at` on the
  build. Then it sends `image.build`. This removes the race.
- `builder.reconcile.v1` gets `max_concurrent_builds`. Before
  `image.build.v1` starts a container, Sentinel counts the containers with the
  `coolify.build` label. At the limit, it refuses with the fixed message
  "The builder is busy." Coolify puts the build back in the queue on this
  message.
- A new capability, `image.build.concurrent.v1`, means that Sentinel keeps the
  archive of a build until `image.load` or until the build is 2 hours old.
  Coolify uses a value above 1 only for a Node that reports this capability.
  For an older Node, the Node page shows the reason.

### Queue

- The queue is FIFO for each cluster, by `NodeBuild.id`. The rule of one active
  build for each workload stays. So one application cannot take all slots, and
  the applications of the team take turns. A preview is its own workload.
- A new action, `StartQueuedNodeBuilds`, takes a cluster. It reads the queued
  builds without a Node in FIFO order. For each build, it takes the least busy
  fitting builder with a free slot, with the existing filters: running BuildKit,
  capabilities, architecture, and `placeResource` of the requester. A build that
  no free builder fits stays in the queue. It does not block the builds after
  it. For example, an `arm64` build does not stop `amd64` builds.
- A builder above the memory or disk pressure threshold of the cluster gets no
  new build (the metadata that `EnsureNodeAcceptsDeployment` reads).
- The action runs when a build ends, fails, or is cancelled, when a builder is
  turned on, when a limit changes, and once each minute from the scheduler.
  A second run does nothing. A queued build does not hold a Horizon worker.
- The `release(15)` loop and the one-hour `retryUntil()` go away. A queued
  build waits without a time limit. A cluster accepts at most 50 queued builds.
  The 51st is refused with "The build queue of this cluster is full." This is
  the v4 `deployment_queue_limit`, as a fixed value.
- A waiting build shows "Waiting for a free builder server (position N of M)"
  or "Waiting for the running build of this application." The position comes
  from the FIFO query. It is not stored.
- Cancel of a queued build sets `cancelled` and runs `StartQueuedNodeBuilds`.
  Cancel of a running build stops its container, as today.

### Resource limits

All builds of a Node share `coolify-buildkitd`, so the limits go on that
container. Sentinel validates each value and gives a fixed `podman run`
option.

| Limit | Field of `builder.reconcile.v1` | Default | Who changes it |
| --- | --- | --- | --- |
| Memory of BuildKit | `memory_limit_bytes` → `--memory` | 50% of `memory_bytes` of the Node, at least 1 GiB | Team admin, Node page |
| CPU of BuildKit | `cpu_limit` → `--cpus` | none | Team admin, Node page |
| CPU weight of BuildKit | fixed `--cpu-shares 512` | half of the default | Nobody |
| Processes | fixed `--pids-limit 4096` | — | Nobody |
| Build cache | `max_used_space_bytes` (exists) | 20% of the root disk, at least 1 GiB | Team admin, Node page |
| Free disk | new `min_free_space_percent` → `minFreeSpace` | 10% | Nobody |
| Build container | fixed `--memory 1g --cpus 1 --pids-limit 1024` | — | Nobody |

- The CPU weight lets a build use idle CPU and gives workloads priority when
  the Node is busy.
- A change of a limit replaces `coolify-buildkitd`. Sentinel waits until no
  build container runs before it replaces it. Coolify starts no new build on
  that Node until the reconcile result arrives.

## Options compared

| Option | Result | Boundary |
| --- | --- | --- |
| Keep one build for each Node | No change; teams wait | None |
| **Coolify queue with a lock, plus a Sentinel refusal (chosen)** | Exact limit, FIFO, a second check on the Node | None |
| Coolify queue only | No refusal when two Coolify writers disagree | None |
| Sentinel refusal only | Jobs loop on refusals; no order; no position in the UI | None |
| Release loop with a higher limit | Race stays; no FIFO; fails after one hour | None |
| FIFO that ignores the requester | One queue is simpler | Crosses "member with access grants → servers outside the grants"; needs only a member with a write grant |
| Limits on each build container only | Does nothing for `RUN` steps, which run in BuildKit | None |
| Settings that a member can change | Fewer admin steps | Crosses "read-only member → write in the own team" |

## Security

- The queue, the BuildKit cache, and the limits stay inside one cluster, and a
  cluster belongs to one team. No option crosses from one team to another.
- `StartQueuedNodeBuilds` keeps the `placeResource` check for the user who
  started the build. Without it, a member with a write grant could run code on
  a builder Node outside the grants. A build without a user (webhook,
  reconciler) keeps the current rule of roadmap G10.
- Only a team admin or owner can change the limits (`NodePolicy::update`,
  `NodeClusterPolicy::update`). A team admin has root on the Nodes already, so a
  wrong value is not a boundary. Sentinel accepts only numbers in a range, never
  free text in an argument.
- The limits keep one build from using all memory and disk of a Node. This does
  not change a boundary: a member with write access on a resource has root on
  the server of that resource. It reduces the effect of an error.
- The "busy" refusal and the queue position show only builds of the same
  cluster, so they show no data of another team.
- Builds do not run on the Coolify host. This decision does not change that.

## Consequences

- One Sentinel version adds `image.build.concurrent.v1`, the busy refusal, and
  the new reconcile fields. Nodes on an older version keep one build.
- A running build still holds a Horizon worker for the whole build. A higher
  limit takes more workers. With the self-hosted default of 4 processes, the
  Node page warns when the sum of `concurrent_builds` of the instance is 3 or
  more.
- When the memory limit is reached, the kernel can stop `buildkitd`. Podman
  restarts it, and every running build on that Node fails. The build log shows
  the reason.
- Concurrent builds share the BuildKit cache. Builds of one team that use the
  same base image get a faster second build.
- `ServerTransferCoverage` lists `Node`, `NodeCluster`, and `NodeBuild` as not
  transferred, so the new columns need no transfer decision.

## Not decided here

- A follow step that does not hold a Horizon worker between polls.
- Priority of a manual build over a webhook build. The queue stays FIFO.
- Build slots that count by size (CPU or memory of each build).
- A per-project or per-environment share of the build slots.
- BuildKit `max-parallelism` for the steps of one build.
