# Decision 0032: Container runtime options of cluster applications

**Status: Proposed**

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
| `--privileged`, `--cap-add`, `--device`, `--sysctl`, other `--security-opt` values | Refused | — | — | They lower the isolation of the container. See "Not decided here" |
| Every other flag (`-e`, `-v`, `-p`, `--memory`, `--network`, ...) | Refused | — | — | The error names the page that sets it: **Environment Variables**, **Storages**, **Published Ports**, **Resource Limits** |

### GPU

- Sentinel reads the CDI specs that Podman reads (`/etc/cdi`, `/var/run/cdi`).
  It reports the devices of the kind `nvidia.com/gpu` in `SystemInfoResult`,
  with the name, the index, and the UUID. Sentinel reports
  `workload.gpu.v1` only when it can read CDI specs.
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
  before Coolify creates the Node operations. It runs in the running
  container of the old revision, on the first Node of
  `RunNodeWorkloadCommand::runningContainers()`. When no container runs (the
  first deploy), Coolify skips it with a log line, as in v4.
- When the pre-deployment command exits with a code other than 0, or times
  out, the deploy fails. Coolify creates no Node operation. The old
  containers keep serving.
- The post-deployment command runs when every Node operation of the
  notification group succeeded, after `retireReplaced()`. It runs in the new
  container on the first Node. A failure writes a warning to the deployment
  log and in the notification. The deploy stays successful, as in v4.
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

## Not decided here

- `--privileged`, `--cap-add`, `--device`, `--sysctl`, and the other
  `--security-opt` values. Decision 0036 decides them for stacks, with an
  allow list and a team-admin tier. When 0036 is accepted, the same allow
  list applies to `--cap-add` and `--sysctl` of a cluster application.
- AMD and Intel GPUs (`amd.com/gpu`, other CDI kinds), MIG slices, exclusive
  GPU reservation, and GPU metrics.
- A release phase in a one-shot container of the new image (option F).
- Installation of the NVIDIA driver and toolkit by `PrepareNodeHost`.
