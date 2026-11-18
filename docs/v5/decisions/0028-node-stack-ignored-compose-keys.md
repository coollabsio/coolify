# Decision 0028: Compose keys that Nodes ignore today

**Status: Accepted, built**

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
| `tmpfs` | `repeated WorkloadTmpfs tmpfs` (path, size) | `--tmpfs path:size=<bytes>`, or `--tmpfs path` without a size | The rules of the `files` paths; no other mount options; at most 8 |
| `stop_grace_period` | `optional uint32 stop_timeout_seconds` | `--stop-timeout` | 1 s to 600 s (amended, see "Implementation notes"). Coolify also sends it as `stop_timeout_seconds` on stop, restart, and a replacement in a deploy |
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

## Implementation notes

Built on 2026-11-18 with Sentinel `619dec7` and `f3e39d0` (images
`main-<sha>`) and Coolify on `v5-progress`.

### Changes to the rules

- **Stop timeout: 600 s, not 3600 s.** The lifecycle command
  (`workload.stop_timeout.v1`), the stop timeout of a desired set entry
  (decision 0026), the Coolify lifecycle operation, and the volume backup
  already accept at most 600 s. One more limit for the same value would let a
  deploy set a time that a stop then refuses. The templates use 30 s to 2 min.
- **A tmpfs size is optional.** Both templates with `tmpfs` (`openwa`) mount
  `/tmp` without a size. Without a size, Sentinel gives `--tmpfs path` and
  Podman uses its default. The memory limit of the container also limits the
  tmpfs, because its pages count in the memory cgroup. A `mode=`, `uid=`, or
  other option is refused with an error, as the rule says.
- **Without a stop timeout, Podman uses the stop timeout of the container.**
  Before, Sentinel sent `--time 10` on each stop and restart without a value.
  Now it sends `--time` only when the request has a value, also on a remove.
  So `--stop-timeout` of the deploy applies also to a stop or a remove that
  has no value, for example the removal of a replaced container in the
  convergence path. Podman 6.1.4 on the dev Nodes waits the stop timeout of
  the container on `stop`, `restart`, and `rm --force` without `--time`.

### Sentinel

- `WorkloadDeployRequest` fields 26 to 31: `shm_size_bytes`, `ulimits`
  (`WorkloadUlimit`: name, soft, hard), `tmpfs` (`WorkloadTmpfs`: path,
  size_bytes), `stop_timeout_seconds`, `tty`, and `stdin_open`, behind
  `workload.runtime_options.v1` (in `STACK_CAPABILITIES`).
- `crates/control/src/runtime_options.rs` validates the values and gives
  `--shm-size <bytes>b`, `--ulimit name=soft:hard`, `--tmpfs`,
  `--stop-timeout`, `--tty`, and `--interactive`. A memory size is 1 byte to
  16 GiB and at most `memory_limit_bytes`. A tmpfs path is not a path of a
  mount, a file, or a socket.
- The fields are part of the stored desired set (`stored_deploy` keeps the
  whole request), and `validate_entry` checks them. Coolify puts them in the
  revision configuration, so they are part of `configuration_hash`, the
  entry hash, and the set hash.
- Flux maps the JSON keys of the deploy and of a desired set entry, and
  returns 409 when a Node without the capability gets one of them.
- A deploy or lifecycle command with an invalid value now fails with the
  message of the field (for example "A ulimit name is invalid."), not with
  "Command is invalid or expired." (`f3e39d0`).

### Coolify

- `NodeStackComposeMapper` maps the keys to `runtime_options` in the revision
  configuration: byte units (`128mb`, `1g`, `500M`, a number), the `ulimits`
  forms (a number, or `soft` and `hard`), the `tmpfs` forms (a path or a list,
  each with an optional `:size=`), Compose durations, and booleans. Each rule
  of the table gives an error with the name of the service, on the create
  page and on the sync of a Git stack. `IGNORED_KEYS` is gone.
- `deploy.resources.limits` and `reservations` (`cpus`, `memory`) become
  `resources` of the revision, the fields of the **Resource Limits** page.
  The Git sync uses the three-way rule for `resources`, so a value from the
  page stays. The other `deploy` keys get the note "deploy.replicas is
  ignored on Nodes".
- `node_stacks.compose_mapping_version` records the mapping that gave the
  stored file to the services. Version 2 maps `deploy.resources`. For a stack
  from before (null), the sync reads the resources of the stored file as
  none, because its services got none: a service without resources takes
  the resources of the new file, and a value from the page stays. Such a
  stack syncs once also when the file is the same.
- `NodeCluster::supportsRuntimeOptions()` gates the template list, the create
  pages, and the Git sync: "The service clickhouse sets ulimits, which needs
  Sentinel with workload.runtime_options.v1 on every server of the cluster".
  The cache key of the template availability has version 3.
- `DispatchWorkloadDeployment` sends only the keys that are set, checks the
  capability, and refuses a deploy when the **Resource Limits** page sets a
  memory limit below `shm_size` or a tmpfs size.
- The Compose `stop_grace_period` is the stop timeout of each stop, restart,
  and remove that Coolify sends (`CreateLifecycleOperation`), of the removal
  of a replaced or a failed container in a rolling deploy, and of the desired
  set entry. `ManageNodeWorkloadJob` waits long enough for it.
- The sync of a Git stack always takes `runtime_options` from the new file:
  no page changes them.

### Templates

With the capability, 8 more templates are available on Nodes: `argilla`,
`gitlab`, `openclaw`, `openpanel`, `orbit`, `palworld`, `signoz`, and
`terraria-server`. The other templates with these keys stay locked by other
keys (`cap_add`, `security_opt`, host mounts, `sysctls`, or `privileged`), or
are not in the catalog.

### Checks on dev cluster 1

| Check | Result |
| --- | --- |
| `openclaw` (`shm_size: 2g`) | `/dev/shm` 2.0G in the browser container, `ShmSize=2147483648` |
| `openpanel` (`ulimits` `nofile` 262144) | `ulimit -n` 262144 soft and hard in the ClickHouse container |
| `orbit` (`stop_grace_period: 40s`) | `StopTimeout=40` in `podman inspect` |
| Stack with `tmpfs`, `deploy.resources`, `tty`, `stdin_open`, `stop_grace_period: 15s` | `/scratch` tmpfs with `size=32768k`, `/tmp` tmpfs, `memory.max` 134217728, `NanoCpus` 500000000, `Tty` and `OpenStdin` true; a restart and a stop of the container that ignores SIGTERM took 15 s and more |
| Invalid values | `shm_size: 99g`, the ulimit `nofiles`, `stop_grace_period: 15m`, and `tmpfs: /tmp:mode=1777` are refused before the stack is created; a page memory limit below `shm_size` refuses the deploy |

