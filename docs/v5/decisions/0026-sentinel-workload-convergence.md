# Decision 0026: Keep Horizon and move workload convergence into Sentinel

**Status: Proposed**

**Date: 2026-11-16**

## Context

Coolify runs its background work on Laravel Horizon (Redis). The long-term goal
is to write Coolify in Rust. This raised a question: should v5 get a new
Rust-based queue now?

v5 already has most of a desired-state model for workloads:

- Revisions are immutable. Each one has a UUID, a `configuration_hash`, and the
  image digest (decisions 0006 and 0017).
- Each workload has a `desired_state` (`running`, `stopped`, `removed`) and
  its Nodes (`node_workload_nodes`).
- Sentinel dials Flux and keeps one gRPC stream open (`Agent.Stream`). Sentinel
  journals each command by its ID, so a repeated command does not run two times.
- Networks and the firewall already converge in Sentinel. Coolify sends a
  revision and a hash. Sentinel rejects older revisions and reports
  `applied_revision` and drift. Ingress routes work the same way through
  Corrosion: Coolify writes intent only (decision 0005).

For workloads, the convergence loop is in Laravel. `RefreshNodeContainersJob`
runs `ReconcileNodeWorkloads` each minute. It compares the container inventory
with the latest revision, and it creates a `NodeOperation` and dispatches
`DeployNodeWorkloadJob` or `ManageNodeWorkloadJob`. Sentinel only runs the
imperative command. This has these costs:

- The backoff for failed revisions, crash-loop detection, busy checks, and
  stale-operation recovery are PHP code that guesses the Node state from an
  inventory that can be one minute old.
- A Node can restart or catch up only when Coolify, Horizon, and Flux run.
- More v5 logic goes into Horizon jobs, which must be rewritten for a Rust
  Coolify.

Laravel job payloads are PHP `serialize()` objects. Rust cannot read them. A new
queue does not fix this. Only the jobs that read and write explicit rows can
move to another runtime.

Legacy servers stay supported permanently (decision 0004) and are in active
development. They are managed over SSH. Their container Sentinel collects
metrics only.

## Decision

1. **Keep Horizon.** v5.0 gets no new queue, in Rust or in another language.
2. **Jobs carry only IDs.** A job gets the ID of an operation, a revision, or a
   workload, and reads the state from Postgres. New code must not keep state in
   a serialized job. Existing Node jobs already do this, for example
   `DeployNodeWorkloadJob::dispatch($operation->id)`.
3. **Sentinel converges workloads.** Coolify writes the desired state. Sentinel
   applies it and reports the result:
   - Coolify sends the **desired set** of a Node through Flux: for each
     workload, the revision UUID, the `configuration_hash`, the desired state,
     and the revision configuration. It sends the set when it changes and when
     the Node connects. Coolify does not poll, and Sentinel does not poll.
   - Sentinel stores the desired set locally. It converges also when Flux or
     Coolify is down.
   - Sentinel reports a status for each workload and revision: `progressing`,
     `healthy`, `failed` (with the reason), `stopped`, or `removed`. Coolify
     records these events in the deployment history.
   - `Hello` carries the applied revision of each workload, so Coolify sends
     only what is missing after a reconnect.
   - The capability `workload.reconcile.v1` gates the new path. Sentinels
     without it keep the command path.
4. **Sentinel owns what happens on one Node:** the deploy without downtime
   (decision 0022), crash-loop detection and backoff, the rule that a failed
   revision is not retried while an older container runs, and the start of a
   stopped container.
5. **Coolify keeps what needs the full cluster:**
   - placement (decision 0014);
   - the order across Nodes, and the wait until every Node has applied the
     network revision before a new container starts (decision 0022);
   - the `depends_on` order of a stack deploy (decision 0023);
   - builds (decision 0006);
   - one-shot commands: exec, terminal, logs, backups, restores, volume copies,
     and moves.
6. **Secret values** go only in the message to the Node that runs the
   workload, as with `workload.secrets.v1` today. They are not stored in
   Corrosion, and Flux does not store them.

## Options considered

- **A new Rust queue for the existing jobs.** Rejected. PHP workers would read
  from a new broker with no benefit, or Rust workers would need the full
  Laravel model layer. It also removes retries, unique jobs, the Horizon
  dashboard, and `Queue::fake()`, and adds a service to each installation.
- **The desired state in Corrosion, as for ingress.** Rejected. Ingress Nodes
  need all routes, but a workload spec concerns only its own Nodes. Corrosion
  would copy every spec to every Node of the cluster.
- **Sentinel polls an HTTP endpoint of Coolify.** Rejected. The Flux stream is
  already open. Polling adds latency and load and gives no benefit.
- **Flux stores the desired state.** Not now. Flux stays a stateless relay, and
  Postgres is the only source of truth.

## Consequences

- Most of `ReconcileNodeWorkloads`, its backoff rules, and a part of
  `RecoverStaleOperations` go away for Nodes with `workload.reconcile.v1`.
- A Node keeps its workloads converged while Coolify is down or upgrades.
- Restarts and catch-up after a reconnect do not wait for the inventory of the
  next minute.
- More v5 logic is in Rust, and less depends on Horizon.
- The protocol gets new messages. This costs less before v5 is stable
  (decision 0015). Coolify pins the Sentinel version (decision 0013), so the
  command path for old Sentinels can be removed soon after the pinned version
  has the capability.
- During the change, both paths exist and need tests.

## Not decided here

- A Rust rewrite of Coolify, and when Horizon is replaced.
- The exact protobuf messages.
- Convergence for legacy servers. They can use the same terms (revision,
  desired state, applied revision, status values), with Laravel converging over
  SSH. That needs its own decision.
- Automatic placement and failover (decision 0014).

## Implementation

1. Add the desired-set and status messages and the `workload.reconcile.v1`
   capability to the protocol.
2. Sentinel: store the desired set and converge stateless workloads without
   volumes first.
3. Coolify: send the desired set on each change and on connect, record the
   status events, and skip Nodes with the capability in
   `ReconcileNodeWorkloads`.
4. Extend the path to volumes, databases, and stack services.
5. Remove the PHP convergence path when the pinned Sentinel has the
   capability.
