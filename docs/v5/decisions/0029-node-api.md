# Decision 0029: REST API for cluster resources

**Status: Accepted**

**Date: 2026-11-16**

## Context

The v4 REST API (`/api/v1`) manages applications, databases, services, and
servers. Cluster resources (decisions 0004 and 0010) have only two endpoints:
the revisions and the rollback of a cluster application. A user cannot create,
change, deploy, or delete a cluster application, database, or stack without
the UI, and an API token cannot read clusters or Nodes.

Most writes of the cluster pages already go through actions. Some are still in
the Livewire components: the deploy of an application, the lifecycle actions,
the move, the save of the settings, the Git create step, the stack deploy, and
the backup schedule of a database.

## Decision

1. **Paths in the v4 style.** `/api/v1/cluster-applications`,
   `/api/v1/cluster-databases`, `/api/v1/cluster-stacks`, `/api/v1/clusters`,
   and `/api/v1/nodes`. A path has the UUID of the resource. A field has the
   v4 name when it means the same thing (`name`, `description`,
   `project_uuid`, `environment_uuid`, `git_repository`, `git_branch`,
   `build_pack`, `base_directory`, `dockerfile_location`,
   `docker_registry_image_name`, `docker_registry_image_tag`, `domains`,
   `image`). A field that means something else gets its own name: the resource
   limits are `cpu_limit`, `cpu_reservation`, `memory_limit_mb`, and
   `memory_reservation_mb`, not the Docker strings of v4 `limits_*`.
2. **v4 conventions.** Inline `Validator` (`customApiValidator`), OpenAPI
   attributes, `serializeApiResponse()`, the `ApiSensitiveData` masking, and the
   `api.ability` middleware: `read` for reads, `write` for changes, `deploy`
   for deploy, start, stop, restart, and rollback, and `delete` for each delete.
3. **One place for the rules.** Each write calls the action that the Livewire
   page calls. The actions keep the validation of the domain, the placement,
   `NodePolicy::placeResource`, `useTeamCredentials`, the Docker socket check,
   and the capability checks. The Livewire-only logic moves into actions first,
   without a change of behavior. The controllers validate only the shape of the
   request.
4. **Authorization.** Each call authorizes the token user with the policy of
   the resource (`NodeWorkloadPolicy`, `NodeStackPolicy`, `NodeClusterPolicy`,
   `NodePolicy`). Each lookup is in the team of the token, and the access grants
   of a member and of a token apply (`VisibleThroughAccessGrants`). A resource
   of another team, or one that the grants hide, gives 404. Each submitted ID
   (project, environment, cluster, Node, GitHub App, private key, registry
   credential, S3 storage) must belong to the team of the resource. A refused
   placement (no write access on the environment, or no `placeResource` on the
   server) is a `NodePlacementDeniedException`, a `RuntimeException` that the
   pages show as before, and the API answers 403.
5. **Secrets.** A value of an environment variable, a database credential, and
   the internal database URL show only when `ApiSensitiveData` allows it (a
   team admin with a `root` or `read:sensitive` token) and the user can view
   sensitive values of the resource. A locked value never shows.
6. **Admin-only Node writes.** The roles of a Node (ingress, builder), the image
   cleanup, and the Sentinel upgrade need a team admin, as on the Node page.
   `/clusters` and `/nodes` are team-level routes, like `/servers`: a token
   that access grants limit cannot use them (`ApiAbility::SCOPED_ACCESS_ROUTES`
   has only the three resource paths).
7. **Stack services.** `/cluster-applications` lists the cluster applications
   without previews and stack services. A stack lists its services. The
   environment variables of a stack belong to its services, so each stack
   environment variable call names the service.
8. **Compose of a stack.** A stack from Git reads its Compose file from the
   repository at each deploy. A stack from a template gets a new Compose file
   through `PATCH /cluster-stacks/{uuid}`, with the same three-way rule as a Git
   sync (decision 0023).
9. **Volumes, volume backups, and scheduled tasks.** The three resource paths
   (`/cluster-applications`, `/cluster-databases`, `/cluster-stacks`) have the
   same calls. A stack call names the service (`service` in the query or the
   body). Without `service`, the volume list and the volume delete of a stack
   work on the volumes of the stack (decision 0024). The calls use the actions
   of the storage pages (`CreateNodeVolume`, `UpdateNodeVolume`,
   `RemoveNodeVolume`, `DeleteNodeVolumeData`, `ManageNodeVolumeBackups`), so
   the rules of decisions 0007 and 0009 apply: a volume is a Podman volume with
   an absolute container path (no host bind mount), a backup goes to one S3
   storage of the team, and a restore stops the workload, replaces the data,
   and starts the workload again. A rename keeps the data, because the Podman
   name of a volume is its UUID. The scheduled task calls are the v4 calls with
   the v4 fields. `command` and the output of a run (`message`) show only when
   the user can view sensitive values of the resource. An enabled task, a
   backup that stops the workload, a backup run, and a restore need the
   `deploy` ability.
10. **Cluster settings.** `PATCH /clusters/{uuid}` changes the fields of the
    General and Advanced tabs: name, description, CIDR, WireGuard interface and
    port, the deployment pressure policy, the image storage (repository and
    registry credential). (History: the server of the built-in registry was
    a field until decision 0030 removed `registry_server_uuid`.) The roles of
    a Node stay on `PATCH /nodes/{uuid}/roles`. The firewall rules have their
    own calls. Each write needs a team admin (`NodeClusterPolicy::update`). A
    registry credential must be of the team of the cluster and of the registry
    of the repository. No response shows a credential value, only its UUID.
11. **Create and delete of clusters.** `POST /clusters` and
    `DELETE /clusters/{uuid}` call `CreateNodeCluster` and `DeleteNodeCluster`.
    `POST /clusters/{uuid}/servers` and `DELETE /clusters/{uuid}/servers/{uuid}`
    call `AssignNodeToCluster` and `RemoveNodeFromCluster` for a server that
    already is a Node of the team. They need a team admin. The UI asks for the
    password before it deletes a cluster. The API does not, as for the other
    deletes of v4, because the token is the proof.
12. **Node delete and the recovery actions of a cluster.** `DELETE /nodes/{uuid}`
    calls `DeleteNode`, the action of the Node page: the Node must be in no
    cluster and run no applications, else the API answers 400 with the message
    of the page. A Node that still holds volumes or volume copies is refused
    with the counts, also on the page. `force=true` (on the page, a second
    confirmation) deletes it for a server that is gone for good, and writes a
    `node.force_deleted` warning with the counts to the audit log. The delete
    removes only the record. Coolify runs no command on the server, so Sentinel
    and the data stay on it, and Coolify cannot remove that data after the
    delete. A Node has no server
    transfer, so the rule of `Server::isTransferredAway()` does not apply.
    `POST /clusters/{uuid}/network/reconcile`, `.../network/repair`, and
    `.../sentinel/upgrade` call `ManageNodeClusterNetwork`, the action of the
    cluster page. The repair runs over SSH and waits for each server. The
    Sentinel upgrade runs one server at a time (decision 0013), and answers 400
    when each server is up to date. Each call needs a team admin.

## Endpoints

- **Cluster applications.** List, show, create (`/docker-image`, `/public`,
  `/private-github-app`, `/private-deploy-key`, `/dockerfile`), update, delete,
  deploy, start, stop, restart, revisions, rollback, environment variables
  (list, create, update, bulk, delete), domains, resource limits, published
  ports, move, logs, and previews (list, deploy).
- **Cluster databases.** List, show, create (one path for each engine), update,
  delete, start, stop, restart, environment variables, and backups (list,
  create, change, and delete a schedule, run now, list the runs). Credentials
  apply at the next deploy. As in v4, a changed database name (for example
  `POSTGRES_DB`) does not change `databases_to_backup` of a schedule, because
  the engine reads the name only for a new data volume.
- **Cluster stacks.** List, show, create (`/template`, `/git`), update the
  Compose file, delete, deploy, start, stop, restart, environment variables, and
  services.
- **Volumes (each resource path).** List, create, update (name, mount path),
  delete (`delete_data=true` also deletes the data), and the volume backups:
  list, create, change, and delete a schedule, run now, list the runs, and
  restore a run.
- **Scheduled tasks (each resource path).** List, create, update, delete,
  execute now, and list the runs with their output.
- **Clusters and Nodes.** List and show. For admins: create, change the
  settings, and delete a cluster, add a server to a cluster and remove it, list,
  add, and delete the firewall rules, reconcile and repair the network, upgrade
  Sentinel on the cluster, delete a Node, set the ingress and builder roles of
  a Node, start an image cleanup, and upgrade Sentinel on a Node.

## Consequences

- Each write of a cluster resource has one implementation, which the UI and the
  API share.
- The OpenAPI files (`openapi.json`, `openapi.yaml`) list the new endpoints.
- An MCP tool or a CLI command for cluster resources can use these endpoints.

## Not in the API

- **Terminal and exec.** A terminal session is interactive. v4 has no exec
  call in its API, so the cluster API has none. A scheduled task with
  `execute` runs one command in the container of a workload.
- **Create of a Node.** A new Node needs the onboarding steps of the UI: an
  SSH key, an SSH check, and the install of Sentinel with the answers of the
  user. No shared action does these steps without the UI.
- **The delete of one backup run.** The page asks for the password, and the
  delete of the files in S3 is logic of the page.
