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
- **Clusters and Nodes.** List and show. For admins: set the ingress and builder
  roles of a Node, start an image cleanup, and upgrade Sentinel.

## Consequences

- Each write of a cluster resource has one implementation, which the UI and the
  API share.
- The OpenAPI files (`openapi.json`, `openapi.yaml`) list the new endpoints.
- An MCP tool or a CLI command for cluster resources can use these endpoints.

## Not decided here

- Volumes and volume backups of cluster applications in the API.
- Scheduled tasks, the terminal, and exec through the API.
- The cluster settings (network, firewall rules, image storage) in the API.
- Create and delete of clusters and Nodes through the API.
