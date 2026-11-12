# Decision 0009: Back up Node volumes to S3 and restore them to any Node

**Status: Accepted**

**Date: 2026-11-11**

## Context

Decision 0007 keeps each volume on one Node and has no replication. When a Node
fails, its volumes are not available. A backup is the only recovery path.

v4 already has volume backups: `ScheduledVolumeBackup` has a frequency, S3
retention, `stop_during_backup`, a timeout, and a missing-backup notification.
It belongs to its volume through the `backupable` morph relation. v4 runs the
backup over SSH with Docker. Nodes have no Docker and no SSH deploy path.

## Decision

- A `NodeVolume` uses the v4 `ScheduledVolumeBackup` model as its
  `backupable`. The UI, schedule, retention, and notifications are the v4
  ones. Node backups go to S3 only. The local retention settings do not apply.
- The target is an S3 storage of the team that owns the volume.
- A new Sentinel command `volume.backup.v1` reads the volume with `tar`,
  compresses it with `zstd`, and streams it to S3 with a multipart upload.
  Coolify gives presigned URLs for each part. The Node never gets the S3 access
  key. This works with every S3-compatible provider.
- Each backup is a full copy. By default, the container runs during the backup,
  so the copy is crash-consistent. With `stop_during_backup`, Sentinel stops
  the container, makes the backup, and starts the container again.
- Coolify records each run in `ScheduledVolumeBackupExecution` with the size,
  the S3 key, and a SHA-256 checksum of the archive.
- A new Sentinel command `volume.restore.v1` downloads an archive with a
  presigned URL into a new empty volume on a Node that the user selects in the
  same cluster. Sentinel checks the checksum before it reports success.
- A restore replaces the volume of the workload with the same steps as a move
  with volumes (decision 0007): stop, switch, start. The old volume stays for
  7 days as a rollback, like a move.
- A Node reports `volume.backup.v1` and `volume.restore.v1` when its Sentinel
  supports them. Coolify does not schedule a backup on a Node without them.

## Consequences

- A volume on a failed Node can come back on another Node, with the data of the
  last backup.
- Each backup uploads the full volume. Large volumes use more S3 space and
  bandwidth than with incremental backups.
- A crash-consistent copy of a running database can be unusable. Databases use
  dumps (decision 0010), or `stop_during_backup`.

## Not decided here

- Incremental, deduplicated, or client-side encrypted backups (for example
  restic or kopia).
- Backups to a target that is not S3.
- Restore to a Node in another cluster.

## Implementation notes

These notes record the details that the decision leaves open.

### Backup

- Coolify creates the multipart upload with the S3 key of the team storage
  and polls `volume.backup.v1` every 2 seconds. The command is not journaled,
  because it carries presigned URLs. The first poll starts the backup in the
  background on the Node; every poll returns the current state.
- Each poll signs UploadPart URLs for the next 32 parts, starting at
  `next_part_number`, the part that Sentinel needs next. A URL is valid for 60
  minutes, and a new poll replaces it. So Coolify never signs URLs for the
  whole volume at once, and a URL that expired is replaced before Sentinel
  uses it.
- Sentinel chooses the part size: the used size of the volume divided by
  8000 parts, rounded up to 1 MiB, at least 16 MiB and at most 5 GiB. The
  rest of the S3 limit of 10000 parts is for a volume that grows during the
  backup. Sentinel holds one part in memory.
- Sentinel runs `tar --numeric-owner --xattrs --xattrs-include=* -cf - .` in
  the `_data` directory of the volume and pipes it through `zstd -3 -T0`. It
  hashes the archive with SHA-256 while it reads it. GNU tar exit code 1
  (a file changed while it was read) is accepted: the backup of a running
  container is crash-consistent.
- Sentinel uploads each part with up to 3 attempts and reports the ETag of each
  uploaded part. After `succeeded`, Coolify checks that the part numbers are
  1 to n and that their sizes add up to the archive size, completes the upload,
  and compares the stored size with HeadObject.
- When Sentinel gets no URL for its next part in 10 minutes, it fails the
  backup. When the backup fails, Coolify sends `abort` to Sentinel and aborts
  the multipart upload. When the worker stops, `VolumeBackupJob::failed()`
  aborts every unfinished upload of the key.
- With `stop_during_backup`, Sentinel stops the container (`podman stop`
  with 120 seconds) only when it runs, and starts it again after the backup,
  also after a failure or an abort. While such a backup runs, the workload
  reconciler skips the workload, so it does not start the container.
- The S3 key is
  `volume-backups/<team id>/<workload uuid>/<volume uuid>/<volume name>-<UTC time>.tar.zst`.
  The execution records it as `filename`, with `size`, `sha256`, and one S3
  copy record, so the v4 S3 retention and deletion work unchanged. There is
  no local archive: `local_storage_deleted` is always true.
- A schedule runs in the instance timezone. The scheduler skips it while the
  volume has no Node, the Node has no recent Flux heartbeat, or the Node does
  not report `volume.backup.v1` and `volume.restore.v1`.
- Coolify checks again at run time that the S3 storage of the schedule
  belongs to the team of the workload.

### Restore

- A restore is a `workload.move.v1` operation with a `restore` request (the
  volume UUID and the execution UUID). So it blocks deploys, lifecycle
  actions, moves, and the reconciler like a move, and stale operation
  recovery rolls it back.
- `volume.restore.v1` with step `download` creates the volume when it is
  missing, rejects a volume that any container uses, empties it, and streams
  the presigned GetObject URL through `zstd -d | tar -x`. It checks the size
  and the SHA-256 at the end; on a mismatch it empties the volume and fails.
  Coolify polls it like a backup and signs a new URL on each poll.
- On the server of the volume, the Podman name of the volume stays the same,
  so the revision does not change. Coolify records a new copy with its own
  Podman name `coolify-vol-<copy uuid>` (`node_volume_copies.podman_name`) and
  downloads into it while the application runs. Then Coolify stops the
  application, and step `switch` exchanges the `_data` directories of the
  two volumes with renames. A marker file next to `_data` makes the switch
  idempotent per restore ID. The copy now holds the old data and is retained
  for 7 days. Then Coolify starts the application again.
- If a step fails before the restore is done, Coolify switches the data
  back, deletes the restored data, and starts the application again.
- To another server of the cluster, the move takes the restored volume from
  S3 on the target instead of the source: Sentinel downloads it into the
  receiving volume, and rsync copies only the other volumes of the workload.
  The source must be online, because the move stops it.
