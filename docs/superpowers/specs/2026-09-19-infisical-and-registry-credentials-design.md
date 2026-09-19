# Infisical Secret Sync and Container Registry Credentials Design

## Problem

Coolify has no external secret manager integration and no registry credential
storage. Two consequences:

1. Secrets live only in Coolify's own `environment_variables` /
   `shared_environment_variables` tables. Teams that treat Infisical as the
   source of truth must copy values by hand.
2. Coolify never authenticates to a container registry. It assumes each managed
   node was pre-authenticated out of band: `prepare_builder_image()` checks for
   `~/.docker/config.json` and throws "Please run docker login to login to the
   docker registry on the server" when it is absent
   (`app/Jobs/ApplicationDeploymentJob.php:2309`). Deploying a private image
   therefore requires SSH access to every node.

## Goals

- Sync secrets from Infisical into Coolify, scoped to a Coolify environment.
- Make synced secrets reach containers without per-key wiring.
- Store container registry credentials in Coolify and inject them at pull and
  push time, so no operator ever runs `docker login` on a node.
- Keep the diff against `coollabsio/coolify` small and conflict-resistant.

## Non-goals

- Resolve-at-deploy secret fetching. Infisical is upstream; Coolify's database
  is the copy that deploys read. Deploys must survive an Infisical outage.
- Multi-node failover, scheduling, or placement. Out of scope entirely.
- Harbor-specific features: robot-account management, tag browsing, push
  webhooks. v1 is generic OCI registry auth that happens to work with Harbor.
- Secret provider abstraction. Infisical only; no plugin interface.
- Database resources (`app/Actions/Database/Start*.php`) do not receive
  auto-injected secrets in v1.

## Decisions

| Decision | Choice |
|---|---|
| Fetch model | Sync into Coolify's DB, Infisical upstream |
| Binding scope | Per Coolify environment |
| Injection | Auto-inject into every resource in the environment |
| Registry auth reach | Ephemeral `DOCKER_CONFIG` per operation |

## Data Model

### `infisical_connections`

Team-scoped Infisical credentials. `team_id`, `name`, `host` (default
`https://app.infisical.com`), `client_id`, `client_secret`.

Authentication is Infisical Universal Auth (machine identity), not service
tokens — machine identities are scopable and rotatable.

`client_id` and `client_secret` use the `encrypted` cast and are listed in
`$hidden`, following `app/Models/S3Storage.php:35-46`. That model's `saving`
hook workaround (`:49-66`) must be reproduced: mutators cannot be combined with
the `encrypted` cast, so normalization happens in the `saving` event.

### `infisical_bindings`

`infisical_connection_id`, `environment_id`, `infisical_project_id`,
`infisical_environment_slug`, `secret_path` (default `/`), `is_enabled`,
`last_synced_at`, `last_sync_status`, `last_sync_error`.

Unique on `environment_id` — one binding per Coolify environment.

### `shared_environment_variables.infisical_binding_id`

New nullable FK column on the existing table. This is the provenance marker:

- Sync only ever modifies or deletes rows whose `infisical_binding_id` matches
  the binding being synced.
- Rows with a null value are user-owned and are never touched.
- The UI renders non-null rows read-only with an Infisical badge.
- Auto-injection selects on it.

`shared_environment_variables` already carries a `type` enum of
`team|project|environment|server` and a unique constraint on
`[key, environment_id, team_id]`, so the per-environment scope and the sync
upsert key both already exist. Migration
`2025_12_24_095507_add_server_to_shared_environment_variables_table.php` is the
precedent for extending this table.

### `container_registries`

`team_id`, `name`, `url`, `username`, `password`, `is_enabled`,
`last_verified_at`, `last_verify_status`. `username` and `password` encrypted
and hidden, same S3Storage pattern.

No per-resource binding. Credentials are team-scoped and selected by matching
the image reference's registry host against `url`, using the existing
`app/Services/DockerImageParser.php`.

## Infisical Sync

`App\Services\Infisical\InfisicalClient` performs universal-auth login and
`GET /api/v3/secrets/raw`. Access tokens are held in memory for the request
only and are never persisted.

`App\Actions\Infisical\SyncEnvironmentSecrets` (lorisleiva Actions pattern, as
used throughout `app/Actions/`) takes a binding and:

1. Fetches secrets at `secret_path` for the bound Infisical project and
   environment.
2. Upserts `SharedEnvironmentVariable` rows with `type = 'environment'`, the
   binding's `environment_id`, the connection's `team_id`, and
   `infisical_binding_id` set.
3. Deletes rows owned by this binding whose key no longer exists upstream.
4. Records `last_synced_at`, `last_sync_status`, `last_sync_error`.

`App\Jobs\InfisicalSyncJob` runs it on a schedule: queued, `crons_queue()`,
`WithoutOverlapping` keyed by binding id — matching
`app/Jobs/ScheduledJobManager.php:43-60`.

**Deploy-time sync is non-fatal.** `ApplicationDeploymentJob` triggers a sync
before resolving variables, but any failure is logged as a warning and the
deploy proceeds with last-synced values. This is an invariant, not a fallback
detail: resilience to an Infisical outage is the reason the sync model was
chosen over resolve-at-deploy.

## Auto-Injection

Coolify's shared variables are not injected into containers; they only apply
when a resource-level variable references them as `{{environment.KEY}}`,
resolved at `app/Models/EnvironmentVariable.php:310`. Auto-injection changes
this for Infisical-owned rows.

Twelve distinct sites assemble environment variables. Rather than editing all
of them, a single resolver — `App\Actions\Infisical\ResolveInheritedSecrets` —
returns the key/value set for a resource's environment, and is called from:

- `generate_runtime_environment_variables()`
  (`app/Jobs/ApplicationDeploymentJob.php:1360`)
- `generate_buildtime_environment_variables()`
  (`app/Jobs/ApplicationDeploymentJob.php:1647`)
- `Service::saveComposeConfigs()` (`app/Models/Service.php:1569`)

**Precedence: a resource-level variable always wins over an inherited one.**
Overriding a single secret for one application must remain possible.

`app/Livewire/Project/Shared/EnvironmentVariable/All.php` and its Blade view
render inherited variables read-only with an Infisical badge, so the origin of
a value is visible where it is used.

## Registry Credential Injection

`App\Actions\Docker\WithRegistryAuth` is a single closure-scoped primitive
that guarantees cleanup:

```php
WithRegistryAuth::run($server, $imageRefs, function (string $dockerConfigDir) {
    // caller prefixes its command with DOCKER_CONFIG=$dockerConfigDir
});
```

`$imageRefs` is the collection of image references the operation will pull or
push. Their registry hosts select which of the team's `container_registries`
rows are written into the config; an empty selection means no config is written
and the callback runs with an empty `$dockerConfigDir`, leaving current
behaviour unchanged.

It creates a temp directory on the node, writes `config.json` at mode 600,
invokes the callback, and removes the directory in a `finally` block. Call
sites change by one `DOCKER_CONFIG=` prefix.

Three hard constraints:

1. **Never use `remote_process()`.** It persists the full, unredacted command
   string into the activities table and renders it in the UI
   (`bootstrap/helpers/remoteProcess.php:47-57`). `redact_sensitive_info()` has
   no pattern matching `-p <password>`
   (`bootstrap/helpers/remoteProcess.php:326-367`). Use
   `instant_remote_process` or `execute_remote_command` with
   `skip_command_log => true`.
2. **`instant_remote_process` logs the first 100 characters** of every command
   as `command_preview` on retry (`bootstrap/helpers/remoteProcess.php:198`).
   The config is written with a `cat > "$dir/config.json" <<'EOF'` heredoc so
   those first 100 characters contain only the preamble and path.
3. **Non-root SSH users** route commands through
   `parseCommandsByLineForSudo()`, which AGENTS.md warns mangles pipes,
   redirects and heredocs. This path gets a dedicated test.

### Call sites

- `prepare_builder_image()` (`app/Jobs/ApplicationDeploymentJob.php:2293-2336`)
  — replace the throw at `:2309` with a generated config; keep mounting it
  read-only into the helper container.
- `pull_latest_image()` (`:3712`), `check_image_locally_or_remotely()` (`:1348`),
  `push_to_docker_registry()` (`:1127-1183`).
- Deploy-job compose pulls: `:924`, `:927`, `:4237`.
- Swarm `docker stack deploy --with-registry-auth` (`:2066`).
- Host-level `docker compose pull` in `app/Actions/Service/StartService.php:37`,
  `app/Actions/Service/DeployServiceApplication.php:39`, the eight
  `app/Actions/Database/Start*.php` pulls, `app/Actions/Proxy/StartProxy.php:63`,
  `app/Jobs/RestartProxyJob.php:146`, and
  `app/Actions/Server/ConfigureCloudflared.php:45`.

The Action call sites are the bulk of the mechanical work.

## Validation and Authorization

Per AGENTS.md, every server-side read and mutation is authorized and
team-scoped.

- `InfisicalConnectionPolicy`, `InfisicalBindingPolicy`,
  `ContainerRegistryPolicy`. Credentials are admin/owner only; members must not
  read `client_secret` or `password`.
- All queries scoped to `currentTeam()`; route and model identifiers treated as
  untrusted.
- Livewire credential forms follow `app/Livewire/Storage/Form.php`, including
  its `isPasswordHiddenForMember` treatment (`:118-127`).
- Validation uses the inline `Validator` facade and custom rules in
  `app/Rules/`, not Form Request classes.

## Testing

Pest. Every change carries a test.

Unit (no DB, per `tests/Pest.php:18`):

- `config.json` generation, including auth header base64 encoding.
- Image-reference host matching against registry `url`.
- Inherited-versus-resource precedence resolution.

Feature (`RefreshDatabase`):

- Sync upsert creates, updates, and deletes only binding-owned rows; user-owned
  rows with a null `infisical_binding_id` survive untouched.
- Deploy proceeds with last-synced values when Infisical is unreachable.
- Resource-level variables override inherited ones.
- Registry credential command construction never places a secret in the first
  100 characters and never routes through `remote_process`.
- Sudo-parser handling of the heredoc write for non-root SSH users.
- Authorization regressions: members blocked from credential reads, cross-team
  access returns 403.

Browser (`tests/v4/Browser/`, seeding `InstanceSettings::create(['id' => 0])`,
ending in `screenshot()`): the Infisical connection screen and the container
registry screen.

## Compatibility

- Additive only. Existing environment variables, shared variables, and
  deployments are unaffected when no binding or registry exists.
- With no `container_registries` row matching an image host, behaviour is
  identical to today, including the existing `~/.docker/config.json` mount.
- Upstream merge discipline: all new logic lives in new files under
  `app/Services/Infisical/`, `app/Actions/Infisical/`, `app/Actions/Docker/`,
  and `app/Models/`. Edits to upstream-owned files are kept to a single-line
  call into new code so conflicts resolve without re-deriving intent.
- `.github/workflows/pr-quality.yaml` blocks the string "Generated with Claude
  Code" in PR descriptions and auto-closes the PR. Omit that attribution on any
  PR targeting `coollabsio/coolify`.
