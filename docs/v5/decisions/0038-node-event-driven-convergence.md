# Decision 0038: Podman events wake the Sentinel convergence

**Status: Accepted**

**Date: 2026-11-28**

## Context

The live test of decision 0037 with 3 Nodes showed that a crash moved the
forward of a published port about 16 s after the kill: up to 15 s until
Sentinel saw the crash, then 2 s until the network revision.

How Sentinel sees a container change today:

- **The converger** (`convergence::run`) runs one pass, then waits 2 s
  (`BUSY_INTERVAL`) when the pass has work, else 15 s (`IDLE_INTERVAL`). A
  change of the desired set wakes it at once (`set.changed()`). Nothing else
  wakes it. So a crashed container is started again, and its `stopped` status
  is sent, only at the next pass: up to 15 s later.
- **The connection** has a second, separate watcher
  (`watch_runtime_changes`). It runs `podman events --filter type=container`
  while Sentinel is connected to Flux, and sends `RuntimeChanged` for each
  event, at most one each 250 ms. An event within 250 ms of the one before is
  dropped, also when it is the last event of a burst.
- **Coolify** answers `runtime_changed` with a container refresh
  (`RefreshNodeContainersJob`). Before 2026-11-28 this refresh used the unique
  key of the refresh of each minute, which waits up to 59 s in the queue, so
  Coolify almost always dropped it. It now uses the key of the status-event
  refresh (fixed with this decision).

## Decision

1. **One event watcher for each Sentinel process.** Sentinel runs
   `podman events --filter type=container --format '{{json .}}'` from the
   start, not only while it is connected. The watcher wakes the converger and,
   while a connection is open, sends `RuntimeChanged`. The connection does not
   start its own watcher.
2. **The events that wake the converger.** Only events of a container with the
   Coolify labels of a workload, with the statuses `died`, `exited`, `stop`,
   `start`, `remove`, `oom`, and `health_status`. Other events (`exec`,
   `attach`, `create`, image and volume events) do not wake it.
3. **Coalesce, do not drop.** Events within 250 ms make one wake-up and one
   `RuntimeChanged` at the end of the burst (trailing edge). The last event of
   a burst is never lost.
4. **The interval stays as a fallback.** The pass of each 15 s (2 s while
   busy) stays. An event can be lost: Podman restarts, the events backend
   (journald or file) rotates, or the watcher restarts. The pass then corrects
   the state, as today.
5. **No change to the backoff.** A woken pass uses the same start backoff,
   settle time, and health rules. A container in a crash loop is not started
   faster than the backoff allows. Only the detection is faster.
6. **No new capability.** The only protocol change is an optional field in
   `system.info` (answer 3). A Sentinel without this change works as today.

### Before and after (crash of a container on Node 1, published port)

| Step | Before | After |
| --- | --- | --- |
| Sentinel sees the crash | at the next pass, 0 to 15 s | at the event, under 1 s |
| Sentinel starts the container again or sends `stopped` | at that pass | in the woken pass, under 1 s, then by the backoff |
| Coolify refreshes the inventory | after `stopped` (and `runtime_changed` was dropped behind the minute refresh) | after `runtime_changed`, 1 to 2 s |
| Network revision of the cluster | about 16 s after the crash | about 2 to 3 s after the crash |

## Options compared

| Option | Good | Bad |
| --- | --- | --- |
| **A. Events wake the converger, the interval stays (proposed)** | Fast. Uses the event stream that Sentinel already reads. The pass stays the source of truth. | One more task that must restart the `podman events` process when it ends. |
| **B. A shorter interval (for example 2 s)** | One constant. | `podman ps --all` each 2 s on each Node costs CPU and fills the Podman log. Still up to 2 s late. |
| **C. The Podman REST API events stream** | No child process. | Needs the Podman socket and an HTTP client in the converger. Sentinel uses the CLI for everything else. |

## Consequences

- A crash is seen in under 1 s. Restarts follow the backoff, as before.
- A published port moves to another Node in about 3 s (decision 0037).
- Deploys that wait for a container (settle time, health) see the result
  sooner, because a `start` or a `health_status` event wakes the pass.
- A Node with many short-lived containers gets more passes. The coalescing and
  the label filter limit this.

## Answers to the open questions (2026-11-28)

1. **`health_status` wakes the converger only on a change.** Podman sends an
   event for each health check. Sentinel keeps the last health status of each
   container and wakes the converger only when the new status differs, for
   example `starting` to `healthy`, or `healthy` to `unhealthy`. If the Podman
   version of the Nodes does not send `health_status` events, the pass of each
   15 s sees the health, as today. The build checks this on the Podman version
   of the Nodes first.
2. **Only containers with the Coolify labels wake the converger.** The
   converger ignores other containers. `RuntimeChanged` still goes to Flux for
   every container event, coalesced, so the container list of Coolify stays
   current.
3. **Sentinel reports when it cannot watch the events.** `system.info` gets an
   optional field `runtime_events` (`watching` or `unavailable`), and Sentinel
   logs the reason. The Node page shows a warning: "Crash detection is slow:
   Podman events are not available". An older Coolify or Flux ignores the
   field.

## Not decided here

- Faster restarts in a crash loop (the backoff values).
- A Sentinel restart without a lost event (the pass after the start already
  corrects the state).
