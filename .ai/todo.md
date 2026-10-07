# Multi-destination S3 backups — implementation

The earlier plan file (`docs/superpowers/plans/2026-09-14-multi-destination-s3-backups.md`) is missing, so this plan replaces it.

## Design

- Schedules (database + volume/directory) get a pivot of selected S3 storages:
  `scheduled_database_backup_s3_storage`, `scheduled_volume_backup_s3_storage`.
- `save_s3` and `s3_storage_id` stay. `s3_storage_id` = primary (first) destination, for old API clients and UI.
- Each execution gets one replica row per destination: `database_backup_s3_replicas`, `volume_backup_s3_replicas`
  (`s3_storage_id` nullable, `s3_uploaded` nullable bool, `s3_storage_deleted`, `message`).
- Execution columns `s3_uploaded` / `s3_storage_deleted` stay as a summary of the replicas.
- Retention: the same S3 retention settings apply to each destination separately (grouped by replica storage).
- Local deletion (`disable_local_backup`) only when every selected replica uploaded.
- Volume S3-only streaming only with exactly one destination; with more, archive locally, upload each, then delete.
- Manual execution deletion removes the copy from every live replica.
- S3 storage deletion: remove from pivots, move primary to next storage, disable S3 when none remain, mark replicas deleted.

## Tasks

- [x] Core: migrations with backfill, models, traits, S3Storage deleting hook
- [x] Database backup job + retention + execution deletion paths
- [x] Volume backup job + recovery cleanup + execution deletion paths
- [x] Livewire UI: multi-select destinations for database and volume schedules, list views, storage resources page
- [x] API (databases + volume backups), MCP tool, server transfer export/import
- [x] Tests, pint, review

## Review

- Independent review found 5 issues; all fixed with regression tests:
  storage deletion skipped rows (`each()` offset paging → `lazyById()`), forms dropped temporarily unusable
  destinations, legacy API `s3_storage_uuid` update dropped other destinations, local leftovers with
  `disable_local_backup` were never pruned, MCP N+1.
- Clone paths (CloneMe, ResourceOperations, API database/service clone) copy destinations with `copyS3StoragesTo()`.
- Migration backfill verified on Postgres 16.
- 1340 related tests pass. Full suite not run. No live UI check (no dev instance for this branch).
