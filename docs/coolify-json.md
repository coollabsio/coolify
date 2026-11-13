# coolify.json

A `coolify.json` file in a git repository defines the resources of a project environment: applications, standalone databases, and services, with their variables, storages, file mounts, scheduled tasks, backups, tags, and settings. Coolify keeps the environment in sync with the file. The file is the full truth for the resources that it manages.

## Quick start

1. Export the environment: `Config as code` > `Export`, or `GET /api/v1/environments/{uuid}/config/export`. The export has no secret values.
2. Commit the file as `coolify.json` to the branch of the environment.
3. Link the environment: `Config as code` > `Connect repository` (repository, branch, file path, git source, and the server that reads the file). Only a team admin can link.
4. Push. Coolify plans the file. The first plan of an export only adopts the existing resources (`import`), so nothing changes.

When you add a new repository that has a `coolify.json`, the add form offers `From coolify.json` (the default) or `Configure manually`. `From coolify.json` creates the resources from the file and links the environment in one step.

## The file

```json
{
    "$schema": "https://coolify.io/schemas/coolify.v1.json",
    "version": 1,
    "project": "acme",
    "resources": {
        "api": {
            "type": "application",
            "server": "prod-1",
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
        "db": { "type": "postgresql", "server": "prod-1", "backups": [{ "frequency": "daily" }] }
    },
    "environments": {
        "production": { "branch": "main" },
        "staging": { "branch": "staging", "resources": { "api": { "domains": ["https://staging.acme.com"] } } }
    }
}
```

- `version` must be `1`. Unknown keys fail the plan, except keys that start with `x-`. `minimum_coolify_version` stops older instances.
- `resources` is keyed by a stable key (lowercase, `a-z0-9-`). Coolify stores the key on the resource, so renaming the resource name does not lose data. `type` is `application`, `service`, or a database type (`postgresql`, `mysql`, `mariadb`, `mongodb`, `redis`, `keydb`, `dragonfly`, `clickhouse`, `sqlite`).
- `server` names an existing server of the team. The name must match exactly one server. The file never creates or configures servers.
- `environments` lists the environments and their branches. Each environment tracks its own branch, and a push changes only the environment of that branch. Per-environment `resources` override the shared ones: objects merge by key, lists replace, and `null` removes.
- The full schema is `public/schemas/coolify.v1.json` (`php artisan project-config:schema` writes it).

### Variables

A variable never holds a secret value. Each variable uses exactly one form:

| Form | Meaning |
| --- | --- |
| `value` | A plain value from the file. |
| `required` | Set the value in Coolify. The variables list shows `Needs a value` until it is set. |
| `generate` | Coolify generates `password`, `base64`, or `uuid` once. Increase `rotation` to generate a new one. |
| `ref` | `team.X`, `project.X`, `environment.X`, `server.X`, `vault.X` (secret manager), or `resource.<key>.internal_url`, `host`, or `port`. |

Flags: `buildtime`, `runtime`, `literal`, `multiline`. A `preview` object sets the value for preview deployments. Without it, previews follow production. Variables that are not in the file are removed after approval.

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

Same-repository pull requests get a plan comment. Forks get nothing. Roll back with `git revert`.

## Managed resources

- Settings that the file manages are read-only in Coolify and in the API. Deploy, logs, terminal, and setting `required` values still work.
- A team admin can pause the sync (`Pause sync`). Paused resources can be edited again. `Resume sync` shows a plan first.
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
| `POST /config/links`, `PATCH /config/links/{uuid}` | `write` |
| `GET /config/links/{uuid}`, `GET /config/links/{uuid}/revisions` | `read` |
| `DELETE /config/links/{uuid}` | `write` + `delete` |
| `POST /config/links/{uuid}/apply` | `write` + `deploy` |
| `POST /config/links/{uuid}/suspend`, `/resume` | `write` |
| `POST /config/revisions/{uuid}/approve` | `write` + `deploy` + `delete` |
| `POST /config/revisions/{uuid}/release`, `/retry` | `write` |

A link in `api` mode has no repository: `POST /config/links/{uuid}/apply` with the file applies it. For a git link, `apply` without a file plans the head of the branch.
