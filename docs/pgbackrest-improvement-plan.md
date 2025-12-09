# PgBackRest Improvement Plan

This document tracks the staged implementation plan for improving PgBackRest support in Coolify.

## Overview

The plan addresses:
- Bug fixes and security issues
- Code consolidation and deduplication
- S3 multi-mode support (Local, S3, S3+Local)
- Per-schedule repository management
- UI improvements
- API feature parity
- Test coverage

---

## Stage 1: Critical Bug Fixes ✅ COMPLETED

**Effort:** S (1-2h) | **Risk:** Low

| Task | Status | Description |
|------|--------|-------------|
| 1a | ✅ | Fix `PgbackrestStanzaJob` - change `$this->database->team` to `team()` method (notifications were broken) |
| 1b | ✅ | Set `pgbackrest_restore_started_at` timestamp in `PgbackrestRestoreJob` when restore begins |
| 1c | ✅ | Add `escapeshellarg()` for `postgres_user`/`postgres_db` in shell commands (security fix) |
| 1d | ✅ | Remove redundant `isPgbackrestEnabled()` check in `performBackup()` |

**Files Modified:**
- `app/Jobs/PgbackrestStanzaJob.php`
- `app/Jobs/PgbackrestRestoreJob.php`
- `app/Jobs/PgbackrestBackupJob.php`
- `app/Actions/Database/StartPostgresql.php`

---

## Stage 2: Code Consolidation ✅ COMPLETED

**Effort:** S-M (2-4h) | **Risk:** Low

| Task | Status | Description |
|------|--------|-------------|
| 2a | ✅ | Extract UUID generation helper to `ScheduledDatabaseBackupExecution::generateUniqueUuid()` |
| 2b | ✅ | Use `isPgbackrestEnabled()` accessor consistently instead of direct property access |
| 2c | ✅ | Use model methods `getPgbackrestConfigDir()`/`RepoDir()`/`StanzaName()` instead of manual path construction |
| 2d | ✅ | Replace helper function calls with `PgbackrestService` direct calls |

**Files Modified:**
- `app/Models/ScheduledDatabaseBackupExecution.php`
- `app/Jobs/PgbackrestBackupJob.php`
- `app/Jobs/DatabaseBackupJob.php`
- `app/Http/Controllers/Api/DatabasesController.php`
- `app/Livewire/Project/Database/Postgresql/Pgbackrest.php`
- `app/Services/PgbackrestService.php`
- `app/Livewire/Project/Database/BackupExecutions.php`

---

## Stage 3: S3 Multi-Mode Support ✅ COMPLETED

**Effort:** M (3-4h) | **Risk:** Medium

Enables three backup storage modes:
- `posix` - Local only (default)
- `s3` - S3 only (pgBackRest native S3)
- `s3+posix` - Both S3 and local (multi-repo)

| Task | Status | Description |
|------|--------|-------------|
| 3a | ✅ | Add model helpers: `pgbackrestUsesS3()` and `pgbackrestHasLocalRepo()` |
| 3b | ✅ | Update `GeneratePgbackrestConfig` for 3 modes (posix, s3, s3+posix) |
| 3c | ✅ | Generate multi-repo config: repo1=local, repo2=s3 for s3+posix mode |
| 3d | ✅ | Apply identical retention settings to both repos in s3+posix mode |
| 3e | ✅ | Update `PgbackrestService`: `isS3Repo()` and `hasLocalRepo()` for all 3 modes |
| 3f | ✅ | Update `executeInTempContainer` mount logic for 3 modes |
| 3g | ✅ | Update `getS3EnvVars` to use REPO1 or REPO2 prefix based on mode |
| 3h | ✅ | Update `validateRestoreDeep` for 3-mode checks |

---

## Stage 4: S3 UI Implementation ✅ COMPLETED (Superseded by Stage 5)

**Note:** This stage was completed but later superseded by the architectural refactor in Stage 5.

---

## Stage 5: Per-Schedule Repository Architecture ✅ COMPLETED

**Effort:** L (4-6h) | **Risk:** Medium

Major architectural refactor: moved pgBackRest configuration from global database settings to per-scheduled-backup settings using a dedicated `PgbackrestRepo` model.

### Design Goals
- Each scheduled backup independently controls its storage (local, S3, or both)
- Each scheduled backup has its own retention settings
- Multiple schedules can share repos or have separate ones
- pgBackRest auto-enables when any scheduled backup uses it (no manual toggle)
- Maximum 8 repos per database (pgBackRest limit)

### Changes Made

| Task | Status | Description |
|------|--------|-------------|
| 5a | ✅ | Create `PgbackrestRepo` model with auto-assigned `repo_index` (1-8) |
| 5b | ✅ | Create pivot table `pgbackrest_repo_scheduled_backup` for many-to-many relationship |
| 5c | ✅ | Simplify `Pgbackrest.php` - removed enable checkbox, S3 settings, retention settings |
| 5d | ✅ | Simplify `pgbackrest.blade.php` - now only shows compression/logging settings |
| 5e | ✅ | Update `isPgbackrestEnabled()` to query scheduled backups instead of column |
| 5f | ✅ | Update `BackupEdit.php` - `pgbackrestAvailable` checks database type, not enabled state |
| 5g | ✅ | Add pgBackRest indicator to scheduled backup preview |
| 5h | ✅ | Refactor `GeneratePgbackrestConfig` to generate config from `PgbackrestRepo` records |
| 5i | ✅ | Update `PgbackrestService` to use `PgbackrestRepo` relationships |
| 5j | ✅ | Update all unit tests for new architecture |

### New Files Created
- `app/Models/PgbackrestRepo.php` - Repository model with retention, S3 config, auto-index
- `database/migrations/2025_12_09_100000_create_pgbackrest_repos_table.php`
- `database/migrations/2025_12_09_100001_create_pgbackrest_repo_scheduled_backup_table.php`

### Files Modified
- `app/Models/StandalonePostgresql.php` - Added `pgbackrestRepos()` relationship, updated `isPgbackrestEnabled()`
- `app/Models/ScheduledDatabaseBackup.php` - Added `pgbackrestRepos()` relationship and helpers
- `app/Livewire/Project/Database/Postgresql/Pgbackrest.php` - Simplified to compression/logging only
- `resources/views/livewire/project/database/postgresql/pgbackrest.blade.php` - Simplified UI
- `app/Livewire/Project/Database/BackupEdit.php` - Check database type for pgbackrestAvailable
- `resources/views/livewire/project/database/scheduled-backups.blade.php` - Added pgBackRest indicator
- `app/Actions/Database/Pgbackrest/GeneratePgbackrestConfig.php` - Generate from PgbackrestRepo
- `app/Services/PgbackrestService.php` - Query repos instead of database columns
- `tests/Unit/GeneratePgbackrestConfigTest.php` - Updated for new architecture
- `tests/Unit/PgbackrestServiceTest.php` - Updated for new architecture
- `tests/Unit/StandalonePostgresqlPgbackrestTest.php` - Updated for new architecture

---

## Stage 6: Per-Schedule UI Integration ✅ COMPLETED

**Effort:** M (3-4h) | **Risk:** Low

| Task | Status | Description |
|------|--------|-------------|
| 6a | ✅ | Add repo creation/attachment logic when enabling pgBackRest on a scheduled backup |
| 6b | ✅ | Add retention settings UI to backup-edit page when pgBackRest is enabled |
| 6c | ✅ | Auto-create repos based on S3/local checkboxes in backup-edit |
| 6d | ✅ | Store repo indexes metadata on backup executions for restore purposes |

### Changes Made

- **BackupEdit.php**: Added pgBackRest retention properties (`pgbackrestRetentionFull`, `pgbackrestRetentionDiff`, etc.), `syncPgbackrestRepos()` method to auto-create/attach repos based on S3/local settings, `findOrCreatePosixRepo()` and `findOrCreateS3Repo()` methods for repo management
- **backup-edit.blade.php**: Added pgBackRest retention UI (Full/Diff retention counts, retention types, archive retention), hidden pg_dump retention settings when pgBackRest is enabled
- **ScheduledDatabaseBackupExecution**: Added `pgbackrest_repo_indexes` JSON column to store which repos were used for each backup
- **PgbackrestBackupJob**: Now stores repo indexes in execution record for restore purposes

**Files Modified:**
- `app/Livewire/Project/Database/BackupEdit.php`
- `resources/views/livewire/project/database/backup-edit.blade.php`
- `app/Models/ScheduledDatabaseBackupExecution.php`
- `app/Jobs/PgbackrestBackupJob.php`

**New Files Created:**
- `database/migrations/2025_12_09_120000_add_pgbackrest_repo_indexes_to_scheduled_database_backup_executions_table.php`

---

## Stage 7: Validation & Config Centralization ⏳ TODO

**Effort:** M (3-6h) | **Risk:** Medium

| Task | Status | Description |
|------|--------|-------------|
| 7a | ⏳ | Centralize pgBackRest option constraints in config (log_levels, compress_types, retention limits) |
| 7b | ⏳ | Unify restore validation: `RestoreFromPgbackrest::validateRestore` → `validateRestoreDeep` |
| 7c | ⏳ | Add per-database concurrency guard for pgBackRest backups |

**Files to Modify:**
- `config/constants.php` or new `config/pgbackrest.php`
- `app/Actions/Database/Pgbackrest/RestoreFromPgbackrest.php`
- `app/Jobs/PgbackrestBackupJob.php`
- `app/Livewire/Project/Database/Postgresql/Pgbackrest.php`
- `app/Http/Controllers/Api/DatabasesController.php`

---

## Stage 8: Feature Gaps & API Parity ⏳ TODO

**Effort:** M (2-4h) | **Risk:** Medium

| Task | Status | Description |
|------|--------|-------------|
| 8a | ⏳ | Expose `target_time` (PITR) in `pgbackrest_restore` API endpoint |
| 8b | ⏳ | Update API to work with new `PgbackrestRepo` model |
| 8c | ⏳ | Add repo management endpoints to API |

**Files to Modify:**
- `app/Http/Controllers/Api/DatabasesController.php`

---

## Stage 9: Cleanup & Testing ⏳ TODO

**Effort:** M-L (4-8h) | **Risk:** Low

| Task | Status | Description |
|------|--------|-------------|
| 9a | ⏳ | Remove dead code: `StartPostgresql::add_pgbackrest_permission_fix_commands` |
| 9b | ⏳ | Deprecate unused columns: `pgbackrest_enabled`, `pgbackrest_repo_type`, `pgbackrest_s3_storage_id` on `standalone_postgresqls` |
| 9c | ⏳ | Remove `pgbackrest_enabled` write from `DatabasesController` API |
| 9d | ⏳ | Add tests for `PgbackrestRepo` model |
| 9e | ⏳ | Add tests for repo auto-creation on backup save |

**Files to Modify:**
- `app/Actions/Database/StartPostgresql.php`
- `app/Http/Controllers/Api/DatabasesController.php`
- `tests/Unit/PgbackrestRepoTest.php` (new)

---

## Architecture Diagrams

### New Per-Schedule Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                    StandalonePostgresql                         │
│  - pgbackrest_compress_type                                     │
│  - pgbackrest_compress_level                                    │
│  - pgbackrest_log_level                                         │
│  - isPgbackrestEnabled() → queries scheduled backups            │
└─────────────────────────────────────────────────────────────────┘
                              │
                              │ hasMany
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                      PgbackrestRepo                             │
│  - repo_index (1-8, auto-assigned)                              │
│  - type (posix | s3)                                            │
│  - path                                                         │
│  - s3_storage_id (FK to s3_storages)                           │
│  - retention_full, retention_diff, retention_full_type          │
│  - retention_archive, retention_archive_type                    │
└─────────────────────────────────────────────────────────────────┘
                              │
                              │ belongsToMany (pivot table)
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                  ScheduledDatabaseBackup                        │
│  - use_pgbackrest (boolean)                                     │
│  - pgbackrest_backup_type (full/diff/incr)                      │
│  - save_s3, disable_local_backup, s3_storage_id                 │
│  - getPgbackrestRepoIndexes() → from attached repos             │
└─────────────────────────────────────────────────────────────────┘
```

### Config Generation Flow

```
GeneratePgbackrestConfig::handle($database)
         │
         ▼
   Query all PgbackrestRepo for database
         │
         ▼
   For each repo:
   ┌──────────────────────────────────┐
   │  repoN-path=...                  │
   │  repoN-type=s3 (if S3)           │
   │  repoN-s3-bucket=... (if S3)     │
   │  repoN-retention-full=...        │
   │  repoN-retention-diff=...        │
   └──────────────────────────────────┘
         │
         ▼
   Add global settings (compress, log)
         │
         ▼
   Add stanza config
```

### Backup Job Flow

```
ScheduledDatabaseBackup (use_pgbackrest=true)
         │
         ▼
   PgbackrestBackupJob
         │
         ▼
   Get repo indexes from schedule
   ┌──────────────────────────────────┐
   │  $schedule->getPgbackrestRepoIndexes()  │
   │  → [1, 2] (for local + S3)       │
   └──────────────────────────────────┘
         │
         ▼
   For each repo index:
   Run: pgbackrest --repo=N backup
```

---

## Key Files Reference

### Core Files
- `app/Services/PgbackrestService.php` - Central service for pgBackRest operations
- `app/Actions/Database/Pgbackrest/GeneratePgbackrestConfig.php` - Config generation from repos
- `app/Actions/Database/Pgbackrest/RestoreFromPgbackrest.php` - Restore action
- `app/Jobs/PgbackrestBackupJob.php` - Backup job
- `app/Jobs/PgbackrestRestoreJob.php` - Restore job
- `app/Jobs/PgbackrestStanzaJob.php` - Stanza management job

### Models
- `app/Models/StandalonePostgresql.php` - PostgreSQL model with pgBackRest relationships
- `app/Models/PgbackrestRepo.php` - Repository model (posix or S3)
- `app/Models/ScheduledDatabaseBackup.php` - Backup schedule with pgBackRest repos

### UI
- `app/Livewire/Project/Database/Postgresql/Pgbackrest.php` - pgBackRest global settings (compression/logging)
- `resources/views/livewire/project/database/postgresql/pgbackrest.blade.php` - Settings view
- `app/Livewire/Project/Database/BackupEdit.php` - Per-schedule backup settings
- `app/Livewire/Project/Database/BackupExecutions.php` - Backup executions list

### API
- `app/Http/Controllers/Api/DatabasesController.php` - API endpoints for pgBackRest

### Tests
- `tests/Unit/PgbackrestBackupJobTest.php`
- `tests/Unit/PgbackrestRestoreJobTest.php`
- `tests/Unit/PgbackrestStanzaJobTest.php`
- `tests/Unit/PgbackrestServiceTest.php`
- `tests/Unit/GeneratePgbackrestConfigTest.php`
- `tests/Unit/StandalonePostgresqlPgbackrestTest.php`

---

## Progress Summary

| Stage | Status | Description |
|-------|--------|-------------|
| 1 | ✅ Complete | Critical Bug Fixes |
| 2 | ✅ Complete | Code Consolidation |
| 3 | ✅ Complete | S3 Multi-Mode Support |
| 4 | ✅ Complete | S3 UI Implementation (superseded) |
| 5 | ✅ Complete | Per-Schedule Repository Architecture |
| 6 | ✅ Complete | Per-Schedule UI Integration |
| 7 | ⏳ Todo | Validation & Config Centralization |
| 8 | ⏳ Todo | Feature Gaps & API Parity |
| 9 | ⏳ Todo | Cleanup & Testing |

**Overall Progress: 6/9 stages complete (67%)**
