# Infisical: single connection, continuous sync, locked local editing

Supersedes the Infisical half of
`2026-09-19-infisical-and-registry-credentials-design.md`. The container-registry
half of that document is unaffected and remains in force.

## Problem

The shipped design requires an operator to create an `InfisicalBinding` for every
Coolify environment before any secret flows. That is manual adoption, repeated
per environment, and it is the thing being removed. It also only ever covered
*shared* environment variables — a resource's own variables were never synced,
so Infisical never held the full picture of what a deployment actually runs
with.

## Goals

- Configure Infisical **once per team**. No per-environment setup, ever.
- Existing Coolify variables move into Infisical automatically, with no manual
  migration step.
- Coolify pulls continuously thereafter, and always pulls fresh at deploy.
- Infisical becomes the only place a human edits a secret. Coolify's own
  variable editing is locked.
- Coolify's self-generated variables keep working, and are visible in Infisical.

## Non-goals

- Deleting secrets. See "Deletion" below — this is a deliberate, recorded
  limitation, not an oversight.
- Webhook-driven push from Infisical. Polling plus deploy-time pull only.
- Multiple Infisical connections per team.
- Migrating existing `InfisicalBinding` rows. The feature is unreleased; the
  binding table is dropped, not converted.

## Decisions

| Question | Decision |
|---|---|
| Adoption of existing vars | Auto-push up on first enable, then lock. One upward moment. |
| Connection scope | One per team. Unique index on `team_id`. |
| Infisical project | One per Coolify team, chosen at configure time. |
| Coolify environment | Maps to a **native Infisical environment**, auto-created if absent. |
| Folder layout | `/` team · `/{project}/` project+env · `/{project}/{resource}/` resource. |
| Precedence | Resource overrides project overrides team, on key conflict. |
| Sync cadence | Scheduled poll **and** a fresh pull at the top of every deploy. |
| Deletion | Never. Additive and update-only. |
| Lock scope | Human edits rejected; Coolify-generated writes allowed and pushed up. |
| Lock enforcement | Server-side, in Livewire components and API controllers. |

## Data model

### `infisical_connections` (modified)

Adds `infisical_project_id`, `is_enabled`, `adopted_at`, and retains
`last_synced_at` / `last_sync_status` / `last_sync_error`, which move here from
the dropped binding table. `client_id` and `client_secret` keep the `encrypted`
cast and `$hidden`; normalization stays in the `saving` event, because mutators
cannot combine with the `encrypted` cast.

A unique index on `team_id` enforces one-per-team. Note that `id = 0` is the
instance sentinel and must never be assigned to a new row.

### `infisical_bindings` (dropped)

Table, model, factory, policy, and the `infisical_binding_id` column on
`shared_environment_variables` are all removed.

### Ownership marking (new, on two tables)

Both `shared_environment_variables` and `environment_variables` gain:

- `is_infisical_managed` (boolean, default false)
- `infisical_path` (string, nullable) — the folder the value came from

`environment_variables` is polymorphic over `resourceable_type` /
`resourceable_id`; every resource type that carries variables is in scope.

## Path derivation

`App\Services\Infisical\InfisicalPath` — a pure class, no I/O, exhaustively unit
tested.

```
project     = connection.infisical_project_id
environment = slug(coolify environment name)     ← native Infisical environment
path:
  /                                  team-wide shared variables
  /{project-slug}/                   project- and environment-level shared vars
  /{project-slug}/{resource-name}/   that resource's own variables
```

Slugging must be deterministic and collision-aware: two Coolify resources whose
names slug identically within one project is an error surfaced to the operator,
not a silent merge.

## Sync

### Upward, once — `AdoptTeamSecretsIntoInfisical`

On first enable, walks team → projects → environments → resources; creates
missing Infisical environments and folders; pushes every existing variable.
Idempotent, so retry after partial failure is safe. Stamps `adopted_at` only on
full success.

### Upward, continuing — system variables only

Coolify generates `SERVICE_PASSWORD_*`, `SERVICE_FQDN_*`, `SERVICE_USER_*`,
`SERVICE_BASE64_*` and friends through `generateEnvValue()`, plus the database
credential columns above. Writes made inside `asSystem()` are pushed to
Infisical so Infisical holds the complete picture.

Two things are explicitly **out of scope**:

- `COOLIFY_URL`, `COOLIFY_FQDN`, `COOLIFY_BRANCH`, `COOLIFY_RESOURCE_UUID`,
  `COOLIFY_CONTAINER_NAME` and `SOURCE_COMMIT` are computed fresh at deploy
  time and never persisted as rows. They are not secrets and are not synced.
- `parse()` uses `firstOrCreate`, so re-running it never overwrites an existing
  value. Generation is idempotent and a repeat `parse()` must not produce a
  repeat push.

Re-entrancy guard: a downward pull writes inside `asSystem()` too, so the push
hook must distinguish "system generated locally" from "system wrote this
because Infisical said so" and skip the push in the latter case. Without this,
every pull triggers a push and the two sync directions feed each other.

### Downward, continuously — `PullTeamSecrets`

A scheduled job per enabled connection, plus a fresh pull at the top of
`ApplicationDeploymentJob`. Merge semantics:

- Key present in Infisical, absent in Coolify → **create**, marked managed.
- Key present in both → **update** Coolify's value.
- Key absent from Infisical, present in Coolify → **leave untouched**.

Registration goes in `app/Console/Kernel.php::schedule()`. This repo's
`routes/console.php` has no schedule block.

### Deletion — recorded limitation

Removing a secret in Infisical does **not** remove it from Coolify or from
running deployments. Revoking a leaked credential therefore requires a manual
step in Coolify as well. This was chosen explicitly over mirroring deletions.
The settings UI must state it plainly rather than leave operators to discover
it.

## The lock

Armed whenever the team has an enabled connection.

### Enforcement point

Enforcement lives in the **Eloquent `saving` and `deleting` hooks** of
`EnvironmentVariable` and `SharedEnvironmentVariable`, not at the call sites.
Call-site enforcement was investigated and rejected: it leaks in at least five
places.

| Leak | Why a call-site lock misses it |
|---|---|
| `EnvironmentVariable::booted()` `created` observer | Silently clones every new production variable into a preview row — a second write the caller never made. |
| `StandaloneRedis::redisUsername()` accessor | **Creates a row as a side effect of a read.** Rendering a page can write. |
| `applicationParser()` / `serviceParser()` via `parse()` | Fires from ~20 call sites including every deployment, every domain save, and clone. |
| The Infisical pull itself | Runs from a scheduled job with no HTTP request in scope. |
| `ServerTransferImporter` | Wraps writes in `withoutEvents()`. |

The last one also bypasses model hooks, so the importer must check the lock
**explicitly** — a model-hook lock alone will not stop it. Bulk import is
treated as a human edit, not a system write, because it copies another
instance's data rather than generating Coolify's own.

### The bypass

A scoped context — `InfisicalLock::asSystem(callable $write)` — marks a write
as Coolify-generated. Inside it, the hooks allow the write; outside, they reject
it when the lock is armed. Static scoping, not a model attribute, because the
same row may be written by both a human and the system at different times.

Paths that must be wrapped in `asSystem()`:

- `applicationParser()`, `serviceParser()`, `parseDockerComposeFile()`
- `EnvironmentVariable::booted()` preview-clone and version stamp
- `Application::booted()` — `NIXPACKS_NODE_VERSION`, buildpack-switch cleanup
- `Server::booted()` — `COOLIFY_SERVER_UUID`, `COOLIFY_SERVER_NAME`
- `StandaloneRedis::redisUsername()` accessor
- One-click template seeding in `ServicesController::create_service` and
  `Livewire/Project/Resource/Create::mount()`
- Resource-deletion cascades (`forceDeleting` on every `Standalone*` model,
  `DeleteService`)
- The Infisical pull action itself

Paths that must be **rejected** when armed:

- `app/Livewire/SharedVariables/{Team,Project,Environment,Server}/*` —
  `saveKey()`, `submit()`
- `app/Livewire/Project/Shared/EnvironmentVariable/{Add,All,Show}` —
  `submit()`, `delete()`, `lock()`
- `Service::saveExtraFields()`, reached from `StackForm::submit()`
- All 24 `api.ability:write` env endpoints across
  `SharedEnvironmentVariablesController` (12), `ApplicationsController` (4),
  `DatabasesController` (4), `ServicesController` (4)
- `ServerTransferImporter` — explicit check, since `withoutEvents()` evades the
  hook

Because the hook is the control, the UI and API layers only need to render the
read-only state and return a clean error. They are not the security boundary.
`@can` in Blade is presentation only and is never the control.

### Database credential columns

Managed database passwords are **not** environment variables for 7 of 8
engines — `postgres_password`, `mysql_root_password`, `mysql_password`,
`mariadb_root_password`, `mariadb_password`, `mongo_initdb_root_password`,
`keydb_password`, `dragonfly_password`, `clickhouse_admin_password` are
encrypted columns on the `Standalone*` models. Only Redis additionally writes
`REDIS_PASSWORD` and `REDIS_USERNAME` as variable rows.

These columns are in scope: pushed up during adoption, written back by the
pull, and locked read-only on each database's General page. The same
`saving`-hook enforcement applies, keyed to those specific columns.

This carries Coolify's existing semantics unchanged: **changing a database
password takes effect on redeploy and does not rotate a running database.**
The UI must say so.

Managed rows render read-only with an Infisical badge and a deep link to the
secret in Infisical.

## Error handling

- Connection failure during a **deploy** pull fails the deploy. It does not
  silently proceed with stale values.
- Connection failure during a **scheduled** pull records
  `last_sync_status` / `last_sync_error` and leaves stored values in place.
- `secretValueHidden` from the Infisical API means the identity cannot read the
  value. Such a secret is skipped, never written as empty.
- Missing Infisical environment is auto-created. If the machine identity lacks
  permission, the sync degrades to skip-and-warn for that environment rather
  than failing the whole run.

## Security constraints

Carried forward, non-negotiable:

- Never use `remote_process()` on a credential path — it persists the full
  unredacted command to the activities table and renders it in the UI.
- `instant_remote_process` logs the first 100 characters as `command_preview`.
- Undefined policy abilities throw rather than deny.
- Authorization is `isAdminOfTeam($teamId)`, never session-scoped `isAdmin()`,
  which lets an admin of one team reach another team's records.
- Never repopulate credential fields into public Livewire properties; they
  serialize into `wire:snapshot`.

## Testing

- `InfisicalPath` — pure unit tests, including slug collisions.
- Adoption — idempotency, partial-failure retry, `adopted_at` only on success.
- Merge — the three cases above, especially that absent-from-Infisical is a
  no-op.
- Lock — one test per surface proving a **human** write is rejected and a
  **system** write succeeds. API tests assert HTTP status, not UI state.
- Re-entrancy — a downward pull does not trigger an upward push.
- No credential appears in any rendered Livewire snapshot.

Tests run under `QUEUE_CONNECTION=sync` (`phpunit.xml`), which hides queued-job
races. Any code path that enqueues must be reasoned about explicitly, not
assumed correct because tests pass.

## Infisical API surface (verified)

Verified against the live OpenAPI document at
`https://app.infisical.com/api/docs/json`. Field names below are exact.

| Operation | Endpoint | Body / query |
|---|---|---|
| Authenticate | `POST /api/v1/auth/universal-auth/login` | `clientId`, `clientSecret` |
| Read secrets | `GET /api/v3/secrets/raw` | `workspaceId`, `environment`, `secretPath` |
| **Upsert secrets** | `PATCH /api/v4/secrets/batch` | `projectId`, `environment`, `secretPath`, `secrets[]`, `mode: "upsert"` |
| List environments | `GET /api/v1/projects/{projectId}` | read `project.environments[] = {id,name,slug}` |
| Create environment | `POST /api/v1/projects/{projectId}/environments` | `name`, `slug`, optional `position` |
| List folders | `GET /api/v2/folders` | `projectId`, `environment`, `path` (all required) |
| Create folder | `POST /api/v2/folders` | `projectId`, `environment`, `name`, optional `path` |

Three findings change the implementation:

1. **`PATCH /api/v4/secrets/batch` with `mode: "upsert"` removes all
   create-vs-update branching.** Every upward write uses this one call.
2. **There is no list-environments endpoint.** Environments are read from the
   project object, not a dedicated collection route.
3. **Recursive parent-folder creation is NOT documented and must not be
   assumed.** `ensureFolderPath()` walks the path segment by segment, creating
   each level idempotently and tolerating an already-exists error.

### Machine identity permissions

The identity must be added to the Infisical project under Access Control and
hold a role granting: `environments:create`, `secret-folders:create` and read,
`secrets:create` / `secrets:edit`, and — critically — **`secrets:readValue`**,
which is a distinct, newer permission from generic secret read. Without
`readValue`, the API returns `secretValueHidden: true` and masks the value
rather than failing, which is why that flag must be checked rather than
assuming `secretValue` is populated.

Environment creation is additionally subject to the organization's plan
environment-count limit. Behaviour of that limit on self-hosted OSS
deployments is unconfirmed; treat a creation failure as a degradation to
skip-and-warn, never as a hard sync failure.

Self-hosted instances expose the same API shape; only the base URL differs.

## Compatibility

The feature is unreleased, so no migration path is owed to existing binding
rows. The drop migration removes them.
