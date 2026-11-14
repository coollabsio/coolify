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
- **Templates on Nodes.** 291 of the 388 templates run on Nodes, not 316:
  - 20 templates share a volume between two services, or use `volumes_from`.
    A Node volume belongs to one workload (decision 0007).
  - 5 more templates have a service name with `_`, for example `rallly_db`.
    Sentinel rejects the whole name table for one name with `_`.
  - The decision counts `.yaml` files only. `templates/compose` also has 4
    `.yml` templates.
- **Traffic.** Sentinel firewall rules need a port, and no all-ports rule
  exists. Coolify sends `stack_rules` (`source_ip`, `destination_ip`) in
  `network.firewall.reconcile` only to Nodes that report
  `network.firewall.stack_rules.v1`. Sentinel does not have that capability
  yet, so the services of a stack reach each other only through firewall
  rules that the user adds.
- **Entrypoint.** A workload has no entrypoint field. The Compose
  `entrypoint` becomes the first words of the command, after the entrypoint
  of the image. A note on the service says this.
- **Ignored keys.** `user`, `shm_size`, `ulimits`, `tmpfs`, `stop_grace_period`,
  `deploy`, `tty`, and `stdin_open` are ignored, with a note on the service.
  Read-only volumes are mounted read-write. A service with `profiles` is not
  created.
- **Health check.** The Compose health check is kept in
  `node_workloads.stack_health_check`. It applies until the user turns on the
  Coolify health check of the application.
- **One-time services.** A service that another service waits for with
  `service_completed_successfully` gets restart policy `no`. The reconciler
  does not start a one-time container again after it ran.
- **Deploy order.** A stack deploy deploys one service after another, in
  `depends_on` order, through the normal deployment of each application. The
  first failure stops the deploy and names the services that were not
  deployed. A dependency cycle fails when the stack is created.
- **Images.** An image without a tag gets `:latest`, as the container
  inventory reports it.
