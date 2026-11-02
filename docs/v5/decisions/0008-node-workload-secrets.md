# Decision 0008: Keep runtime variables in the environment and add opt-in secret files

**Status: Accepted**

**Date: 2026-11-02**

## Context

Coolify stores the runtime variables of a cluster application encrypted in
the workload revision (`node_workload_revisions.environment`) and sends them
through Flux to Sentinel in `workload.deploy.v1`. Sentinel passes each value to
Podman through the process environment (`--env KEY`), so a value is never in a
command line. Podman keeps every value in the container configuration.

Sentinel also stores each command request in its durable command journal
(`commands.sqlite`) for up to 7 days to detect a reused command ID. The
journal kept the complete deploy request, so every runtime value was on the
Node a second time. This contradicts the split of responsibilities: Sentinel
does not store Coolify secrets.

We tested Podman 4.9.3 (Ubuntu 24.04) on a development Node:

- A secret of type `env` is a normal variable in the container, and
  `podman inspect` shows its value. It also adds a copy in the Podman secret
  store. It has no benefit on this version.
- A secret of type `mount` is a file at `/run/secrets/<target>`, mode 0444.
  Its value is not in the environment of the container and not in
  `podman inspect`. It survives a container restart.
- Podman reads a secret at each container start. A container whose secret was
  deleted cannot start again.
- Podman does not store an empty secret, cannot filter secrets by label, and
  cannot `--replace` a secret that does not exist.

Security guidance prefers files to environment variables for secrets, because
every process in the container, child processes, crash dumps, and error pages
can read the environment. Most applications read their configuration from the
environment, so a PaaS must keep environment variables as the default.

## Decision

- Runtime variables stay environment variables (`--env KEY`). This is the
  default.
- A runtime variable of a cluster application can be a secret file instead
  (`environment_variables.is_file_secret`, the "Delivery" setting). The
  container reads it at `/run/secrets/<key>`. Its value is not in the
  environment of the container.
- The revision keeps secret files apart from the environment, encrypted
  (`node_workload_revisions.secrets`). They are part of the configuration
  hash. A revision without secret files keeps its old hash.
- `workload.deploy.v1` carries them in `secrets`. Sentinel creates one Podman
  secret for each file and revision,
  `coolify-sec-<container>-<revision>-<key>` (each part a short SHA-256), with
  the value in the process environment of `podman secret create --env`. A
  secret of a revision never changes, so an existing one is kept.
- After a successful deploy, Sentinel deletes the secrets of the container that
  the new revision does not use. `workload.lifecycle.v1` with `remove` deletes
  every secret of the container. Move, delete, and build cleanup use this
  command, so no secret stays on a Node that no longer runs the workload.
- A Node reports `workload.secrets.v1` when its Sentinel supports secret files.
  Coolify does not deploy a revision with secret files to a Node without it.
- A secret file name has 1 to 255 letters, digits, `_`, `.`, or `-`, and is not
  `.` or `..`. A value has 1 byte to 512 kB. There are at most 128 per
  application.
- The command journal stores a SHA-256 digest of each request, not the request.
  It replaces the requests that older versions stored when it opens. A reused
  command ID with a different request still fails.
- Coolify hides runtime values in the error of a workload operation before it
  stores the error. Logs always hide the value of a secret file, like a locked
  variable.

## Consequences

- An application must read the file, or use an image that supports `<NAME>_FILE`.
- Root on a Node can still read every value. Secret files protect against
  leaks inside the container and in container details, not against the Node.
- A Node with an old Sentinel must upgrade before it can deploy an application
  with secret files.

## Left open

- The owner and mode of a secret file. Podman uses root and 0444.
- Build-time secrets for builder Nodes (decision 0006).
- Secret manager references for cluster applications.
