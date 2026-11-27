# Decision 0036: Isolation keys that lock templates on Nodes

**Status: Accepted**

**Date: 2026-11-23**

## Context

A Compose service with one of these keys cannot run on a Node today.
`NodeStackCompose::UNSUPPORTED_KEYS` gives the first key that it finds as the
reason, and the template list shows "Not available on Nodes":

- `privileged`, `cap_add`, `devices`, `security_opt`, `network_mode`, `pid`,
  and `sysctls`.
- A host bind mount (`kind` `host` in `NodeStackCompose::mounts()`): an
  absolute path, a `~` path, or a `$` path. Decision 0007 refuses host bind
  mounts, and `NodeStackComposeMapper::storage()` refuses them again.

Coolify ignores `cap_drop` without a note.

With every capability of decisions 0016, 0023, and 0028, 317 of the 354
templates of the catalog run on Nodes. The other 37 are locked only by the
keys of this decision. A script over `templates/compose/*.yaml` called
`NodeStackCompose::unavailableReason()` with every capability flag true. It
then removed one key from each service and called the check again
("only this key"):

| Key | Templates locked | Only this key | Values in the templates |
| --- | --- | --- | --- |
| Host bind mount | 14 | 12 | `/etc/localtime:ro` (4), `/dev/urandom:ro`, `/run/dbus:ro`, `/var/lib/docker/containers`, `/tmp`, `/tmp/ansible`, `/var/lib/pterodactyl`, data paths (`/apprise-api/config`, `/jupyter`, `/data/foundryvtt`), `${COOLIFY_VOLUME_APP}` |
| `cap_add` | 10 | 4 | `SYS_ADMIN` (3), `NET_ADMIN` (3), `SYS_PTRACE`, `SYS_NICE`, `SYS_RESOURCE`, `SYS_TIME`, `SYS_MODULE`, and `CHOWN`, `DAC_OVERRIDE`, `FOWNER`, `SETGID`, `SETUID` (2, after `cap_drop: ALL`) |
| `privileged` | 7 | 4 | `true` (4 are the Forgejo runner templates) |
| `security_opt` | 7 | 4 | `no-new-privileges:true` (3), `seccomp=unconfined` (2), `label:disable` (1) |
| `network_mode` | 7 | 3 | `host` (6), `service:tailscale-client` (1) |
| `sysctls` | 2 | 0 | `net.core.somaxconn`, `net.ipv4.ip_forward`, `net.ipv4.conf.all.src_valid_mark` |
| `devices` | 1 | 0 | `/dev/net/tun` |
| `pid` | 1 | 0 | `host` (`glances`) |

27 templates are locked by one key only. 10 are locked by two or more keys.
Three more templates outside the catalog (`macos`, `windows`, `booklore`) are
not counted.

### How a Node runs a container

- Sentinel runs rootful Podman. The workload socket is
  `/run/podman/podman.sock` (`crates/control/src/container_socket.rs`).
  Container root is root on the Node when a container escapes its namespaces.
- `podman run` of a workload (`commands.rs`) sets no `--cap-add`,
  `--cap-drop`, or `--security-opt`. A container gets the Podman default
  capabilities and the default seccomp profile. The Podman default set does
  not have `NET_RAW`.
- Each container has a fixed IP and MAC address on the network of the
  cluster. The nftables tables `coolify_cluster` and `coolify_cluster_bridge`
  (`network.rs`) allow traffic inside a stack and drop other traffic between
  workloads. The rules match the IP source address.
- The host network namespace has the WireGuard mesh interface, the Sentinel
  API, Corrosion, the DNS server of the workloads, and the ingress.
- `/etc/coolify`, `/var/lib/coolify`, and `/data/coolify` on the Node hold
  the Flux trust bundle, Corrosion data, and Sentinel state.

### Who can deploy to a Node

- A cluster belongs to one team (`NodeCluster::team()`). A Node never runs
  workloads of two teams.
- A member with a write grant can create and change a stack
  (`NodeStackPolicy::update`) and place a resource on a server where the
  member has a write grant (`ServerPolicy::placeResource`). Resources of other
  grants of the same team run on the same Nodes.
- On a v4 server, a member with a write grant has root on the server
  (AGENTS.md, "Security threat model"). On a Node, this is not true today.
  Nodes refuse host mounts, give the socket only to admins, and have no host
  shell (decision 0018). So "member with access grants -> resources outside
  the grants" is a real boundary on a Node.
- The precedent is the Docker socket (`workload.container_socket.v1`).
  `NodeStackCompose::ensureMayMountContainerSocket()` refuses a user who is
  not an admin or owner of the team. A change without a user (the
  reconciler, a webhook) is allowed, because the team admin trusted the
  repository.

## Decision

Sort each value into one of three tiers. Sentinel validates each value against
a fixed list and gives a fixed `podman run` option. A value never becomes free
text in an argument, as in decision 0028.

### Tier 1: every user who can change the stack

These values remove privileges or keep the Podman default:

| Key | Values | Podman option |
| --- | --- | --- |
| `cap_drop` | A capability name, or `ALL` | `--cap-drop` |
| `cap_add` | Only names of the Podman default set: `CHOWN`, `DAC_OVERRIDE`, `FOWNER`, `FSETID`, `KILL`, `NET_BIND_SERVICE`, `SETFCAP`, `SETGID`, `SETPCAP`, `SETUID`, `SYS_CHROOT` | `--cap-add` |
| `security_opt` | `no-new-privileges` or `no-new-privileges:true` | `--security-opt no-new-privileges` |
| `sysctls` | `net.core.somaxconn`, `net.ipv4.ip_local_port_range`, `net.ipv4.tcp_syncookies`, `net.ipv4.ping_group_range`, `net.ipv4.ip_unprivileged_port_start`, `net.ipv4.tcp_keepalive_time`, `net.ipv4.tcp_keepalive_intvl`, `net.ipv4.tcp_keepalive_probes`, `net.ipv4.tcp_fin_timeout`, `kernel.shm_rmid_forced` | `--sysctl name=value` |

Host bind mounts change in the mapper only. Sentinel gets no host path:

- `/etc/localtime`, `/etc/timezone`, `/dev/urandom`, and `/dev/random` are
  ignored with a note. The container has its own `/dev/urandom`. The note
  tells the user to set `TZ`.
- Another absolute path becomes a stack volume, as a relative bind
  (`./data`) does (decisions 0023 and 0024). The note says that the data is
  in a Node volume and not in the host directory. The data moves and backs
  up with the volume (decisions 0007 and 0009).
- The path stays refused when it is under `/proc`, `/sys`, `/dev`, `/run`,
  `/var/run`, `/etc`, `/var/lib/docker`, `/var/lib/containers`,
  `/var/lib/coolify`, or `/data/coolify`. The application wants the content
  of the host there, and a volume does not give it.
- The path stays refused when the service also mounts the Docker socket. The
  containers that it starts through the socket use the same host path, so a
  volume breaks them (`appwrite`, `autobase`, `wings`).
- A `~` path or a `$` path stays refused.

### Tier 2: team admin or owner only, from an allow list

| Key | Values | Podman option |
| --- | --- | --- |
| `cap_add` | `NET_ADMIN`, `NET_RAW`, `SYS_PTRACE`, `SYS_NICE`, `SYS_RESOURCE`, `IPC_LOCK` | `--cap-add` |
| `sysctls` | `net.ipv4.ip_forward`, `net.ipv4.conf.<interface>.src_valid_mark`, `net.ipv4.conf.<interface>.forwarding`, `net.ipv6.conf.<interface>.forwarding` | `--sysctl name=value` |

- Add `NodeStackCompose::ensureMayUseAdminIsolation()`, in the way of
  `ensureMayMountContainerSocket()`. Call it at the same places:
  `CreateClusterStack`, `SyncNodeStackCompose`, `DeployNodeStackJob`,
  `CreateDeploymentOperation`, `CreateMoveOperation`, and `StartNodeBuild`.
- A change without a user is allowed, as for the socket.
- The service page shows the values, so a member sees why a change needs an
  admin.

### Tier 3: refused for every user

- `privileged`, `pid: host`, and `network_mode: host`.
- `cap_add` of any other name, for example `SYS_ADMIN`, `SYS_MODULE`,
  `SYS_TIME`, `SYS_RAWIO`, `DAC_READ_SEARCH`, `BPF`, or `ALL`.
- `security_opt` of any other value, for example `seccomp=unconfined`,
  `apparmor=unconfined`, or `label:disable`.
- Any other sysctl, also every `kernel.*` sysctl except
  `kernel.shm_rmid_forced`.
- The host bind mounts of tier 1 that stay refused.

### Sentinel

- ~~One new capability, `workload.isolation_options.v1`, in
  `STACK_CAPABILITIES`.~~ Changed on 2026-11-27: no new capability. Decision
  0032 added the same fields to Sentinel `725ed36` under
  `workload.runtime_options.v2`, so stacks use that capability. A second
  capability for the same fields would let a Node report one and not the
  other.
- New fields in `WorkloadDeployRequest`: `repeated string cap_add`,
  `repeated string cap_drop`, `bool no_new_privileges`, and
  `repeated WorkloadSysctl sysctls` (name, value). Sentinel `725ed36` has
  them (decision 0032).
- Sentinel has the allow list of tiers 1 and 2 and refuses other values.
  Sentinel does not know the role of the user. Coolify checks the role.
- The fields are part of the stored desired set (decision 0026) and of the
  configuration hash.
- A stack with one of these keys deploys only when every server of the
  cluster reports the capability (`NodeCluster::supportsIsolationOptions()`).
  Otherwise the template list shows the reason. The cache key of the
  template availability changes (`AVAILABILITY_VERSION`).

## Options compared

| Option | Templates unlocked | Boundary crossed | Role that the riskiest value needs |
| --- | --- | --- | --- |
| A. Keep every key refused | 0 | None | — |
| B. Allow every key for team admins, without an allow list | 37 | None. An admin can already mount the socket and get root on the Node | Team admin |
| C. Three tiers (recommended) | 12 (10 for every user, 2 for admins) | None | Team admin for tier 2 |
| D. An allow list for every user with a write grant, as on v4 | 12 | Member with a grant -> resources outside the grants on the same Node (`NET_ADMIN`, `NET_RAW`, `SYS_NICE`) | Member with a write grant |

Option C unlocks `apprise-api`, `elasticsearch-with-kibana`, `foundryvtt`,
`immich`, `jupyter-notebook-python`, `metabase`, `openwa`,
`openwa-with-postgresql-and-redis`, `redlib`, and `stalwart` for every user,
and `kuzzle` and `swetrix` for admins. A second run of the script with the
rules of option C gave these numbers.

Option B is refused although it crosses no boundary of the threat model. A
privileged or host network container on a Node changes the host that Sentinel
manages:

- A host network container shares ports with Sentinel, Corrosion, the DNS
  server, and the ingress. Its traffic leaves the host namespace, so the
  workload rules of nftables do not apply to it.
- A privileged container can change nftables, the WireGuard interface, and
  the files of Sentinel. The next reconcile can then fail on every resource
  of the Node.
- A team admin who needs this can use the Docker socket, which is an explicit
  choice with a warning.

## Security

- **Tier 1** crosses no boundary. Each value removes a privilege or keeps the
  Podman default. The sysctls are namespaced to the network or IPC namespace
  of the container. A converted host path becomes a volume, so no host file
  is mounted.
- **Tier 2** needs a team admin or owner. For a member with a write grant,
  each value crosses the boundary "member with access grants -> resources
  outside the grants":
  - `NET_ADMIN` and `NET_RAW` let a container send traffic with a different
    source address on the shared network of the Node. The stack rules of
    nftables trust the source address, so the container can reach stacks of
    other grants.
  - `SYS_NICE` and `SYS_RESOURCE` let a container take CPU or limits from
    Sentinel, Corrosion, and the workloads of other grants on the same Node.
  - `SYS_PTRACE` and `IPC_LOCK` stay in the namespaces of the container, but
    they add kernel attack surface beyond the Podman default.
  For a team admin, no value crosses a boundary: an admin can already mount
  the socket. No value reaches another team, because a cluster belongs to
  one team.
- **Tier 3** stays refused. For a member, each value crosses "member ->
  Node host" (root on the Node, the mesh, and the files of Sentinel). For an admin, it crosses no boundary, but it breaks the
  managed host (see option B).
- No tier 1 or tier 2 value reaches the Coolify host, also when Coolify runs
  on a Node: each value stays in the namespaces of the container.
- Sentinel validates again, so a wrong Coolify check cannot give a value
  outside tiers 1 and 2.

## Consequences

- 12 more templates of the catalog run on Nodes (329 of 354). As built, 13
  run, with the fixed `wireguard-easy` (330 of 354).
- A converted host path changes where the data is. A user who moves a stack
  from v4 must copy the host directory into the volume. The note on the
  service says this.
- `cap_drop` now has an effect. A template that drops `ALL` and adds less
  than it needs fails on a Node, as it does on Docker.
- One Sentinel version adds every field, so a Node needs one upgrade.
- 25 templates stay locked (24 as built). Most need `privileged`, `network_mode: host`,
  `SYS_ADMIN`, or `seccomp=unconfined`.
- Add the new fields to the server transfer (`ServerTransferCoverage`) with
  the revision configuration.

## Answers to the open questions (2026-11-26)

The user accepted the decision with these answers. They have priority over
the text above.

- **Host paths.** An absolute host path that is not a system path becomes a
  stack volume, with a note on the service.
- **`SYS_PTRACE` and `IPC_LOCK`.** They stay in the team-admin tier.
- **Templates.** Where a safe change exists, the template is fixed instead of
  Nodes: `SYS_MODULE` of `wireguard-easy` and `network_mode: host` of
  `cloudflared`.

## Not decided here

- `devices`, for example `/dev/net/tun` (`tailscale-client`) and `/dev/kvm`.
- `network_mode: service:<name>`. Two services share one network namespace,
  so they must run on one Node, as for decision 0024.
- Host paths for a service that mounts the Docker socket (`appwrite`,
  `autobase`, `wings`, `pterodactyl-with-wings`).
- Template changes for other templates, for example `SYS_TIME` of `pi-hole`.
- The template bug in `deno-kv`: `${COOLIFY_VOLUME_APP}` is not a magic
  variable of Coolify.
- A cluster setting that allows tier 3 for a team that wants it.

## Implementation notes (2026-11-27)

- **Capability.** Stacks use `workload.runtime_options.v2` of decision 0032
  (see "Sentinel" above). `NodeCluster::supportsIsolationOptions()` is true
  when every usable server of the cluster reports it. Without it, a service
  with `cap_add`, `cap_drop`, `security_opt`, or `sysctls` is not available.
  `cap_drop` is no longer ignored, so a file that only drops capabilities
  also needs the capability.
- **One allow list.** `App\Support\ContainerIsolation::parse()` checks the
  four keys for the Custom Docker Options of an application (0032) and for
  the Compose keys of a stack service. Sentinel has the copy of the list in
  `crates/control/src/isolation.rs`. Values are interpolated with the stack
  variables first. A sysctl can be a map or a list of `name=value`.
- **Mapper.** `NodeStackComposeMapper::isolationOptions()` adds the four keys
  to `runtime_options` of the revision. The diff treats `runtime_options` as
  a key that only the file sets, so the new file always wins.
  `MAPPING_VERSION` is 3, so a stack syncs once again with the same file and
  gets `cap_drop`. `privileged`, `devices`, `network_mode`, and `pid` stay in
  `UNSUPPORTED_KEYS`, and the template list shows why.
- **Team-admin tier.** `NodeStackCompose::ensureMayUseAdminIsolation()` runs
  next to `ensureMayMountContainerSocket()` in `CreateClusterStack`,
  `SyncNodeStackCompose`, `DeployNodeStackJob`, and `StartNodeBuild` (for
  the service of the build). `CreateDeploymentOperation` checks each revision
  through `EnsureNodeRuntimeOptions`. `CreateMoveOperation` now checks the
  revision before the move starts. Before, a move of a 0032 application with
  a tier 2 value failed only in `MoveNodeWorkloadJob`. A call without a user
  is allowed. The API calls the same actions. The service description shows
  the tier 2 values, for example "--cap-add SYS_NICE needs a team admin or
  owner to create, change, or deploy this service".
- **Host paths.** `NodeStackCompose::hostPathRefusal()` has the rules of
  tier 1. The mapper ignores `/etc/localtime`, `/etc/timezone`,
  `/dev/urandom`, and `/dev/random` with a note. Another absolute path
  becomes a stack volume with the key `host:<normalized path>` and the name
  `host-<path slug>`. Services that mount the same host path share one
  volume and stay on one Node (decision 0024). `:ro` stays: a shared volume
  is read-only for that service, and an unshared volume gets the read-write
  note. The root path `/` is also refused. A bind without `content:` is a
  directory volume, because v4 also takes a bind without content as a
  directory. A bind with `content:` stays a file of the `files` feature.
- **Templates.** `wireguard-easy`: `SYS_MODULE` is removed and `NET_RAW` is
  added. The kernel loads the WireGuard module when the container creates
  the interface, so `SYS_MODULE` is not necessary where the module exists.
  The Podman default set has no `NET_RAW`, and iptables in the container
  needs it. On Docker, `NET_RAW` is in the default set, so the change does
  nothing on a v4 server. The risk on v4: a host that cannot load the module
  on demand. The template needs a team admin on Nodes (`NET_ADMIN`,
  `NET_RAW`, and two tier 2 sysctls). It publishes UDP 51820, which is the
  default WireGuard port of a cluster, so it is created only on a cluster
  with another WireGuard port. `cloudflared` is not changed. The Coolify
  documentation of Cloudflare Tunnels on v4 points the tunnel to
  `http://localhost:80`, which needs the host network. No change is safe for
  v4, so `cloudflared` stays locked by `network_mode`.
- **Count.** The read-only count calls `unavailableReason()` with every
  capability flag true on the 354 templates of the catalog. Before: 317 run
  and 37 are locked. After: 330 run and 24 are locked. The 13 new templates:
  `apprise-api`, `elasticsearch-with-kibana`, `foundryvtt`, `immich`,
  `jupyter-notebook-python`, `metabase`, `openwa`,
  `openwa-with-postgresql-and-redis`, `redlib`, and `stalwart` for every
  user, and `kuzzle`, `swetrix`, and `wireguard-easy` for a team admin.
  `AVAILABILITY_VERSION` is 4.
- **Server transfer.** The values are in the `runtime_options` of the
  revision configuration, which the transfer moves (decision 0032). A host
  path volume is a stack volume and moves as one.
- **Checks on cluster 1 (2 Nodes, Sentinel `main-725ed36`).**
  - `openwa` (tier 1): the effective capabilities are `CHOWN`,
    `DAC_OVERRIDE`, `FOWNER`, `SETGID`, and `SETUID`, with
    `no-new-privileges`.
  - `redlib`: every default capability is dropped, with
    `no-new-privileges`.
  - `swetrix` (tier 2): `clickhouse` has `CAP_SYS_NICE`. A member with
    team-wide write access was refused at create.
  - `apprise-api` (host path): `/apprise-api/config` is the stack volume at
    `/config`. After an edit to `cap_add: NET_RAW` and
    `net.core.somaxconn: 1024`, the member was refused for the edit, the
    stack deploy, the deploy of the service, and a move. The admin edit and
    deploy gave `CAP_NET_RAW`, and `/proc/sys/net/core/somaxconn` is 1024.
  - The member edit of `redlib` with only a tier 1 sysctl was accepted.
  - `esphome` (`privileged`) was refused for the admin.
