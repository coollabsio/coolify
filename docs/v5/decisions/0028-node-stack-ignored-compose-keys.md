# Decision 0028: Compose keys that Nodes ignore today

**Status: Accepted**

**Date: 2026-11-16**

## Context

A stack service on a Node runs without these Compose keys. The mapper
(`NodeStackComposeMapper::IGNORED_KEYS`) drops them and shows a note on the
service:

| Key | Templates that use it | Examples |
| --- | --- | --- |
| `shm_size` | 7 | `128mb` to `2g` (browsers, databases, PostHog, Plausible) |
| `ulimits` | 7 | `nofile` (7), `nproc` (1), `memlock` (1), as a number or `soft`/`hard` |
| `stop_grace_period` | 5 | `30s` to `2m` |
| `tty` | 3 | `true` |
| `stdin_open` | 2 | `true` |
| `tmpfs` | 2 | a path or a list of paths |
| `deploy` | 1 | `resources.limits.memory` |

Without these keys, some services run worse or fail. A browser or Postgres
with the Podman default of 64 MB `/dev/shm` can crash. A search engine
without `nofile` or `memlock` can refuse to start. A database that gets only
the default stop time of 10 s can stop without a clean shutdown.

Sentinel already has some of this:

- `WorkloadDeployRequest` has `cpu_limit`, `cpu_reservation`,
  `memory_limit_bytes`, and `memory_reservation_bytes`.
- `WorkloadLifecycleRequest` has `stop_timeout_seconds`.

The other keys need new fields.

## Decision

Map each key, in the way of `user:` (decision 0023, "User of a service"):

- Add the new fields to `WorkloadDeployRequest` under one new capability,
  `workload.runtime_options.v1`.
- Sentinel validates each value and gives a fixed `podman run` option. A
  value never becomes free text in an argument.
- Coolify validates the same rules on the create page and on the sync of a
  Git stack, and shows an error for each service.
- A stack with one of these keys can deploy only when every server of the
  cluster reports the capability. Otherwise the template list shows the
  reason.
- When a key is not set, the payload does not send it, and the container
  keeps the Podman default.

| Key | Sentinel field | Podman option | Rules |
| --- | --- | --- | --- |
| `shm_size` | `optional uint64 shm_size_bytes` | `--shm-size` | Compose byte units; at most the memory limit of the service, when it has one, and at most 16 GiB |
| `ulimits` | `repeated WorkloadUlimit ulimits` (name, soft, hard) | `--ulimit name=soft:hard` | Names: `nofile`, `nproc`, `memlock`, `core`, `stack`, `msgqueue`, `sigpending`, `rtprio`; soft <= hard; `-1` for unlimited; at most 16 |
| `tmpfs` | `repeated WorkloadTmpfs tmpfs` (path, size) | `--tmpfs path:size=<bytes>` | The rules of the `files` paths; no other mount options; at most 8 |
| `stop_grace_period` | `optional uint32 stop_timeout_seconds` | `--stop-timeout` | 1 s to 3600 s. Coolify also sends it as `stop_timeout_seconds` on stop, restart, and a replacement in a deploy |
| `tty` | `bool tty` | `--tty` | — |
| `stdin_open` | `bool stdin_open` | `--interactive` | — |
| `deploy.resources` | the existing CPU and memory fields | the existing options | Only `limits` and `reservations` of `cpus` and `memory`. A limit on the **Resource Limits** page of the service has priority. Coolify ignores the other `deploy` keys (`replicas`, `placement`, `restart_policy`, `update_config`) with a note |

## Security

None of these keys crosses a boundary of the threat model. A user who can
deploy a Compose file has root on the server already. The limits only keep a
wrong value from stopping the Node:

- `shm_size` and `tmpfs` use memory of the Node, so the memory limit of the
  service limits them.
- A `ulimits` value above the hard limit of the Node fails the deploy with the
  Podman error.

## Consequences

- 21 templates lose their notes and run with their intended settings.
- One Sentinel version adds all the fields, so a Node needs only one upgrade.
- `deploy.resources` works without a Sentinel change, so Coolify can map it
  before the Sentinel work is done.

## Not decided here

- `sysctls` (1 template), `cap_add`, `security_opt`, `privileged`,
  `network_mode`, and `pid`. They lock a template today, because they change
  the isolation of the container, and need their own decision.
- `deploy.replicas` and other Swarm keys. Replicas belong to automatic
  placement (P7).
