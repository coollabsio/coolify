# Coolify Cloud Horizon worker pools

Coolify Cloud runs Horizon on two app nodes that share one Redis. On Cloud
(`SELF_HOSTED=false`, `APP_ENV=production`), `config/horizon.php` provisions
one fixed-size supervisor per queue. A busy queue cannot starve the others.

| Supervisor    | Queue         | Env variable                    | Default per node |
|---------------|---------------|---------------------------------|------------------|
| `deployments` | `deployments` | `HORIZON_DEPLOYMENTS_PROCESSES` | 60               |
| `crons`       | `crons`       | `HORIZON_CRONS_PROCESSES`       | 60               |
| `high`        | `high`        | `HORIZON_HIGH_PROCESSES`        | 60               |
| `default`     | `default`     | `HORIZON_DEFAULT_PROCESSES`     | 40               |
| `maintenance` | `maintenance` | `HORIZON_MAINTENANCE_PROCESSES` | 10               |

- Counts apply to **each node**. Two nodes with the defaults run 460 workers.
- `maintenance` runs Docker cleanups (scheduled, manual, and the cleanup
  that runs after a resource stops). Remote prunes can be slow, so this pool
  is small on purpose: it limits how many cleanups run at the same time and
  keeps them away from the `high` workers. It is added on top of the other
  pools. Each cleanup has a 600 s timeout, and only one cleanup runs per
  server at a time. Self-hosted instances keep cleanups on `high`.
- Each pool has `minProcesses = maxProcesses` and `balance = false`. Horizon
  does not scale them.
- A value that is not a positive integer falls back to the default.
- `HORIZON_QUEUES`, `HORIZON_MIN_PROCESSES`, `HORIZON_MAX_PROCESSES`, and the
  `HORIZON_BALANCE*` variables have no effect on Cloud production. They still
  configure the self-hosted `s6` supervisor and the `local` environment.
- Timeout, retry, memory, and recycling settings (`HORIZON_TIMEOUT`,
  `HORIZON_MAX_TIME`, `maxJobs`, `memory`, `tries`) are the same as for
  self-hosted.
- The config reads `SELF_HOSTED` with `env()` and does not call `isCloud()`,
  because `isCloud()` reads the config repository, which is not complete while
  config files load.

## Sample Cloud configuration

Put these values in the `.env` of each node. The values are the defaults, so
set only the values that you change.

```dotenv
SELF_HOSTED=false
APP_ENV=production
HORIZON_DEPLOYMENTS_PROCESSES=60
HORIZON_CRONS_PROCESSES=60
HORIZON_HIGH_PROCESSES=60
HORIZON_DEFAULT_PROCESSES=40
HORIZON_MAINTENANCE_PROCESSES=10
# Worker timeout. Must stay above the longest job timeout (36000 s) and below
# retry_after (86400 s). The config clamps it to 36600..85800.
HORIZON_TIMEOUT=36600
```

Redis that holds queues must use `maxmemory-policy noeviction`. An evicting
policy deletes queued jobs without an error.

## Worker shutdown, retry_after, and interrupted jobs

- **Graceful (`php artisan horizon:terminate`)**: workers stop taking new
  jobs and finish the current job. `fast_termination` is `false`, so the
  master waits until the last worker exits. This can take up to the longest
  job timeout. Waiting jobs stay in Redis. The other node continues to
  process them.
- **Forced (SIGKILL, container stop after its grace period, OOM kill)**: the
  running job stays in `queues:<queue>:reserved`. Redis gives it to a worker
  again only after `retry_after` (86400 s). Then the job has attempt 2:
  - Jobs with `tries = 1` fail with `MaxAttemptsExceededException` and their
    `failed()` method runs.
  - A `ScheduledTaskJob` does not run its command again. It fails.
  - A scheduled occurrence that a killed worker claimed is marked `failed`
    ("interrupted") when it is older than the worker timeout plus 15 minutes.
    It is not run again, because part of its remote work can be done.
- Do not lower `retry_after` below the worker timeout. A running job would
  then be given to a second worker.

### Which settings need new workers

| Change                                                    | Needed action                                    |
|-----------------------------------------------------------|--------------------------------------------------|
| `HORIZON_*_PROCESSES`, `HORIZON_TIMEOUT`, queue names      | `config:cache` and `horizon:terminate`           |
| Horizon `trim` values                                     | `config:cache` and `horizon:terminate`           |
| `retry_after` (`config/queue.php`)                        | `config:cache` and `horizon:terminate`           |
| Application code (jobs, queues, middleware)               | New image, then `horizon:terminate`              |
| New database migration                                    | `php artisan migrate` before new workers start   |

## Rollout

Do these steps on one node at a time. Do not flush Redis or clear queues.

1. Run the database migrations once (the new volume backup recovery columns
   must exist before the new scheduler runs):

   ```bash
   docker exec coolify php artisan migrate --force
   ```

2. Optional: set the `HORIZON_*_PROCESSES` variables in the node's `.env`.
   Remove manual pool overrides from earlier hotfixes.
3. Rebuild the configuration cache:

   ```bash
   docker exec coolify php artisan config:cache
   ```

4. Restart Horizon on this node only. `horizon:terminate` lets the running
   jobs finish, then s6 starts Horizon again with the new configuration:

   ```bash
   docker exec coolify php artisan horizon:terminate
   ```

5. Make sure the five supervisors are running on this node:

   ```bash
   docker exec coolify php artisan horizon:supervisors
   ```

6. Make sure the `maintenance` queue drains (its depth goes down) and that
   `php artisan scheduled:diagnostics` shows no growing count of stale
   occurrences.
7. Do the same steps on the next node.

Jobs that the old code put on `high` (for example Docker cleanups) stay on
`high`, and the `high` pool still processes them.

## Rollback

1. Deploy the previous image, or remove the `HORIZON_*_PROCESSES` overrides.
2. Run `config:cache` and `horizon:terminate` on one node at a time.
3. Jobs that wait on the `maintenance` queue are not lost, but the old
   configuration has no `maintenance` pool. Keep one node with the new
   configuration until `maintenance` is empty, or start a temporary worker:
   `php artisan queue:work redis --queue=maintenance --tries=1 --timeout=600`.
4. Do not roll back the migration. The old code ignores the new columns.

## Metrics to collect before you change worker counts

The incident samples are short and show only completed jobs. Collect these
values before you change pool sizes:

- Arrival and completion rate per queue (per minute).
- Age of the oldest waiting job per queue (`LINDEX queues:<queue> -1`, then
  read `pushedAt` from the payload). Do not scan the full list.
- Queue depth (`LLEN queues:<queue>`) and reserved age
  (`ZRANGE queues:<queue>:reserved 0 0 WITHSCORES`).
- Active worker processes per supervisor (count processes, not the
  Horizon reserved count; reserved entries stay after forced restarts).
- Job duration distribution (p50/p95/max) and failure/retry rate per job
  class.
- Stale scheduled occurrences per status (`scheduled:diagnostics` and the
  `open_occurrences_over_15_minutes` value in `scheduled.log`).
- Redis `used_memory` / `maxmemory`, the delta of `evicted_keys` (`INFO
  stats`), and the delta of `errorstat_OOM` (`INFO errorstats`).
- Horizon metadata size: the count of `horizon:*` job hashes and the
  `horizon:recent_jobs` / `horizon:completed_jobs` sizes.

Label metrics only by queue and job class. Do not label by resource ID or
occurrence UUID.
