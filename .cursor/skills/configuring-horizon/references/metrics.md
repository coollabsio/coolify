# Metrics & Snapshots

## Where to Find It

Search with `search-docs`:
- `"horizon metrics snapshot"` for the snapshot command and scheduling
- `"horizon trim snapshots"` for retention configuration

## What to Watch For

### Metrics dashboard stays blank until `horizon:snapshot` is scheduled

Running `horizon` artisan command does not populate metrics automatically. The metrics graph is built from snapshots, so `horizon:snapshot` must be scheduled to run every 5 minutes via Laravel's scheduler.

### Register the snapshot in the scheduler rather than running it manually

A single manual run populates the dashboard momentarily but will not keep it updated. Search `"horizon metrics snapshot"` for the exact scheduler registration syntax, which differs between Laravel 10 and 11+.

### `metrics.trim_snapshots` is a snapshot count, not a time duration

The `trim_snapshots.job` and `trim_snapshots.queue` values in `config/horizon.php` are counts of snapshots to keep, not minutes or hours. With the default of 24 snapshots at 5-minute intervals, that provides 2 hours of history. Increase the value to retain more history at the cost of Redis memory usage.