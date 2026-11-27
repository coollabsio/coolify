# Decision 0032: Container runtime options of cluster applications

**Status: Accepted**

**Date: 2026-11-23**

## Context

A v4 application has four settings that change its container. A cluster
application on Nodes has none of them today:

- **Custom Docker Options** (`applications.custom_docker_run_options`).
  `convertDockerRunToCompose()` in `bootstrap/helpers/docker.php` reads the
  flags of `DOCKER_RUN_OPTION_MAPPING` (`bootstrap/helpers/constants.php`):
  `--add-host`, `--cap-add`, `--cap-drop`, `--security-opt`, `--sysctl`,
  `--device`, `--init`, `--ulimit`, `--privileged`, `--ip`, `--ip6`,
  `--shm-size`, `--dns`, `--gpus`, `--hostname`, `--entrypoint`,
  `--pids-limit`, `--read-only`, `--runtime`, and `--tmpfs`. It also reads
  `-i`, `-t`, `-it`, `--interactive`, and `--tty`.
  `unsupportedDockerRunOptions()` finds every other flag.
  `ApplicationDeploymentJob` writes a warning for these flags and ignores
  them.
- **GPU** (`ApplicationSetting`: `is_gpu_enabled`, `gpu_driver`, `gpu_count`,
  `gpu_device_ids`, `gpu_options`). `gpuDeviceReservation()` writes a Compose
  `deploy.resources.reservations.devices` entry. A `--gpus` flag is ignored
  when the GPU settings are on. `Server::parseNvidiaSmiGpus()` reads the GPUs
  of a v4 server over SSH with `nvidia-smi`.
- **Pre-deployment and post-deployment commands**
  (`pre_deployment_command`, `post_deployment_command`, and the `_container`
  columns). `ApplicationDeploymentJob::run_pre_deployment_command()` runs
  `docker exec <container> sh -c '<command>'` in the running container of
  the old version, when the builder container starts and before the build.
  It skips the command when no container runs. A failure fails the
  deployment. `run_post_deployment_command()` runs in the new container after
  the deployment. A failure only writes a log line. Neither command has its
  own timeout.
- **Container labels** (`applications.custom_labels`, base64). The deploy job
  removes lines that start with `coolify.`. Most v4 labels are Traefik or
  Caddy labels.

On Nodes, these parts exist:

- `WorkloadDeployRequest` (`crates/protocol/proto/control.proto`, fields 1 to
  31) has typed fields only. Decision 0028 added `shm_size_bytes`, `ulimits`,
  `tmpfs`, `stop_timeout_seconds`, `tty`, and `stdin_open` behind
  `workload.runtime_options.v1` (`crates/control/src/runtime_options.rs`).
  The request has `command` but no entrypoint.
  `NodeStackComposeMapper` puts a Compose `entrypoint` in front of the
  command, with a note.
- `BuildContainerLabels` sets `coolify.managed`, `coolify.instance`,
  `coolify.workload`, `coolify.revision`, `coolify.component`,
  `coolify.project`, and `coolify.environment`. Sentinel reads these labels
  for ownership: `discovery.rs` (endpoints), `convergence.rs` (the desired
  set), `exec.rs` (`coolify.managed`, `coolify.workload`, and
  `coolify.service`), and `secrets.rs` (`coolify.revision`). The label check
  in `commands.rs` refuses only an empty key, `=`, a NUL byte, and long
  values. It accepts any prefix.
- `RunNodeWorkloadCommand` runs `workload.exec.v1` (decision 0018). The
  timeout is 1 s to 36000 s, and the output limit is 1 MiB. Scheduled tasks
  use it, and the task pages authorize `update` on the resource.
- A deploy creates one operation for each Node (`DeployNodeWorkload`), in one
  notification group. `DeployNodeWorkloadJob` waits for the new container
  and then removes the replaced slot (`retireReplaced()`, decision 0022). The
  new container gets traffic when it is healthy.
- Sentinel reports fixed capabilities (`connection.rs`). `SystemInfoResult`
  has no GPU facts.
- `NodeStackCompose::UNSUPPORTED_KEYS` locks a stack with `privileged`,
  `cap_add`, `devices`, `security_opt`, `network_mode`, `pid`, or `sysctls`.
  A mount of the container socket needs a team admin
  (`ensureMayMountContainerSocket()`).

## Decision

### Docker options

- A cluster application gets the **Custom Docker Options** field, stored in
  `node_workloads.custom_docker_run_options`. Users and the API keep the v4
  syntax.
- On save, Coolify parses the text with `convertDockerRunToCompose()` and
  `unsupportedDockerRunOptions()`. Coolify writes the result as typed
  `runtime_options` in the revision configuration. A refused flag is a
  validation error on save, not a warning in the deployment log.
- Sentinel never receives the text. Each flag becomes one typed field.
  Sentinel validates the field and gives a fixed `podman run` option, as in
  0028.
- The new fields use one new capability, `workload.runtime_options.v2`.

| v4 flag | Result | Sentinel field | Podman option | Rules or reason |
| --- | --- | --- | --- | --- |
| `--shm-size`, `--ulimit`, `--tmpfs`, `-i`, `-t`, `--interactive`, `--tty` | Typed (0028) | existing fields | existing options | The rules of 0028 |
| `--init` | Typed | `bool init` | `--init` | — |
| `--read-only` | Typed | `bool read_only` | `--read-only` | Podman keeps tmpfs on `/run`, `/tmp`, and `/var/tmp` |
| `--pids-limit` | Typed | `optional int64 pids_limit` | `--pids-limit` | `-1`, or 1 to 4194304 |
| `--hostname` | Typed | `string hostname` | `--hostname` | One DNS label, at most 63 characters |
| `--entrypoint` | Typed | `repeated string entrypoint` | `--entrypoint` (JSON array) | At most 64 words. The stack mapper also uses it, and its note goes away |
| `--add-host` | Typed | `repeated WorkloadHost extra_hosts` | `--add-host name:ip` | A DNS name and an IP literal; at most 32; `host-gateway` is refused, because the Node firewall controls access to the host |
| `--cap-drop` | Typed | `repeated string cap_drop` | `--cap-drop` | A Linux capability name or `ALL` |
| `--security-opt no-new-privileges` | Typed | `bool no_new_privileges` | `--security-opt no-new-privileges` | Only this value |
| `--gpus` | Typed | see "GPU" | `--device <CDI name>` | Same as the GPU settings |
| `--ip`, `--ip6` | Refused | — | — | Coolify gives each slot its address (`EnsureNodeWorkloadAddress`), and the firewall rules use it |
| `--dns` | Refused | — | — | The container must use the discovery DNS of the cluster (`dns_server`, `dns_search`) |
| `--runtime` | Refused | — | — | A Node has one OCI runtime. A GPU uses CDI, not the NVIDIA runtime |
| `--cap-add` | Typed | `repeated string cap_add` (field 40) | `--cap-add` | The allow list and the tiers of decision 0036. Tier 2 needs a team admin |
| `--sysctl` | Typed | `repeated WorkloadSysctl sysctls` (field 43) | `--sysctl name=value` | The allow list and the tiers of decision 0036. Tier 2 needs a team admin |
| `--privileged`, `--device`, other `--security-opt` values | Refused | — | — | They lower the isolation of the container. See "Not decided here" |
| Every other flag (`-e`, `-v`, `-p`, `--memory`, `--network`, ...) | Refused | — | — | The error names the page that sets it: **Environment Variables**, **Storages**, **Published Ports**, **Resource Limits** |

### GPU

- Sentinel reads the CDI specs that Podman reads (`/etc/cdi`, `/var/run/cdi`).
  It reports the device names of the kind `nvidia.com/gpu` in
  `SystemInfoResult` (`gpu_cdi_devices`, for example `nvidia.com/gpu=0` and
  `nvidia.com/gpu=GPU-<uuid>`) and the result of the check (`gpu_cdi_check`).
  Sentinel always reports `workload.gpu.v1` (see "Implementation notes").
- The admin installs the NVIDIA driver and the NVIDIA Container Toolkit and
  generates the CDI spec. `PrepareNodeHost` does not install them. The Node
  page shows the GPUs that Sentinel reports.
- A team admin turns on **Allow GPU workloads** on the Node
  (`NodePolicy::update`). The default is off.
- A cluster application gets the v4 GPU fields: **Count** (a number or
  `all`) or **Device IDs**. The driver is always `nvidia`. **GPU options** are
  refused, because the CDI spec sets them. A stack maps the Compose `gpus`
  key and a `deploy.resources.reservations.devices` entry with
  `capabilities: [gpu]` to the same fields.
- Coolify sends `repeated string cdi_devices` (field 32), for example
  `nvidia.com/gpu=all` or `nvidia.com/gpu=0`. For a count, Coolify sends the
  first devices of the Node by index. Sentinel refuses a name that is not in
  its current CDI inventory or that has another kind. It gives
  `--device <name>`.
- Placement and `EnsureNodeAcceptsDeployment` refuse a GPU workload on a
  Node that does not allow GPU workloads, does not report
  `workload.gpu.v1`, or has fewer devices than the count. A move follows the
  same rule.
- Nodes do not reserve a GPU for one workload. Two workloads can use the same
  GPU, as on v4 servers.
- A GPU workload deploys stop-then-start, not make-before-break. Two copies of
  a model server often do not fit in the GPU memory. This adds a condition to
  decision 0022.

### Pre-deployment and post-deployment commands

- A cluster application and a Git stack get the v4 fields:
  **Pre-deployment command**, **Post-deployment command**, and a
  **Service** for a stack. A new field **Timeout** has a default of 300 s and
  accepts 1 s to 3600 s. A shell switch works as on scheduled tasks
  (`ScheduledTask::commandArguments()`).
- The commands run with `workload.exec.v1`, once for each deploy, not once
  for each Node.
- The pre-deployment command runs after the build or the image fetch and
  before a Node operation changes a container (see "Implementation notes").
  It runs in the running container of the old revision, on the first Node of
  `RunNodeWorkloadCommand::runningContainers()`. When no container runs (the
  first deploy), Coolify skips it with a log line, as in v4.
- When the pre-deployment command exits with a code other than 0, or times
  out, the deploy fails. Every Node operation of the deploy fails before
  Sentinel gets it. The old containers keep serving.
- The post-deployment command runs when every Node operation of the
  notification group succeeded, after `retireReplaced()`. It runs in the new
  container on the first Node. A failure writes a warning to the deployment
  log. The deploy stays successful, as in v4.
- The deploy notification does not wait for the post-deployment command and
  has no warning. v4 does the same: `ApplicationDeploymentJob::post_deployment()`
  sends `DeploymentSuccess` in `completeDeployment()`, before
  `run_post_deployment_command()`, and writes a failure to the log only.
  Coolify sends exactly 1 notification for each deploy (the `notified_at`
  claim), also when the command fails or times out.
- The output goes to the deployment log, with the redaction of scheduled
  tasks. Output above 1 MiB is truncated.
- A preview runs the commands in its own containers.

### Container labels

- A cluster application gets **Container Labels**: one `key=value` for each
  line, stored in `node_workloads.custom_labels`. Coolify does not generate
  ingress labels. Ingress reads routes from Corrosion (decision 0005).
- Coolify and Sentinel refuse a key that starts with `coolify.`,
  `io.containers.`, or `io.podman.`, and the key `PODMAN_SYSTEMD_UNIT`.
  Sentinel checks this in `commands.rs` for each label that is not from
  `BuildContainerLabels`. Coolify sends the user labels in a separate field,
  `repeated WorkloadLabel user_labels` (field 33). Sentinel adds them before
  its own labels.
- Rules: at most 64 labels; a key of 1 to 255 characters from
  `[A-Za-z0-9._/-]` that starts with a letter or a digit; a value of at most
  4096 bytes without NUL.
- When a user pastes v4 Traefik or Caddy labels, Coolify keeps them and shows
  a note that ingress on Nodes does not read them.
- A stack maps the Compose `labels` key with the same rules.

## Options compared

| Option | What it does | Boundary | Result |
| --- | --- | --- | --- |
| A. Pass the options text to `podman run` | v4-like passthrough | Member with write on a workload -> Node host (`--privileged`, `--device`) | Refused. Decision 0001 forbids Podman passthrough |
| B. Parse the text in Coolify into typed fields | v4 syntax, typed Sentinel fields | None for the typed fields | **Chosen** |
| C. Typed form fields only, no text | One input for each flag | None | Not chosen. The v4 API field and pasted v4 options stop working |
| D. Pre-deployment command in the old container | v4 behavior | None | **Chosen** |
| E. Pre-deployment command in the `next` slot before traffic | Runs new code first | None | Not chosen. Sentinel publishes the endpoint when the container is healthy. A hold state needs a new Sentinel state and does not fit a stop-then-start deploy |
| F. One-shot container of the new image (release phase) | Like Heroku release | None | Not chosen now. It needs a new Sentinel command, an address, and a rule for writable volumes (decision 0007) |
| G. User labels in the same field as Coolify labels | No new field | Member with write on a workload -> other workloads of the cluster | Refused. Sentinel cannot tell a user label from an ownership label |
| H. GPU without a Node opt-in | Any member places a GPU workload | Member with write -> Node host through a driver flaw | Refused. A team admin turns on each Node |

## Security

- **Refused flags.** `--privileged`, `--cap-add`, `--device`, `--sysctl`,
  `--runtime`, and the other `--security-opt` values cross the boundary
  "member -> Node host". They need only write access on a workload. On Nodes,
  write access does not give root on the Node: the container socket already
  needs a team admin. This decision keeps that rule.
- **`--ip`, `--ip6`, and `--dns`.** They cross "member with access grants ->
  resources outside the grants". The address and the DNS of a container are
  part of the firewall rules and of service discovery for the whole cluster.
- **Labels.** Without the prefix refusal, a member with write access on one
  workload could change labels that Sentinel reads for ownership. This
  crosses "member with access grants -> resources outside the grants" in the
  same team. A cluster belongs to one team, so it does not cross to another
  team. Sentinel must refuse the prefix itself, because an older Coolify or a
  direct Flux call does not check it.
- **GPU.** A CDI device gives the container device nodes and driver files of
  the host. A flaw in the driver or the toolkit can cross "member -> Node
  host". It needs write access on a workload and a Node that a team admin
  opened for GPU workloads. Sentinel accepts only names from its CDI
  inventory, so a request cannot name a host path.
- **Pre-deployment and post-deployment commands.** They cross no boundary.
  They need `update` on the workload, like scheduled tasks. They run only in
  a container of the workload. `runningContainers()` filters by the team of
  the workload, and Sentinel exec requires `coolify.managed` and
  `coolify.workload`. A user who can set the command can also set the image
  and the command of the container.
- The typed fields `init`, `read_only`, `pids_limit`, `hostname`,
  `entrypoint`, `extra_hosts` (without `host-gateway`), `cap_drop`, and
  `no_new_privileges` cross no boundary. Some of them only add limits.

## Consequences

- Most v4 applications move to Nodes with their Docker options, GPU, deploy
  commands, and labels. An application with a refused flag gets an error
  that names the flag and the reason.
- One Sentinel version adds `workload.runtime_options.v2`,
  `workload.gpu.v1`, the GPU facts, and the label check. Coolify sends user
  labels and the new fields only to a cluster where every Node reports the
  capability.
- The new fields are part of the revision configuration, so they change
  `configuration_hash`, and a change starts a redeploy.
- The pre-deployment command runs the code of the old revision, as in v4.
  The docs must say this for database migrations.
- The pre-deployment command runs after the build in v5, not before it. A
  failed build does not run it.
- A GPU workload has a short downtime on each redeploy.

## Answers to the open questions (2026-11-26)

The user accepted the decision with these answers. They have priority over
the text above.

- **Pre-deployment command.** It runs with exec in the old container, as in
  v4. Without an old container, the command is skipped with a note.
- **GPU workloads.** They always deploy with stop-then-start, because one GPU
  cannot be shared by two container slots.
- **NVIDIA driver.** `PrepareNodeHost` does not install the driver or the
  toolkit. The Node page shows the result of the CDI check and a link to the
  docs.

## Implementation notes (2026-11-27)

Built in Sentinel `725ed36` and on Coolify `v5-progress`. The code showed
that some rules of this decision must change. The text above is changed
where it was wrong.

- **`--cap-add` and `--sysctl`.** Decision 0036 is accepted, so they are
  typed fields with its allow list and tiers. The allow list is one module
  in each code base: `App\Support\ContainerIsolation` and
  `crates/control/src/isolation.rs`. Sentinel accepts tier 1 and tier 2 and
  refuses tier 3. Coolify checks tier 2 with
  `ContainerIsolation::ensureMayUseAdminTier()` (a team admin of the team of
  the resource, like `ensureMayMountContainerSocket()`) on save on the page
  and in the API (`UpdateNodeWorkloadRuntimeOptions`), at the start of a
  build (`StartNodeBuild`), and on each deploy, rollback, build deploy, and
  move (`EnsureNodeRuntimeOptions` in `CreateDeploymentOperation`). A member
  cannot save the options while they have a tier 2 value. Stacks do not use
  it yet (decision 0036).
- **Where the pre-deployment command runs.** It runs in
  `DeployNodeWorkloadJob`, after the image fetch and before the command or
  the desired set goes to Sentinel, not before Coolify creates the
  operations. Reason: a deploy, a rollback, a build deploy, and the API
  create operations in different places, and a command of up to 3600 s
  must run in the queue, not in a web request. The first operation of the
  notification group runs it under a lock. The other servers of the deploy
  wait and use its result. A failure fails each operation before Sentinel
  gets it, so the old containers keep serving.
- **The convergence path (decision 0026).** The commands run for each
  deploy on both paths: the `workload.deploy` command and the desired set
  that Sentinel converges. Coolify runs them, because only Coolify knows
  when a deploy starts and when every server of it succeeded. A restart or a
  new convergence by Sentinel without a deploy runs no command, as a
  container restart in v4 runs none. A move, a restore, and the reconciler
  run no command (they have no notification group).
- **The post-deployment command** runs in a job after every operation of the
  group succeeded, in the container of the slot of the first server. The
  warning is in the deployment log. The deploy notification does not wait
  for the command, so it has no warning, as in v4. An earlier rule put the
  warning in the notification. v4 sends its success notification before the
  command, so the rule changed to match v4.
- **No shell switch.** Both commands run with `sh -c`, as in v4. The image
  must have `sh`.
- **Git stacks.** The commands are built for cluster applications only. A
  stack service has no fields yet.
- **`workload.gpu.v1`.** Sentinel reports it always. A capability is fixed
  for a connection, and an admin can install the driver after Sentinel
  starts. Coolify reads the devices from the server information and refuses
  a GPU workload on save and on deploy when the server does not allow GPU
  workloads, does not report the devices, or has fewer devices than the
  count. The Node page shows the CDI check and a link to the NVIDIA CDI
  documentation.
- **`--gpus`.** The flag maps to the same request as the GPU settings. The
  GPU settings replace it, as in v4.
- **Labels.** Coolify stores them as plain text, not base64. Sentinel
  refuses the reserved keys in `user_labels` (also `coolify.`) and in
  `labels` (`io.containers.`, `io.podman.`, and `PODMAN_SYSTEMD_UNIT`), for
  every caller. Sentinel adds the user labels before its own labels.
- **Capability limit.** A Sentinel of this version reports more than 64
  capabilities, and the assignment endpoint refused it. The limit is now
  256.
- **Not tested live: GPU.** The dev Nodes have no GPU. The Sentinel unit
  tests cover the CDI parse, the refusal of an unknown device, and the
  arguments. The live checks covered the refusals on save.
- **Live checks on cluster 1 (2026-11-27).** `podman inspect` showed
  `--init`, `--pids-limit`, `--shm-size`, `--ulimit`, `--hostname`,
  `--add-host`, `--security-opt no-new-privileges`, `--cap-add NET_RAW`,
  `--cap-drop SYS_CHROOT`, the sysctl `net.core.somaxconn`, and the user
  labels. `--privileged`, `--device`, `--cap-add SYS_ADMIN`, a tier 3
  sysctl, a `coolify.` label, and a GPU without an allowed server gave
  `422`. Sentinel refused a reserved label, `SYS_ADMIN`, and an unknown GPU
  from a direct Flux call. A member got `422` for `NET_RAW` and could not
  deploy it. An admin could. A failed pre-deployment command stopped the
  deploy, and the old container kept running. A failed post-deployment
  command gave a warning. Another team got `404`.

## Not decided here

- `--privileged`, `--device`, and the other `--security-opt` values.
- AMD and Intel GPUs (`amd.com/gpu`, other CDI kinds), MIG slices, exclusive
  GPU reservation, and GPU metrics.
- A release phase in a one-shot container of the new image (option F).
