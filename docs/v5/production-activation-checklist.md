# V5 Production Activation Checklist

V5 is intentionally limited to development environments so this branch can be merged without activating V5 or changing the V4 database schema in production. Complete this checklist before making V5 available outside development.

## Feature Gate

- Replace the development-environment-only gate in `app/Support/V5/V5Feature.php` and `config/v5.php` with an explicit rollout flag that can be enabled per installation.
- Keep V5 disabled by default during the rollout.
- Retain a disabled-mode test so V4 continues to work when the V5 schema is absent.

The current gate controls:

- V5 migrations and the V5 morph map in `app/Providers/AppServiceProvider.php`.
- `/v5` routes and the V5 rate limiter in `app/Providers/RouteServiceProvider.php`.
- The internal Flux status endpoint in `routes/api.php`.
- Reconciliation and agent-token rotation schedules in `app/Console/Kernel.php`.
- V5 applications in the V4 resource list in `app/Livewire/Project/Resource/Index.php`.
- V5-aware project and environment emptiness checks in `app/Models/Project.php` and `app/Models/Environment.php`.
- V5 host teardown during team deletion in `app/Models/Team.php`.

## Database

- Review and back up the production database before registering `database/migrations-v5/`.
- Run the V5 migrations in staging and verify both upgrade and rollback behavior.
- Keep V5 schema changes out of `database/migrations/` until the activation strategy explicitly changes.
- Verify existing V4 projects, environments, teams, and resources remain unchanged after the V5 migrations run.

## Flux Production Runtime

The production image deliberately does not ship Flux today. Before activation:

- Add the Flux binary to `docker/production/Dockerfile` with a pinned production version.
- Add the production Flux s6 service and its `user/contents.d` entry, using the development service only as a reference.
- Provision the Flux Unix socket directory, JWT signing keys, Laravel API token, and required permissions.
- Configure the required `COOLIFY_FLUX_*` and `COOLIFY_COOLD_*` values from `config/flux.php` and `config/coold.php`.
- Decide whether Flux needs a published port, persistent mounts, or host binding in `docker-compose.prod.yml`.
- Define key and token rotation procedures before enrolling production hosts.

Production environment templates and stable/nightly install and upgrade scripts deliberately do not provision Flux tokens or storage while V5 is inactive. At activation:

- Add the token to `.env.production` and generate it during new stable and nightly installations.
- Update stable and nightly upgrades to generate a token only when one is missing, preserving existing tokens.
- Create the persistent Flux storage path with the ownership and permissions required by the production runtime.
- Verify the Laravel and Flux processes receive the same token without exposing it in logs.
- Document and test zero-downtime rotation with `COOLIFY_FLUX_LARAVEL_API_TOKENS` before production rollout.

## Container Processes

Container roles are development-only while V5 is inactive in production.

- Keep role handling under `docker/development/` and `docker-compose.dev.yml`.
- Keep production Horizon, scheduler, and Nightwatch startup identical to `next` until V5 activation.
- At activation, decide whether production needs separate web, worker, scheduler, Nightwatch, or Flux roles. Introduce production role handling in a dedicated change if it is required.

## Queues and Scheduling

- Add a production `v5reconcile` Horizon supervisor for the `v5-reconcile` queue in `config/horizon.php`.
- Set production process counts, memory, retry, and timeout values from measured workloads.
- Enable and monitor `V5ReconcileServersJob` and `V5RotateAgentTokensJob` schedules.
- Verify reconciliation and token rotation are idempotent across multiple application instances.

## Commands and Seeders

- Keep `flux:dev` and `v5:sync-dev-lima-servers` development-only.
- Replace `v5:flux-generate-keys` with, or adapt it into, a production-safe key provisioning and rotation workflow.
- Keep `V5DevLimaSeeder` restricted to development environments.

## V4 Compatibility

- Keep shared server IP validation aligned with `next` unless a separately reviewed V4 change is intended.
- Verify V4 resource pages do not query V5 tables while V5 is disabled.
- Verify V4 API routes, deployment flows, queues, and background processes behave the same with the rollout flag disabled.
- Test project, environment, and team deletion with V5 disabled and enabled.

## Tests to Update at Activation

- Update `tests/Feature/V5DevelopmentIsolationTest.php`, which currently requires production and staging to omit V5 routes, migrations, Horizon workers, and the Flux runtime.
- Retain `tests/Feature/V5DisabledModelIsolationTest.php` for installations where V5 remains disabled.
- Extend `tests/Feature/ContainerRoleScriptTest.php` if production container roles are introduced.
- Run the V5 migration, authorization, lifecycle, reconciliation, token rotation, API, and browser test suites against a production-like staging environment.

## Activation Exit Criteria

- V5 is explicitly enabled rather than inferred only from `APP_ENV`.
- Production migrations, Flux, queues, schedules, secrets, and networking are provisioned and monitored.
- A rollback procedure has been tested.
- V4 regression tests pass with V5 both disabled and enabled.
- V5 browser and deployment smoke tests pass in staging.
