# Infisical: instance-wide connection with a project per team

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

## Open risk: project creation permissions

Creating a project is an **organization-level** operation, and a machine
identity may additionally need to be granted access to each project it creates
before it can write secrets there. Neither is confirmed at the time of writing.

If a machine identity cannot create projects, step 3 above is dropped and the
design degrades to **adopt-by-name only**: the operator creates one Infisical
project per team by hand, named to match, and Coolify binds to it. That is
still a single set of credentials for the whole instance, which is the main
goal — only the auto-creation is lost.

Nothing in this spec may assume auto-creation works until it is verified
against a live instance.
