# Decision 0035: Concurrent builds on builder Nodes

**Status: Accepted**

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
- Fixed on 2026-11-23: `SelectBuilderNode` counted builds without a lock,
  and `RunImageBuild` set `node_id` and `started_at` later, so two jobs at the
  same time could both select the same Node. Now `SelectBuilderNode::claim()`
  locks the Nodes of the cluster (`lockForUpdate`, ordered by `id`), selects
  the Node, and sets `node_id` and `started_at` of the build in one
  transaction. The limit is still one build on each builder Node.
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
- Fixed on 2026-11-23 (Sentinel `57e889c`): `remove_stale_outputs()`
  deleted the image archive of each build whose container was gone, and
  `RunImageBuild` removes the container before `image.load`. So the start of
  a second build could delete the archive of the first build before Coolify
  loaded it. Now a build removes only its own old output, and the outputs of
  other builds only one day after they ended without a load.

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

- Coolify claims a slot in one transaction, as `SelectBuilderNode::claim()`
  does today for the limit of 1. It compares the count with
  `concurrent_builds` instead of 0.
- `image.build.v1` gets `max_concurrent_builds` (changed on 2026-11-28, see
  the implementation notes). Before Sentinel starts a container, it counts the
  running containers with the `coolify.build` label. At the limit, it refuses
  with the fixed message "The builder is busy." Coolify puts the build back in
  the queue on this message.
- A new capability, `image.build.concurrent.v1`, means that Sentinel refuses
  at the limit. Sentinel `57e889c` and later already keep the archive of
  another build until its load or for one day. Coolify uses a value above 1
  only for a Node that reports this capability. For an older Node, the Node
  page shows the reason.

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
| Memory of BuildKit | `memory_limit_bytes` → `--memory` and `--memory-swap` | 50% of the memory of the Node, at least 256 MiB | Team admin, Node page |
| CPU of BuildKit | `cpu_limit_millis` → `--cpus` | none | Team admin, Node page |
| CPU weight of BuildKit | fixed `--cpu-shares 512` | half of the default | Nobody |
| Processes | fixed `--pids-limit 4096` | — | Nobody |
| Build cache | `max_used_space_bytes` (exists) | 20% of the root disk, at least 1 GiB | Team admin, Node page |
| Free disk | fixed `minFreeSpace = "10%"` | 10% | Nobody |
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
| Release loop with a higher limit | No FIFO; fails after one hour | None |
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

## Answers to the open questions (2026-11-26)

The user accepted the decision with these answers. They have priority over
the text above.

- **Default.** `concurrent_builds` is 1. Small Nodes run out of memory with
  2 builds.
- **Queue cap.** A fixed limit of 50 waiting builds for each cluster.
- **Queue worker.** A waiting build does not hold a Horizon worker. The worker
  is released and the build is dispatched again when a slot is free.
- **BuildKit memory.** The limit of `coolify-buildkitd` is 50% of the memory of
  the Node.

## Not decided here

- Priority of a manual build over a webhook build. The queue stays FIFO.
- Build slots that count by size (CPU or memory of each build).
- A per-project or per-environment share of the build slots.
- BuildKit `max-parallelism` for the steps of one build.

## Implementation notes (2026-11-28)

Built in Coolify and in Sentinel `a7a95d5` (`image.build.concurrent.v1`).

### Changes to the decision

- **The limit goes with each build, not with the builder reconcile.**
  `max_concurrent_builds` is a field of `image.build.v1`. Sentinel checks the
  value at the start of each build, so the check always uses the current
  value of Coolify. A change of the slots then does not replace BuildKit and
  does not wait for a network revision.
- **The BuildKit memory has no minimum of 1 GiB.** The answer of 2026-11-26
  sets 50% of the Node. A Node with 1 GiB would give all its memory to
  BuildKit with a minimum of 1 GiB. Sentinel reads the memory of the host and
  accepts a value from 256 MiB to the memory of the Node. `--memory-swap` has
  the same value, so BuildKit does not use swap.
- **The free disk is fixed.** `buildkitd.toml` always has
  `minFreeSpace = "10%"`. No request field exists.
- **Not built in this step:** `node_clusters.build_timeout_minutes`. The job
  timeout stays 2400 s, and `NODE_BUILD_TIMEOUT_MINUTES` stops the build.

### Coolify

- `nodes.concurrent_builds` (default 1, 1 to 16), `build_memory_limit_bytes`,
  `build_cpu_limit_millis`, and `build_cache_bytes` (null: the default of
  Sentinel). `node_builds.node_cluster_id` and `builder_queued_at` hold the
  queue. `Node::buildSlots()` gives 1 for a Node without
  `image.build.concurrent.v1`.
- `SetNodeBuildLimits` changes the settings, for the **Builds** section of the
  Node page and `PATCH /api/v1/nodes/{uuid}/builds`. It authorizes
  `NodePolicy::update`. A changed BuildKit limit queues a network revision.
  The Node page warns when the sum of `concurrent_builds` of the instance is 3
  or more.
- `StartQueuedNodeBuilds` is the 1 function that starts waiting builds. It
  locks the cluster row, then the Nodes of the queue in `id` order. For each
  build in FIFO order (`id`), it runs `SelectBuilderNode` again, so the
  architecture, the capabilities, and `placeResource` of the requester apply
  when the build starts. A busy build stays in the queue. Another refusal
  fails the build and sends the notification. A claimed build gets `node_id`
  and `started_at`, and its job is dispatched again. The build job joins the
  queue in the same transaction (`$enqueue`), so no claim can come between.
- `SelectBuilderNode` takes the least busy builder (active builds divided by
  slots) with a free slot. It skips a builder with
  `builder_replace_pending` and a builder above the memory or disk pressure
  threshold of the cluster. Missing or old resource data does not stop builds.
  `claim()` is removed.
- The build job has `tries = 1` and no `retryUntil()` or `release()`. A build
  without a free slot writes "Waiting for a free builder server (position N of
  M)." to its build log and ends its job. A build behind a running build of its
  application writes "Waiting for the running build of this application." The
  log line has the position when the build joins the queue. The `Duration`
  column of the deployment history shows the current position ("Waiting 1 of
  2") from `NodeBuild::builderQueuePosition()`, the same function as the log
  line, and updates when the page polls.
- On "The builder is busy." from Sentinel, the job puts the build back in the
  queue at its old position and writes the refusal to the build log. The
  scheduler tries again within 1 minute, so a refusing builder does not loop.
- `StartQueuedNodeBuilds` runs after each build ends or fails, after a cancel
  (after the container stopped), after a builder reconcile with a running
  BuildKit, after a change of the settings, and each minute from
  `ProcessNodeBuildQueueJob`.
- `ProcessNodeBuildQueueJob` also recovers stale builds. `RunImageBuild`
  writes a heartbeat (`updated_at`) each 30 s while it follows the build. A
  running build without a heartbeat for 20 minutes, or a claimed build whose
  job did not start in 120 minutes, fails with "The build job stopped before
  the build ended." Its container is stopped and the next build starts.
- `StartNodeBuild` refuses a new build when its cluster has 50 builds without
  a builder (both queues). The webhook response, the API, and the UI show
  `StartQueuedNodeBuilds::QUEUE_FULL`. The builds that a newer push cancels
  do not count.
- `ReconcileNodeClusterNetwork::builderRequest()` sends
  `max_used_space_bytes`, `memory_limit_bytes`, and `cpu_limit_millis` to a
  Node with the capability (0: the default of Sentinel). The result stores
  `builder_replace_pending` and `builder_memory_limit_bytes`. When the last
  build of a pending Node ends, `StartQueuedNodeBuilds` queues a network
  revision, so the new limits apply.
- A stack deploy (`DeployNodeStackJob`) holds no worker while the builds of
  its services wait. It links its builds to its `node_stack_deployments` row
  (`node_builds.node_stack_deployment_id`) and ends. The end of the last build
  of the deploy (success, failure, cancel, or a stale build) dispatches the job
  again with the same row (`DeployNodeStackJob::resumeAfter()`). The resumed
  run keeps the commit of the first run, does not read the Compose file again,
  and deploys the services in `depends_on` order. A failed or cancelled build
  fails the deploy. `deploy_status` stays `deploying` while the builds wait, so
  a second deploy is refused, and `deploy_notified_at` gives 1 notification.
  `ProcessNodeBuildQueueJob` resumes a deploy whose dispatch was lost, for
  example when the last build ended while the first run still held the unique
  lock of the job.

### Sentinel

- `builder::start()` refuses with "The builder is busy." when
  `max_concurrent_builds` is above 0 and the Node runs that number of
  `coolify.build` containers. A retry of the same build ID is not refused.
- `coolify-buildkitd` runs with `--memory`, `--memory-swap`,
  `--cpu-shares 512`, `--pids-limit 4096`, and `--cpus` when set. A build
  container runs with `--memory 1g --cpus 1 --pids-limit 1024`.
- The limits are part of the configuration hash. When the hash changes while
  builds run, Sentinel keeps the old BuildKit and returns
  `replace_pending: true`.
- The outputs of local builds stay separate for each build ID (Sentinel
  `57e889c`).

### Live check on the dev cluster (2026-11-28)

With 2 Nodes, Sentinel `main-a7a95d5`, and the builder role only on node-1:

- With 1 slot, 3 builds of 3 applications: 1 ran, 2 waited with positions
  1 and 2, then they ran one after the other.
- With 2 slots, 2 builds ran at the same time on node-1, and both images
  deployed.
- A cancel of a running build started the waiting build. A cancel of the
  first waiting build moved the second to position 1. A failed build started
  the next build in the same second.
- A build without a heartbeat for 25 minutes failed from the scheduled job,
  and the waiting build started.
- With a foreign `coolify.build` container on node-1, Sentinel refused the
  build 2 times. The build waited and ran when the container was gone.
- `podman inspect coolify-buildkitd`: memory 1524551680 (50% of 3049103360),
  CPU shares 512, pids limit 4096. `buildkitd.toml` has `maxUsedSpace`
  (20% of the disk) and `minFreeSpace = "10%"`.
- A member could not change the setting, an admin could, and 17 was refused.
- With 49 fake waiting builds in a rolled-back transaction, the 50th build
  was accepted and the 51st was refused with the full-queue message.
- A Git stack with 2 `build:` services (`web2` depends on `web1`) and 1 slot:
  `web2` waited at position 1, and no `DeployNodeStackJob` was reserved in
  Horizon while it waited (only the build job of `web1`). The end of the build
  of `web2` resumed the deploy with the same row. The stack ended `deployed`,
  and `deploy_notified_at` was set once. A cancel of the waiting build of
  `web2` ended the stack `failed` ("The build of web2 was cancelled. Nothing
  was deployed."), with 1 failure mail.
- The deployment history of an application shows "Waiting 1 of 2" and
  "Waiting 2 of 2" for 2 waiting builds. When the first build ended, the
  second showed "Waiting 1 of 1".
