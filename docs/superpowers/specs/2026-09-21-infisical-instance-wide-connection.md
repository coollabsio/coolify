# Infisical: instance-wide connection with a project per team

> **NOT IMPLEMENTED — superseded by decision, 2026-09-21.**
>
> The per-team connection model in
> `2026-09-21-infisical-single-connection-design.md` is retained: the operator
> creates one Infisical project per team and configures each team's connection
> directly. No code from this document was written.
>
> Kept only for the verified API research in "Project creation API (verified)",
> which is accurate and would be the starting point if this is ever revisited.

Amends `2026-09-21-infisical-single-connection-design.md`. Everything in that
document stands except where contradicted here — the folder layout, the lock,
the sync directions, the deletion policy and the scope mapping are unchanged.

## Problem

The shipped design requires a full connection — host, client id, client secret,
Infisical project id — to be configured **per Coolify team**. On an instance
with more than one team that is repeated credential entry, and it forces the
operator to create each Infisical project by hand first.

## Goals

- Configure Infisical **once for the whole Coolify instance**.
- Every team inherits it automatically.
- Coolify creates **one Infisical project per team**, named after the team, and
  remembers which project belongs to which team.
- A team that genuinely needs its own credentials can still override.

## Non-goals

- Changing the folder layout, the lock, or any sync behaviour.
- Migrating existing per-team connections. The feature is unreleased.

## Decisions

| Question | Decision |
|---|---|
| Where credentials live | One instance-level connection row; per-team rows are optional overrides. |
| Distinguishing the instance row | `team_id IS NULL`. |
| Enforcing one instance row | **Partial unique index**, not `unique(team_id)` — Postgres treats NULLs as distinct, so a plain unique index would permit many instance rows. |
| Where the project id lives | A new `infisical_team_projects` table, never on the connection. |
| Project naming | The Coolify team name. |
| Resolution order | The team's own connection, else the instance connection. Both must be enabled. |

## Data model

### `infisical_connections` (modified)

- `team_id` becomes **nullable**. `NULL` means the instance-wide connection.
- Drop `unique(team_id)`. Add two partial unique indexes:

```sql
CREATE UNIQUE INDEX infisical_connections_team_unique
    ON infisical_connections (team_id) WHERE team_id IS NOT NULL;

CREATE UNIQUE INDEX infisical_connections_instance_unique
    ON infisical_connections ((team_id IS NULL)) WHERE team_id IS NULL;
```

- **`infisical_project_id` is dropped.** Project identity moves to the mapping
  table for every connection type, so there is one code path rather than two.

### `infisical_team_projects` (new)

| column | notes |
|---|---|
| `id` | |
| `team_id` | unique, cascade on delete |
| `infisical_connection_id` | the connection this project was resolved through |
| `infisical_project_id` | the Infisical project id |
| `created_by_coolify` | false when Coolify adopted a pre-existing project by name |
| `timestampsTz` | |

Recording `created_by_coolify` matters for a later teardown story: Coolify
should never delete a project a human made.

## Resolution

Two lookups replace every current use of `$connection->infisical_project_id`:

```php
InfisicalConnection::resolveForTeam(?int $teamId): ?self
// the team's own enabled connection, else the enabled instance connection

ResolveTeamProject::run(Team $team, InfisicalConnection $connection): string
// the recorded mapping, else adopt-by-name, else create, then record
```

`ResolveTeamProject` is the only place that talks to Infisical about projects.
It must be idempotent: two concurrent syncs for one team must not create two
projects. Guard with a unique constraint on `team_id` and treat a duplicate-key
violation as "another process won, re-read the mapping".

### Order of attempts

1. **Recorded mapping** — return it.
2. **Match by name** — list the identity's visible projects and match the team
   name exactly. Record with `created_by_coolify = false`. This is what makes
   pre-existing Infisical projects usable.
3. **Create** — create a project named after the team, record with
   `created_by_coolify = true`.
4. If creation is refused, fail with a message naming the missing permission.
   Do **not** silently fall back to a shared project; that would merge two
   teams' secrets.

## Call sites

`infisical_project_id` is read in only five files, three outside the UI:

- `app/Actions/Infisical/AdoptTeamSecretsIntoInfisical.php`
- `app/Actions/Infisical/PullTeamSecrets.php`
- `app/Jobs/InfisicalPushJob.php`
- `app/Models/InfisicalConnection.php`
- `app/Livewire/Security/Infisical/Form.php`

Each resolves the project through `ResolveTeamProject` instead.

## UI

- **Settings → Infisical** (instance settings, instance admin only) — host,
  credentials, enable. This is the screen originally asked for.
- **Keys & Tokens → Infisical** (per team) — shows which connection the team
  resolved to, which Infisical project it maps to, sync status, and an optional
  per-team override.

The per-team screen must make inheritance visible: an operator should be able
to tell at a glance whether this team is using the instance connection or its
own.

## Project creation API (verified)

Verified against the live OpenAPI document. A Universal Auth machine identity
**can** create projects, given the org-level `project:create` permission (the
built-in org **Admin** role has it).

| Operation | Endpoint | Notes |
|---|---|---|
| Create project | `POST /api/v2/workspace` | Field is **`projectName`**, not `name`. Max 64 chars. No `organizationId` — the org is derived from the token. |
| Grant self access | `POST /api/v1/projects/{projectId}/identity-memberships/{identityId}` | Body `{"role": "admin"}`. **Required second call.** |
| List projects | `GET /api/v1/projects` | Returns only projects the identity is a **member of**. |

### Creation is a two-call sequence, and the order is load-bearing

**The creating identity does NOT automatically get access to the project it
created.** It must add itself via the identity-memberships endpoint before it
can read or write any secret there.

This produces a trap that must be designed around: `GET /api/v1/projects`
returns only projects the identity is a *member* of. So if `POST
/api/v2/workspace` succeeds and the membership call then fails, the project
exists but is **invisible to us forever** — and the next sync, finding nothing
by name, creates another one. Left unguarded this creates a new orphaned
Infisical project on every single sync cycle.

Mitigations, all required:

1. Write the `infisical_team_projects` row **immediately after creation**,
   before attempting the membership call. The local mapping, not the remote
   list, is the source of truth for "we already made one".
2. If the membership call fails, keep the mapping, record the failure in
   `last_sync_error` naming the permission, and **do not retry creation**.
3. A resumable repair path: if a mapping exists but secrets calls return 403,
   retry only the membership call.

### Project names are not guaranteed unique

The API documents no uniqueness constraint on `projectName` and no 409 on
duplicates. Name-based idempotency must therefore be enforced in application
code — list, match, then create — and never rely on the server rejecting a
duplicate.

### Create projects WITHOUT default environments

`POST /api/v2/workspace` defaults `shouldCreateDefaultEnvs: true`, which
creates Development/Staging/Production with slugs **`dev`, `staging`, `prod`**.

Those slugs do **not** match Coolify's environment names. Coolify's default
environment is `production`, which slugs to `production`, not `prod` — so every
auto-created project would arrive with three unusable environments and Coolify
would then create a fourth alongside them.

Therefore: **pass `shouldCreateDefaultEnvs: false`** and let Coolify create
environments named after its own, which it already does. This removes the
mismatch entirely for auto-created projects.

Projects **adopted by name** still carry whatever environments their creator
made, so the separate environment-mapping feature remains necessary for them.

### Unresolved: how to obtain the identity's own id

The membership call needs `{identityId}` — the machine identity's own id. It is
not currently stored, and whether the Universal Auth login response returns it
(or whether it must be decoded from the JWT, or fetched from a separate
endpoint) is **NOT CONFIRMED**. This must be resolved before implementation;
without it, auto-creation cannot complete its second call.

### Plan limits

Third-party sources report the Infisical Cloud free tier caps at roughly 3
projects and 5 identities. One project per Coolify team reaches that quickly.
Self-hosted is believed unlimited but this is **NOT CONFIRMED**. The UI should
surface a project-creation failure clearly enough to distinguish a quota from a
permission problem.
