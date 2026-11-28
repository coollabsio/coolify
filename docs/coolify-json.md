# coolify.json

A `coolify.json` file in a git repository defines the resources of a project environment: applications, standalone databases, and services, with their variables, storages, file mounts, scheduled tasks, backups, tags, and settings. Coolify keeps the environment in sync with the file. The file is the full truth for the resources that it manages.

## Quick start

1. Export the environment: `Config as code` > `Export`, or `GET /api/v1/environments/{uuid}/config/export`. The export has no secret values.
2. Commit the file as `coolify.json` to the branch of the environment.
3. Link the environment: `Config as code` > `Connect repository` (repository, branch, file path, git source, and the server). Only a team admin can link. The server reads the file, and resources that name no server run on it.
4. Push. Coolify plans the file. The first plan of an export only adopts the existing resources (`import`), so nothing changes.

## Use the file as a template

When you add a new repository that has a `coolify.json`, the add form offers three choices:

| Choice | Result |
| --- | --- |
| `Use coolify.json` (default) | Coolify creates the resources with the settings of the file one time. They are normal resources: you edit them in Coolify, and later changes of the file do not change them. |
| `Config as code` | Coolify creates the resources and links the environment. The file manages them, and each push updates them. Only a team admin can link. |
| `Configure manually` | Coolify does not use the file. |

With `Use coolify.json`, a repository can give its own default settings (build pack, ports, health check, variables, a database) to everyone who deploys it:

- `environments` is optional. When the file has an environment for the branch, or with the name of the Coolify environment, its overrides apply.
- An application without `source.repository` builds the repository that you add, with its branch and git source. A fork or a copy of the repository builds itself.
- Resources without `server` go to the server that you selected. `moved`, `unmanage`, and `import` are ignored.
- `required` variables are created empty and marked required. `generate` values are generated one time. Nothing deploys until you deploy.
- The user needs the right to create resources in the environment. A git source or a deploy key also needs write access to the whole team. An environment that is linked to a `coolify.json` cannot use a template: add the resources to its file instead.
- The API does the same: `POST /environments/{uuid}/config/template/plan` shows the plan, and `POST /environments/{uuid}/config/template` with the `commit` of the plan creates the resources.

## The file

```json
{
    "$schema": "https://cdn.coollabs.io/coolify/schemas/coolify.v1.json",
    "version": 1,
    "resources": {
        "api": {
            "type": "application",
            "source": { "repository": "https://github.com/acme/api", "branch": "main" },
            "build": { "pack": "dockerfile" },
            "ports": { "exposes": "3000" },
            "domains": ["https://api.acme.com"],
            "env": {
                "NODE_ENV": { "value": "production" },
                "SESSION_SECRET": { "generate": "password", "length": 64 },
                "STRIPE_KEY": { "required": true },
                "DATABASE_URL": { "ref": "resource.db.internal_url" },
                "SENDGRID_KEY": { "ref": "team.SENDGRID_KEY" }
            }
        },
        "db": { "type": "postgresql", "server": "db-1", "backups": [{ "frequency": "daily" }] }
    },
    "environments": {
        "production": { "branch": "main" },
        "staging": { "branch": "staging", "resources": { "api": { "domains": ["https://staging.acme.com"] } } }
    }
}
```

- `version` must be `1`. Unknown keys fail the plan, except keys that start with `x-`. `minimum_coolify_version` stops older instances.
- `resources` is keyed by a stable key (lowercase, `a-z0-9-`). Coolify stores the key on the resource, so renaming the resource name does not lose data. `type` is `application`, `service`, or a database type (`postgresql`, `mysql`, `mariadb`, `mongodb`, `redis`, `keydb`, `dragonfly`, `clickhouse`, `sqlite`).
- The link decides the project, the environment, and the default server. So one file works in any project, team, or instance. `project` is an optional label, and Coolify does not compare it with the linked project.
- `server` is optional. Without it, the resource runs on the server of the link (and on its destination when that server has more than one). With it, the name must match exactly one server of the team. A per-environment override can set it too. The file never creates or configures servers.
- Git sources (`source.github_app`, `source.gitlab_app`, `source.gitea_app`), deploy keys (`source.deploy_key`), S3 storages (`backups[].s3_storage`), and integration tokens (`secret_manager.token`) are found by name, so the file works on every instance that has them. The name must match exactly one of them. A credential of the team wins over a system-wide git app with the same name.
- `environments` lists the environments and their branches. Each environment tracks its own branch, and a push changes only the environment of that branch. Per-environment `resources` override the shared ones: objects merge by key, lists replace, and `null` removes.
- The full schema is `public/schemas/coolify.v1.json` (`php artisan project-config:schema` writes it). It is published at `https://cdn.coollabs.io/coolify/schemas/coolify.v1.json` from the coollabs-cdn repository (`json/coolify/schemas/coolify.v1.json`). Copy it there again when the field maps change.

### Variables

A variable never holds a secret value. Each variable uses exactly one form:

| Form | Meaning |
| --- | --- |
| `value` | A plain value from the file. |
| `required` | Set the value in Coolify. The variables list shows `Needs a value` until it is set. |
| `generate` | Coolify generates `password`, `base64`, or `uuid` once. Increase `rotation` to generate a new one. |
| `ref` | `team.X`, `project.X`, `environment.X`, `server.X`, `vault.X` (secret manager), or `resource.<key>.internal_url`, `host`, or `port`. |

Flags: `buildtime`, `runtime`, `literal`, `multiline`. For a Docker Compose application or service, `containers` (for example `["app", "worker"]`) limits the variable to these Compose services. Without it, every container gets the variable. The plan fails when a service has no Compose service with that name, and when the resource is not Docker Compose. For a Compose application, the plan warns when the Compose file that Coolify loaded last has no service with that name. A `preview` object sets the value for preview deployments. Without it, previews follow production. Variables that are not in the file are removed after approval.

### Secret manager

`secret_manager` links an integration token by name (`token`) with the `settings` of its provider. The plan fails when a setting is unknown for the provider, or when a required setting is missing.

| Provider | Settings |
| --- | --- |
| Infisical | `project_id` and `environment` (required), `secret_path` (default `/`) |
| HashiCorp Vault | `mount` and `path` (required) |
| Doppler service account token | `project` and `config` (required) |
| Doppler service token, other providers | None |

### Resource limits

An application or a standalone database sets its CPU and memory limits in `limits`. The values use the Docker format.

```json
"limits": { "memory": "512m", "memory_reservation": "256m", "cpus": "1.5", "cpuset": "0,1" }
```

| Key | Default |
| --- | --- |
| `memory`, `memory_swap`, `memory_reservation` | `"0"` (no limit) |
| `memory_swappiness` | `60` |
| `cpus` | `"0"` (no limit) |
| `cpuset` | none |
| `cpu_shares` | `1024` |

A key that is not in the file goes back to its default. A service has no `limits`. Set `deploy.resources.limits` in its Compose file.

### Renames, hand-offs, and adoption

- `moved`: `[{ "from": "web", "to": "api" }]` renames a key without data loss.
- `unmanage`: `["old"]` stops managing a resource and keeps it running.
- `environments.<name>.import`: `[{ "to": "api", "uuid": "…" }]` adopts an existing resource.

## How a push works

1. A push to the linked branch reaches Coolify through the GitHub, GitLab, Gitea, or Bitbucket webhook (app or manual, with the secret from `Config as code` > `Set up a webhook`).
2. Coolify reads the file at that commit on the read server and plans it. The plan never shows secret values.
3. Safe changes apply automatically when `Apply safe changes automatically` is on. A plan with a destructive change (a removal, a replaced resource, a removed variable or storage) waits for approval. Deleting the data of a removed resource is a separate choice in the approval.
4. Deploys from the same push wait until the apply is done. A later push replaces a waiting plan.
5. A plan is never partly applied. An error fails the whole revision, and `History` shows the reason. `Retry` plans the commit again.

Same-repository pull requests of a GitHub app get a plan comment. Forks get nothing. Roll back with `git revert`.

## Managed resources

- Settings that the file manages are read-only in Coolify and in the API. Deploy, logs, terminal, and setting `required` values still work.
- A team admin can pause the sync (`Pause sync`). Paused resources can be edited again. `Resume sync` shows a plan first.
- `Change server` in the `Source` section (team admin) sets another default server. Coolify plans the file again. Resources without `server` that move wait for approval. After the approval, Coolify stops them on the old server and starts or deploys them on the new one. Volumes and their data stay on the old server. The API does the same with `PATCH /config/links/{uuid}` and `server_uuid`, which needs the `deploy` ability.
- `Disconnect` removes the link. The resources stay and can be edited again.
- Pages show the state: a sync dot on the project page, a badge and a banner on the environment page, a `coolify.json` chip in the top bar of a managed resource, and tags in the variables list.

## Permissions

- Linking, pausing, resuming, disconnecting, and approving need a team admin. Approving destructive changes asks for the password.
- Export needs a team admin, or an API token with `read:sensitive`, because commands and Compose files can hold credentials. Without it, plans and history show which settings change, but not their values.
- Anyone who can push to the linked branch can change the resources of the environment and use the shared variables of the team. Protect the branch.

## API

| Method and path | Ability |
| --- | --- |
| `GET /environments/{uuid}/config/export` | `read` + `read:sensitive` |
| `POST /environments/{uuid}/config/plan` | `read` |
| `POST /environments/{uuid}/config/template/plan`, `POST /environments/{uuid}/config/template` | `write` |
| `POST /config/links`, `PATCH /config/links/{uuid}` | `write` (`server_uuid` in `PATCH` also needs `deploy`) |
| `GET /config/links/{uuid}`, `GET /config/links/{uuid}/revisions` | `read` |
| `DELETE /config/links/{uuid}` | `write` + `delete` |
| `POST /config/links/{uuid}/apply` | `write` + `deploy` |
| `POST /config/links/{uuid}/suspend`, `/resume` | `write` (`/resume?confirm=1` also needs `deploy`) |
| `POST /config/revisions/{uuid}/approve` | `write` + `deploy` + `delete` |
| `POST /config/revisions/{uuid}/release` | `write` |
| `POST /config/revisions/{uuid}/retry` | `write` + `deploy` |

A link in `api` mode has no repository: `POST /config/links/{uuid}/apply` with the file applies it. For a git link, `apply` without a file plans the head of the branch.
