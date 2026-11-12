# Decision 0018: Add typed exec and terminal commands for containers

**Status: Accepted**

**Date: 2026-11-11**

## Context

v4 has a terminal and scheduled commands in containers over SSH and
`docker exec`. Sentinel has no exec command, and decision 0001 and ADR 0002
forbid unrestricted shell or Podman passthrough.

## Decision

- `workload.exec.v1` runs one command in a container of a workload and returns
  the exit code and the output. The output has a size limit. Scheduled tasks use
  it. Only admins of the team that owns the workload can use it.
- `workload.terminal.v1` opens an interactive PTY in a container and streams it
  through Flux. It uses the same `canAccessTerminal` gate as v4.
- Both commands name a workload container, never a host process. A terminal on
  the Node host is not included. SSH stays the path for that.

## Consequences

- Scheduled tasks and the terminal reach v4 parity on Nodes.
- Flux needs a bidirectional stream for the terminal.

## Not decided here

- Recording of terminal sessions.
