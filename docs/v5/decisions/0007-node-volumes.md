# Decision 0007: Keep each volume on one Node and copy it on a move

**Status: Accepted**

**Date: 2026-11-02**

## Context

Cluster applications run on Podman Nodes and can move between the Nodes of a
cluster. Applications and databases need persistent data that stays with them
when they move.

A cluster can have one Node. This is the default. A storage design that needs
two or more Nodes, a separate block device, or a kernel module cannot be the
default.

We compared these models:

- Uncloud keeps each volume on one machine. Its scheduler places a service on
  the machine that has the volume, and creates a missing volume on one machine
  only. It does not replicate volumes.
- k3s uses `local-path-provisioner` by default: a directory on one Node, with no
  replication. Replication comes from add-ons such as Longhorn, OpenEBS,
  Piraeus (DRBD), or Rook (Ceph). All of them need Kubernetes.
- DRBD 9 gives synchronous replication without Kubernetes. It needs a DKMS
  kernel module, a block device for each replica, and three Nodes for quorum.
- Ceph needs three or more Nodes and much memory. GlusterFS is no longer
  maintained upstream.

The current move (`workload.move.v1`) is make-before-break: it deploys the
target before it stops the source. With a volume, this makes two writers on
two copies of the data.

The v4 volume clone (`VolumeCloneJob`) copies data across servers through the
Coolify host. In v5 this would send the data two times through the control
plane.

## Decision

### One volume, one Node

- A volume belongs to one workload and lives on exactly one Node. There is no
  replication and no shared storage in this decision.
- Coolify stores the volume in a `NodeVolume` record. `node_id` is the
  authority for the location of the data. Coolify owns the identity and the
  desired configuration. Sentinel creates and attaches the volume on the Node.
- The Podman volume name is `coolify-vol-<uuid>`, the same on every Node.
  Sentinel sets the labels `coolify.managed=true` and `coolify.volume=<uuid>`. Podman cannot change volume labels after creation,
  so the state of a volume and of each copy is stored only in Coolify.
- The workload revision stores its mounts: volume UUID, container path, and
  read-only flag. The mounts are part of the configuration hash.
- `workload.deploy.v1` receives the mounts. Sentinel creates a missing volume
  idempotently before it starts the container.
- A workload with a volume can deploy only on the Node of the volume. A
  read-write volume allows one container only.
- Sentinel reports its volumes with the container inventory. Coolify shows
  missing and orphan volumes. Coolify never deletes a volume automatically.
- `volume.delete.v1` is an explicit operation. Sentinel rejects it when a
  container uses the volume.
- Host bind mounts stay denied. Configuration files are not volumes: Coolify
  stores their content and Sentinel writes them for each revision, so they move
  with the revision.
- System volumes of Coolify components (for example the build cache and the
  registry of [decision 0006](0006-node-image-builds.md)) are not
  `NodeVolume` records.

### Move a workload that has volumes

A move of a workload with one or more volumes is break-before-make. A move of
a workload without volumes keeps the make-before-break flow of
[decision 0005](0005-node-ingress.md).

The data goes directly from the source Node to the target Node over the
WireGuard mesh. It does not go through Flux or Coolify.

1. **Preflight.** Both Nodes are in the same cluster and report
   `volume.transfer.v1`. The target has enough free disk for the used size of
   each volume.
2. **Prepare.** The source Sentinel starts the transfer service. The target
   Sentinel creates each volume empty. Coolify records the target copies as
   `receiving`.
3. **Warm pass.** The data is copied while the source runs. Most of the data
   moves without downtime.
4. **Stop.** Coolify stops the source container and gives it 120 seconds
   for a clean shutdown before Podman kills it. Downtime starts.
5. **Final pass.** The copy runs again with `--delete` and sends only the
   changes since the warm pass. Because the source is stopped, nothing writes
   during this pass.
6. **Check.** While the source is still stopped, rsync compares every file of
   the target with the source by checksum, without changes
   (`--checksum --dry-run --delete --itemize-changes`). Any difference fails
   the move, and Coolify rolls back to the source. This check reads all data on
   both Nodes, so it adds downtime that grows with the volume size.
7. **Switch.** In one transaction, Coolify sets `NodeVolume.node_id` to the
   target for each volume.
8. **Start.** Coolify deploys the revision on the target and verifies that it
   runs. Downtime ends.
9. **Retain.** Coolify records the source copy as `retained`. After 7 days
   Coolify deletes it with `volume.delete.v1`. The user can delete it earlier.

### Transfer

- The source Sentinel starts a temporary `rsync --daemon`. It binds only to the
  WireGuard address of the source Node. The module is read-only and chrooted to
  the `_data` directory of the volume.
- The target Sentinel runs the `rsync` client into the new volume with
  `-aHAX --numeric-ids --sparse`, so owners, hard links, ACLs, extended
  attributes, and sparse files stay the same.
- Coolify creates a one-time secret for each transfer and sends it to both
  Sentinels in the operation. The secret expires with the operation. It is not
  stored in the operation journal.
- The source Sentinel adds a temporary rule to the `coolify_cluster` nftables
  table that allows the transfer port only from the WireGuard address of the
  target Node. It removes the rule after the transfer. The cluster firewall
  also drops outgoing traffic to the cluster range, so the target Sentinel
  allows its connection to the source address and port while a pass runs.
  Both allows are re-added after a firewall reconcile.
- Sentinel reports transfer progress to Coolify, so the UI can show the copied
  and the total size.
- Each Node needs `rsync`. Node preparation installs it.

### Failure and recovery

Each step is a durable `NodeOperation`. A retry continues the copy, because
`rsync` sends only what is missing.

| Failure | Result |
| --- | --- |
| Before the stop (steps 1–3) | The source keeps running. Coolify deletes the partial target volumes. |
| During the final pass or the check (steps 5–6) | Coolify starts the source again. No data is lost. |
| During the start on the target (step 8) | Coolify rolls back automatically: it sets `node_id` back, removes the target container, and starts the source on its volumes. The target never ran, so no writes are lost. |

When the source Node is offline, a direct copy is not possible. The user can
restore the latest volume backup on the target Node instead. The UI shows the
time of the backup, so the user knows which data is lost.

### Protocol

Field numbers continue after the highest number in use in `control.proto`.

| Command | Runs on | Request | Result | Journal |
| --- | --- | --- | --- | --- |
| `workload.deploy.v1` (extended) | Node of the volumes | new `repeated WorkloadVolumeMount mounts`: `volume_name`, `container_path`, `read_only` | unchanged | yes |
| `container.list.v1` (extended) | any Node | unchanged | new `repeated VolumeObservation volumes`: `name`, `labels`, `created_at_unix_ms`, only for volumes with `coolify.managed=true` | no |
| `volume.delete.v1` | any Node | `repeated string names` | `repeated string deleted`, `repeated string missing` | yes |
| `volume.serve.v1` | source Node | `transfer_id`, `repeated string names`, `peer_ip`, `secret`, `expires_at_unix_ms`, `stop` | `address`, `port`, `repeated VolumeUsage volumes` (`name`, `used_bytes`) | no |
| `volume.receive.v1` | target Node | `transfer_id`, `pass` (`warm`, `final`, or `verify`), `source_address`, `source_port`, `secret`, `repeated string names` | `state` (`running`, `succeeded`, `failed`), `bytes_total`, `bytes_done`, `error` | no |

- Each mount is `--mount type=volume,source=<volume_name>,target=<path>`.
  Sentinel creates a missing volume before it creates the container.
  `volume_name` must match `coolify-vol-<uuid>`. `container_path` is absolute,
  normalized, not `/`, and has no `,`. A request has at most 32 mounts.
- `volume.delete.v1` rejects a volume that a container uses. A missing volume
  is reported as `missing`, so a retry succeeds.
- `volume.serve.v1` is idempotent for its `transfer_id`: a repeated call
  returns the same port. `stop` ends the service and removes its firewall
  rule. Sentinel also stops it at `expires_at_unix_ms` and when Sentinel
  starts.
- `volume.receive.v1` starts the copy in the background for each
  `transfer_id` and `pass` and returns at once. A repeated call returns the
  current state, so Coolify polls with the same request. A finished pass
  stays readable until Sentinel restarts. A restart during a pass reports it
  as `failed`, and Coolify starts the pass again; `rsync` sends only what is
  missing.
- Coolify sends the mounts only to a Node that reports `volume.mounts.v1`.
  An older Sentinel ignores unknown fields, so without this check it would run
  the container without its data.

### Sentinel versions

Coolify uses volumes only on Nodes that report `volume.mounts.v1` and
`volume.delete.v1`.
Coolify does not offer a stateful move when the source or the target does not
report `volume.transfer.v1`, when the source does not report
`workload.stop_timeout.v1` (`workload.lifecycle.v1` accepts
`stop_timeout_seconds`, 1 to 600), or when the target does not report
`volume.verify.v1` (`volume.receive.v1` accepts the `verify` pass).

## Consequences

- Volumes work on a one-Node cluster with local disk speed and no extra
  components.
- A Node failure makes its volumes unavailable until the Node returns or the
  user restores a backup. Volume backups to S3 are required for recovery.
- A move with volumes has downtime. The downtime is the time of the final pass
  and the start, not the time of the full copy.
- The source volume uses disk for 7 days after a move.
- A volume cannot be shared by two workloads or by replicas of one workload.

## Not decided here

This decision does not define:

- the volume backup and restore format, schedule, and S3 layout (see decision
  0009);
- replication of volumes (asynchronous snapshots, DRBD, or another engine);
- database replication managed by Coolify;
- shared network volumes such as NFS;
- volume size limits, quotas, and resize;
- automatic failover when a Node fails (see decision 0014).
