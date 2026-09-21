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

Coolify generates `SERVICE_PASSWORD_*`, `SERVICE_FQDN_*`, and managed-database
credentials. A model hook pushes these to Infisical so Infisical holds the
complete picture. Must be guarded against re-entrancy: a downward pull writing
rows must not trigger an upward push.

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

Enforcement is server-side. `@can` in Blade is presentation only and is never
the control. The gate answers one question — is this write Coolify-generated, or
a human edit? System passes; human is rejected with a message naming Infisical
as the place to edit.

Surfaces, all of which must be covered:

- `app/Livewire/SharedVariables/{Team,Project,Environment,Server}/*`
- `app/Livewire/Project/Shared/EnvironmentVariable/{Add,All,Show,ShowHardcoded}`
- Every `api.ability:write` env route in `routes/api.php`, across
  `SharedEnvironmentVariablesController`, `ApplicationsController`, and the
  service and database controllers.

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

## Open risk: Infisical API capability

Auto-creating environments and folders depends on endpoints not yet verified
against a live Infisical instance, and environment creation requires
project-admin rights on the machine identity. This must be verified before any
code depends on it. If unavailable, the design degrades to skip-and-warn for
missing environments, and the operator creates them in Infisical by hand.

## Compatibility

The feature is unreleased, so no migration path is owed to existing binding
rows. The drop migration removes them.
