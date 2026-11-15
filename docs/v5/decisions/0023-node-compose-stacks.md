# Decision 0023: Run Compose services on Nodes as stacks of workloads

**Status: Accepted**

**Date: 2026-11-14**

## Context

v4 runs one-click services and Docker Compose applications with one
`docker compose up` on one server, in a private network of the project. On a
Node, a workload is one container. Nodes have no grouping of workloads, no
`depends_on`, no names for the services of one stack, and no file content
mounts. All traffic between workloads is dropped unless a firewall rule allows
it (decision 0005).

A census of the 388 templates in `templates/compose` (843 services):

- 210 templates have two or more services, and 174 of them reach a sibling by
  its bare service name, for example `postgres:5432`.
- 194 use `depends_on`, 167 of them with `condition: service_healthy`, and 9
  with `service_completed_successfully` (a one-time container).
- 361 use `SERVICE_URL_*`, 254 `SERVICE_PASSWORD_*`, and 198 `SERVICE_USER_*`.
- 350 use named volumes, 67 bind mounts (mostly relative paths, such as
  `./data`), and 36 Coolify `content:` file mounts.
- No template uses `build:`. Compose applications from Git can.
- 72 templates need a feature that a workload does not have:
  - 20 only published `ports` (decision 0016);
  - 12 only the Docker socket;
  - 8 only an absolute host path;
  - 7 only `cap_add`, `security_opt`, or `network_mode`;
  - 25 several of these, for example `privileged` or `devices`.

## Decision

Option A below: a **stack** groups one Node workload for each Compose service.
Each service keeps everything that a workload has today: revisions, rolling
deploys, health checks, logs, terminal, scheduled tasks, volume backups, and
rollback.

- **Model.** A new `NodeStack` belongs to a team, project, environment, and
  cluster. It stores the Compose file as the user or the template wrote it and
  its parsed form. Each Compose service becomes a `NodeWorkload` with
  `node_stack_id` and its service name. The stack page lists its services; each
  service opens the normal cluster application page.
- **Parser.** Coolify reuses the v4 service parser for magic variables, domains
  (`SERVICE_URL_*`, `SERVICE_FQDN_*`), and generated passwords, and writes its
  output to the workloads of the stack. The parser does not change for legacy
  servers.
- **Names.** A service reaches another service of its stack by its bare name.
  Each service gets the name `<service>.<stack>.coolify.internal`, and its
  containers get the DNS search domain `<stack>.coolify.internal`. Sentinel
  needs a `dns_search` field in `workload.deploy` (new capability). Each
  service also keeps its cluster-wide name `<name>.default.coolify.internal`,
  so a firewall rule can let another application reach it, for example a
  shared database.
- **Traffic.** Coolify allows all traffic, on every port, between the services
  of one stack, as a Compose project network does. Only 5 templates list their
  ports in `expose:`, so a list of ports would break most stacks. Coolify
  builds these allows from the stack membership in each cluster network
  revision, not from stored addresses, so they follow every move. Traffic
  between stacks and other workloads stays dropped unless a firewall rule
  allows it.
- **Order.** A stack deploy deploys its services in `depends_on` order.
  `service_healthy` waits for the health check, `service_started` for the
  start, and `service_completed_successfully` for an exit code of 0. A one-time
  service has restart policy `no` and counts as deployed when it exits with 0.
- **Placement.** A new stack runs on one Node, which the user selects. Each
  service can move to another Node on its own in the first step, with **Move
  to another server**:
  - its name follows its endpoint in Corrosion, and its search domain is the
    same on every Node;
  - the next network revision allows the stack traffic for its new address on
    every Node. Its peers call it, so the move waits until that revision
    applies before the target takes traffic, as a move of a routed workload
    does today;
  - a service with a writable volume moves with its data, as decision 0014
    describes;
  - traffic between Nodes goes over the mesh with its source address
    (decision 0005).
- **Volumes.** Named volumes stay named volumes. A relative bind mount
  (`./data`) becomes a named volume of the stack. The rules of decision 0007
  stay: a writable volume pins its service to one Node.
- **Files.** A `content:` mount is stored with the revision of its service, and
  Sentinel writes it into the container (`workload.files.v1`, new capability),
  as decision 0007 planned for configuration files.
- **Not in the first step.** A template that needs the Docker socket, an
  absolute host path, `privileged`, `cap_add`, `devices`, `security_opt`,
  `network_mode`, `pid`, or `sysctls` shows "Not available on Nodes" with the
  reason. These templates stay available on legacy servers. Published `ports`
  come with decision 0016, which adds 20 templates.
- **Compose from Git.** Compose applications from Git, which can `build:`
  images, come in a second step. Each service with `build:` builds with the
  Node builder (decision 0006).

With this first step, 316 of the 388 templates can run on Nodes, and 336 after
port publishing.

## Options compared

| Option | Good | Bad |
| --- | --- | --- |
| **A. A stack of workloads (chosen)** | Every service gets every workload feature: rolling deploys, rollback, backups, terminal, logs, placement. Ingress, firewall, and DNS already work per workload. | The most work: a stack model, deploy order, names, and two Sentinel fields. One stack deploy is several operations. |
| **B. One Podman pod for each stack on one Node** | Close to Compose: the containers share `localhost`, and one operation deploys the stack. | Services that listen on the same port collide in one pod. Rolling deploys, health, backups, terminal, and rollback must work again per container in a pod. A stack cannot use more than one Node. |
| **C. Run Compose on the Node** (`podman compose` through Sentinel) | The fastest to build, and close to v4 behavior. | It bypasses the Node model: no Corrosion ingress, firewall, secrets, backups, rolling deploys, or rollback. Legacy servers already run Compose well. |

## Consequences

- A stack deploy shows one deployment for each service.
- Rollback, backups, and moves work per service, not per stack.
- Templates need no change. The template list shows on which server types a
  template can run.
- Two new Sentinel capabilities: `dns_search` in `workload.deploy` and
  `workload.files.v1`.

## Not decided here

- Docker or Podman socket access for a stack (12 templates need only that). A
  Podman socket gives root on the Node.
- Placement of the services of one stack on several Nodes when the stack is
  created.
- Rollback of a whole stack to one point in time.
- Conversion of a v4 service on a legacy server to a stack.

## Implementation (step 1, 2026-11-14)

Step 1 is built: `NodeStack`, `NodeStackCompose` (availability),
`NodeStackComposeMapper` (services to workloads), `CreateClusterStack`,
`DeployNodeStackJob`, `DeleteNodeStackJob`, and the stack page. These points
differ from the decision above:

- **Parser.** The v4 service parser writes the records of a v4 service, so a
  stack does not call it. `NodeStackComposeMapper` reuses its helpers
  (`parseServiceEnvironmentVariable`, `parseCommandFromMagicEnvVariable`,
  `generateEnvValue`, `generateDockerComposeServiceName`) and keeps their
  meaning. It also resolves the Compose interpolation (`${VAR:-default}`,
  `$$`) itself, because a workload has no `.env` file. The generated
  `SERVICE_*` values are stored once, encrypted, in `node_stacks.variables`.
  Each service gets its values as environment variables of its own
  application. A change to a value in one service does not change it in the
  other services.
- **Templates on Nodes.** 296 of the 388 templates run on Nodes, not 316.
  The 20 others share a volume between two services, or use `volumes_from`.
  A Node volume belongs to one workload (decision 0007). The decision counts
  `.yaml` files only; `templates/compose` also has 4 `.yml` templates.
- **Names with `_`.** Compose names often have `_`, for example `rallly_db`.
  Sentinel accepts `_` inside a name of a stack namespace with
  `discovery.names.underscore.v1`. Coolify sends such a name, and offers such a
  template, only when every usable server of the cluster reports it. Otherwise
  the template shows "Not available on Nodes" (291 templates run), because one
  invalid name rejects the whole name table.
- **Traffic.** Sentinel firewall rules need a port, so Coolify sends the stack
  allows apart, as `stack_rules` (`source_ip`, `destination_ip`) in
  `network.firewall.reconcile`, to Nodes that report
  `network.firewall.stack_rules.v1`. Sentinel renders them in the forward
  chains only, because both ends are workload addresses. A Node without the
  capability does not get them, and the services on it reach each other only
  through firewall rules that the user adds.
- **Entrypoint.** A workload has no entrypoint field. The Compose
  `entrypoint` becomes the first words of the command, after the entrypoint
  of the image. A note on the service says this.
- **Ignored keys.** `user`, `shm_size`, `ulimits`, `tmpfs`, `stop_grace_period`,
  `deploy`, `tty`, and `stdin_open` are ignored, with a note on the service.
  Read-only volumes are mounted read-write. A service with `profiles` is not
  created.
- **Health check.** The Compose health check is kept in
  `node_workloads.stack_health_check`. It applies until the user turns on the
  Coolify health check of the application. A `CMD` test is sent as a JSON
  array, which Podman runs without a shell, so images without `/bin/sh` keep
  their check. It needs `workload.healthcheck.exec.v1` (Sentinel `46b61ce`);
  `CMD-SHELL` stays a shell command.
- **One-time services.** A service that another service waits for with
  `service_completed_successfully` gets restart policy `no`. The reconciler
  does not start a one-time container again after it ran.
- **Deploy order.** A stack deploy deploys one service after another, in
  `depends_on` order, through the normal deployment of each application. The
  first failure stops the deploy and names the services that were not
  deployed. A dependency cycle fails when the stack is created.
- **Images.** An image without a tag gets `:latest`, as the container
  inventory reports it. The same applies to a cluster Docker image
  application.

## Implementation (step 2, 2026-11-15)

Step 2 is built: Compose applications from Git. The Git create pages (public
repository, GitHub App, and deploy key) have the build pack **Docker
Compose**, with **Compose file location** (default `/docker-compose.yaml`)
and the base directory. `ReadGitComposeFile`, `CreateClusterGitStack`, the
Git source of `NodeStack`, and the build step of `DeployNodeStackJob` are
new. These points differ from the decision above or add to it:

- **Reading the file.** Coolify reads the file in its own container, not on
  a server. It fetches the head commit of the branch without file content
  (`--depth 1 --filter=blob:none`), then reads the one file. A symbolic link
  and a file larger than 5 MiB are refused, as in v4. `ResolveGitCommit` and
  `ReadGitComposeFile` use the same credential handling (`NodeGitProcess`).
  Coolify reads the file when it creates the stack, and again at each
  deploy of the stack (see **Changes of the Compose file**).
- **Source.** The stack stores `git_repository`, `git_branch`,
  `github_app_id` or `private_key_id`, `base_directory`, `compose_location`,
  and `commit_sha`. A template stack keeps them empty, so
  `compose_location` has no default in the database.
- **Built services.** A service with `build:` is a Dockerfile application of
  the same repository and credential. Its base directory is the build
  context, and its Dockerfile location is relative to the context. The
  context is relative to the directory of the Compose file. An `image:` next
  to `build:` is ignored, with a note. A build arg becomes a build-time
  variable that is not a runtime variable. A build arg with the name of an
  environment variable of the service uses that variable, with the value of
  the environment. `build.target` is sent as `target` in `image.build`, which
  needs `image.build.target.v1`.
- **First revision.** A built service has no revision until its first build.
  The first build takes the command, restart policy, and files of the Compose
  service from the stored Compose file of the stack.
- **Not available on Nodes.** A context that is a URL, an absolute path, or a
  path outside the repository; a Dockerfile outside its context (the builder
  needs it inside); `dockerfile_inline`, `additional_contexts`, `ssh`, and
  `secrets` of `build:`; a top-level `include`; and `extends` with `file`.
  Other keys of `build:` are ignored, with a note. Templates still cannot use
  `build:`.
- **Deploy.** A stack deploy reads the Compose file at the head of the
  branch. The commit of that read is the commit of the deploy. A webhook
  commit only starts the deploy, because the head is the same commit or a
  newer one. The deploy syncs the services with the file, then starts the
  builds of all built services at that commit, and these builds do not deploy (`node_builds.skip_deploy`).
  Each build is a normal build, with its log on the page of its service.
  When a build fails, nothing deploys, and the stack shows the failed
  services. Then every service deploys in `depends_on` order, as in step 1.
  The stack stores the commit of the last successful build in `commit_sha`.
- **Webhooks.** A push that matches a built service of a stack deploys the
  whole stack at the pushed commit, once for each stack. A push during a
  deploy of the stack is skipped. A push deploy does not deploy an image
  service again when it is deployed and its configuration did not change,
  as `docker compose up` does, so a database does not restart for each push.
  A deploy from the stack page deploys every service.
- **Changes of the Compose file.** When the file is different from
  `compose_raw`, `SyncNodeStackCompose` makes the services match it before
  the builds. `NodeStackComposeDiff` compares three versions: the services
  of the previous file, the services of the new file, and the current
  workloads. For each value that the file owns, it uses this rule: when the
  current value is equal to the value of the previous file, the value of
  the new file is taken. When it is different, the user changed it, and the
  value of the user stays. The rule applies to:
  - each environment variable: its value and its runtime and build-time
    flags. A new key is added. A removed key is deleted only when the user
    did not change it. A variable that the user deleted is not added again;
  - the image of an image service, and the command, restart policy, and
    files of the latest revision. A change makes a new revision. A built
    service gets a new revision with its current image, so that the next
    build copies the new configuration;
  - the base directory, Dockerfile location, and build target of a built
    service;
  - the domains, as one list.

  The health check of the file (`stack_health_check`) is always taken,
  because the user sets an own health check in other fields. A service that
  changes between `image:` and `build:` changes its source type and its Git
  fields. A new service is created as at the creation of the stack. It is
  placed on the server of its shared volumes, or else on the server of the
  first service. A service that left the file is deleted after the sync,
  with the normal delete of a cluster application. A new volume path makes
  a new volume. A volume whose path left the file stays with its data, and
  the summary lists it. A new mount of a shared volume is added, and a
  mount that left the file is removed; the shared volume stays (decision
  0024). The values are saved for the user of the deploy. A webhook deploy
  has no user, so an owner or admin of the stack team is used.
  A push deploy deploys a changed image service again, because its
  configuration changed.
- **Sync failures.** Coolify does all checks before it writes, and writes
  in one transaction. When the sync fails, the deploy fails with the
  reason, and the stack does not change. The sync fails when:
  - the new file is not available on Nodes, or its services depend on each
    other in a cycle;
  - a removed service has volumes. The user deletes the service on its page
    to delete its data, or adds the service back;
  - a volume moves between one service and a volume that services share,
    because Coolify cannot move the data;
  - services that share a volume run on different servers.
- **Summary.** `node_stacks.compose_changes` keeps the last sync: the
  commit, the added, changed, and removed services, and the kept volumes.
  The stack page shows it.
- **Not synced.** A template stack (no Git source) does not change. The
  generated `SERVICE_*` values stay; only new ones are generated. A deploy
  with the same file does not sync. A removed service whose delete fails
  stays, and the team gets a notification. The job timeout of a deploy
  counts only the services that existed before the sync.
