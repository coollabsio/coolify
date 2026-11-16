# Decision 0026: Keep Horizon and move workload convergence into Sentinel

**Status: Accepted**

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
   - `Hello` carries the generation and the hash of the stored set (addition
     3), not the applied revision of each workload. Coolify compares them with
     Postgres and sends the full set when they differ.
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

## Additions after the review (2026-11-16)

These rules are part of the decision. They close gaps that the review found.

1. **Generation and fencing.** Each desired set has a generation number that
   only goes up. Sentinel stores it with the set. Without a connection,
   Sentinel only keeps the containers of the stored set running: it restarts a
   crashed one and starts them after a reboot, as the restore does today. It
   does not deploy a new revision or remove a container until it has the
   current generation from Coolify. Fencing after a recovery move
   (`FenceRecoveredNodeWorkloads`, decision 0014) stays in Coolify: the first
   set after a reconnect removes the moved workload, as the fence does today.
2. **Secrets and registry logins.** Secret values stay only in the Podman
   secret store of the Node (decision 0008). The stored desired set has
   references to them, not values. Without a connection to Coolify, Sentinel
   does not pull an image: a convergence that needs a pull waits for Coolify,
   and the registry login comes in the message of that change only.
3. **Changes, not full sets.** Coolify sends one change for each workload (put
   or remove, with the generation) and the hash of the full set of the Node.
   On connect, Sentinel sends its generation and hash in `Hello`. Coolify sends
   the full set only when they differ.
4. **Heartbeat hash.** Sentinel puts the generation and the hash of its set in
   each heartbeat. When they differ from Postgres, Coolify sends the set
   again. This replaces polling and finds a lost message.
5. **Network revision.** Each entry of the set has the network revision that
   the workload needs. Sentinel starts a new container of the entry only after
   it applied that network revision. The wait across Nodes before Coolify sends
   the entry stays in Coolify.
6. **Deploy history.** A deploy, rollback, stop, start, or move that a user
   starts keeps its `NodeOperation`. The status events of the revision update
   that operation and its log. A change that Sentinel makes alone (a restart
   after a crash) adds an event to the deployment history, not an operation.
7. **Work that moves to Sentinel.** Also the crash-loop detection of a new
   revision (`VerifyDeploymentConvergence`: a restart, `unhealthy`, or an exit
   before the container is healthy), the START backoff of
   `ReconcileNodeWorkloads::recentlyStarted()`, and the local-image rollback
   with pull policy `missing`. The image cleanup (decision 0027) adds the
   images of the stored set to its keep list in Sentinel. Coolify still sends
   the infrastructure images and the earlier revisions that a rollback needs,
   because the set has only the current revision of each workload.

## Implementation notes

Built on 2026-11-16 (Sentinel `7a509e9`, `7574bf0`, `6774450`; Coolify
`e0cdeeeb92`, `501805d0de`). The code changed or added these points:

- **Messages.** `WorkloadReconcileRequest` has the generation, the set hash,
  `full`, `puts`, `removes`, and `releases`. Each `WorkloadSetEntry` has the
  deploy request, the container name that it replaces, the operation UUID, the
  network revision, the stop timeout, the entry hash, and `redeploy`. Sentinel
  sends `WorkloadStatus` events with an event ID. Flux forwards them to Coolify
  as `workload_status`, and acknowledges them to Sentinel only after Coolify
  accepted them. Sentinel sends an unacknowledged event again every 10 s. The
  Flux route `/v1/commands/workload.reconcile` returns 409 without the
  capability.
- **Hash.** The set hash is the SHA-256 of the lines `<workload_id>
  <entry_hash>\n`, sorted by workload ID. Coolify computes the entry hash.
  Sentinel accepts a change only when its set then has the hash of the
  message. Otherwise Coolify sends the full set.
- **Confirmation (addition 1).** Sentinel cannot know if its stored generation
  is current. Each new connection starts unconfirmed. Coolify confirms with a
  message that has the same generation and hash and no change, or it sends the
  full set. Before that, Sentinel only starts the stopped containers of entries
  that it applied, with a backoff of 10 s that doubles up to 10 min. A deploy,
  stop, or removal waits for the confirmation.
- **Secrets (addition 2).** The stored set has the deploy request without the
  secret values and the registry login, in
  `/var/lib/coolify/workloads/desired-set.pb` with mode 0600. After a restart
  of Sentinel, an entry that needs
  them counts with an empty entry hash. The hash then differs, and Coolify
  sends the full set with the values.
- **Releases.** An entry can leave the set without a change of its containers.
  Coolify uses this when a workload goes back to the command path.
- **Published ports keep the command path.** A published port forwards to one
  container (decision 0016). Before the old container goes, every Node must
  forward the port to the new one. That is a network revision of the cluster,
  which Coolify applies across Nodes. Volumes, databases, and stack services
  converge in Sentinel.
- **Writable volumes.** A workload with a writable volume deploys in place
  (decision 0022): `podman run --replace` in its slot, so no older container
  runs. Sentinel tries a failed revision of such a workload again after 10 min.
- **Health.** Without a health check, a new container must run 10 s without a
  restart. With a health check, Sentinel waits for `healthy` for the start
  period plus (interval + timeout) × retries + 30 s, from 10 s to 300 s. A
  failed rolling deploy removes the new container, and the reason ends with
  "The previous version keeps running."
- **Backups.** A volume backup that stops its container holds it, so Sentinel
  does not start it during the backup.
- **Coolify rows.** `node_workload_set_entries` has one entry for each workload
  of a Node in the set. `nodes.workload_set_generation` and
  `nodes.workload_set_hash` have the generation that Coolify sent last and the
  hash of the desired set. A change that Sentinel made alone goes to
  `node_workload_events` and shows in the deployment history (addition 6).
- **Operations.** `DeployNodeWorkloadJob` and `ManageNodeWorkloadJob` write the
  entry, send the change, and wait for the status events. A move or a stack
  deploy that runs them inline gets the result. When Sentinel is not
  connected, the operation is `uncertain`, and Sentinel applies the change
  when it connects again.
- **Adoption.** `ReconcileNodeWorkloads` skips the workloads in the set. It
  gives a workload of the command path to Sentinel when its deployed revision
  is the latest revision and runs in a slot. No operation is created.
- **Fence.** Before Coolify compares or sends the set, it deletes the entries
  of workloads that are no longer on the Node. The first set after a reconnect
  then removes the moved workload. `FenceRecoveredNodeWorkloads` also still
  sends its removal. Both remove the same containers.
- **Order of a stack.** `DeployNodeStackJob` keeps the `depends_on` order of a
  deploy. After a reboot, Sentinel starts the stored containers without an
  order, as the Podman restart policy did before.
- **Both paths stay (step 5).** A new Node gets `sentinel-host` from
  `upstream.json` (1.1.7), which does not have the capability, and cluster 2
  of the dev instance runs 1.1.5. Workloads with published ports also need the
  command path. `ReconcileNodeWorkloads` and `RecoverStaleOperations` stay for
  these. Remove them when a released Sentinel has the capability and the
  published ports converge in Sentinel.
- **Checked on dev (cluster 1, Sentinel `main-6774450`).** A deploy, a rolling
  redeploy, a rollback with pull policy `missing`, stop and start, and a
  crash-looping revision that failed while the older container kept running.
  With Coolify stopped, Sentinel started a killed and a stopped container
  again, and every container after a reboot of a Node. When Coolify started,
  the hashes matched, no operations were added, and the deployment history
  showed the restarts. A recovery move with Node 1 offline, then Node 1 came
  back: the old copy was removed. A database, a stack with `depends_on` across
  two Nodes, a stop and start with a volume, and a move with a volume were
  healthy. Cluster 2 (Sentinel 1.1.5) deployed and stopped through the command
  path.

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
4. Extend the path to volumes, databases, and stack services. A workload
   with published ports keeps the command path (see the implementation notes).
5. Remove the PHP convergence path when the pinned Sentinel has the
   capability. Not done: see the implementation notes.
