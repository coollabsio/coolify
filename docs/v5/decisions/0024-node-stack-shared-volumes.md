# Decision 0024: Share a volume between the services of one stack

**Status: Accepted**

**Date: 2026-11-14**

## Context

Decision [0007](0007-node-volumes.md) gives each volume one workload on one
Node, and lets one container write a read-write volume. Decision
[0023](0023-node-compose-stacks.md) runs a Compose file as a stack with one
workload for each service. In Compose, several services of one project can
mount the same named volume, and on one host they share the same data.

20 templates are not available on Nodes only because of that:

- 14 templates: an application and its worker, scheduler, or executor both
  write the same storage, for example chatwoot `rails-data`, n8n `n8n-data`,
  twenty, glitchtip, penpot, opnform, sure, and appwrite.
- 4 templates: an init or setup service writes files that the main service then
  uses: vikunja `init`, the affine migration, hatchet `setup-config`, and
  signoz `init-clickhouse`, which `clickhouse` mounts read-only.
- 1 template mounts the volume read-only in the second service (chibisafe), and
  1 template uses `volumes_from` (espocrm).

Separate copies of the data break these applications: the worker does not see
the uploads of the application.

## Decision

Option A below: a volume can belong to a stack and be shared by its services
on one Node.

- **Stack volume.** A named volume that two or more services of one stack mount
  is a stack volume: a `NodeVolume` that belongs to the stack, not to one
  workload. Each service mounts it read-write or read-only, as the Compose file
  says. Only services of the same stack can share a volume.
- **One Node.** A stack volume still lives on one Node and is not replicated
  (decision 0007). Every service that mounts it runs on that Node. Coolify
  places these services together and blocks a deploy of one of them on another
  Node.
- **Volume group.** Services that share stack volumes, directly or through
  another service, form a volume group. **Move to another server** of one of
  them moves the whole group, with the break-before-make volume move of decision
  0007, so the data is copied once. The confirmation lists the services that
  move.
- **Deploy.** A service with a read-write stack volume deploys with
  stop-then-start (decision [0022](0022-node-rolling-deploys.md)). The other
  services of the group keep running, as `docker compose up` of one service
  does.
- **`volumes_from`.** A service with `volumes_from: [other]` mounts the volumes
  of `other` at the same paths, read-only when the entry ends with `:ro`. The
  volumes become stack volumes.
- **Backups.** A stack volume has one backup schedule (decision 0009). A
  restore stops every service of the group that mounts it, restores the data,
  and starts them again in `depends_on` order.
- **Delete.** A stack volume stays while a service of the stack mounts it. It
  is deleted with the stack, or explicitly, as decision 0007 describes.

This makes 18 of the 20 templates available. appwrite and trigger also mount
the Docker socket, which decision 0023 leaves open. With it, 314 of the 388
templates can run on Nodes when every Node reports
`discovery.names.underscore.v1` (296 before).

## Options compared

| Option | Good | Bad |
| --- | --- | --- |
| **A. Stack volume on one Node, shared by the services (chosen)** | The same behavior as Compose on one host; no new storage technology; the data is moved and backed up once. | The services of a volume group cannot spread over several Nodes. A move moves the whole group. |
| **B. One copy of the volume for each service** | Keeps the rule of decision 0007 as it is. | Breaks the 20 templates: a worker does not see the uploads of the application, and an init service writes into a copy that nobody reads. |
| **C. Network storage (NFS, Ceph)** | Services could share data across Nodes. | Decision 0007 rejected it: it needs more Nodes, more memory, or a kernel module, and it is not the default for a one-Node cluster. |
| **D. Keep these templates unavailable on Nodes** | No work. | 18 templates, such as chatwoot, n8n with a worker, twenty, and penpot, stay on legacy servers only. |

## Consequences

- Decision 0007's "a read-write volume allows one container only" applies to
  one workload. A stack volume allows one container of each service that
  mounts it.
- The placement and move code must handle a volume group, not one workload.
- A volume group is a unit of failure: when its Node goes offline, the recovery
  move of decision 0014 moves the whole group.

## Not decided here

- Sharing a volume between stacks, or between a stack and a single
  application.
- Spreading the services of a volume group over several Nodes.
