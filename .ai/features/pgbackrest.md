# pgBackRest Integration for PostgreSQL

This document describes the pgBackRest backup and restore implementation for PostgreSQL databases in Coolify.

## Overview

pgBackRest is a reliable backup and restore solution for PostgreSQL that provides features like:
- Full, differential, and incremental backups
- Parallel backup and restore
- Local and remote backup repositories
- Point-in-time recovery (PITR)
- Backup integrity verification

Coolify integrates pgBackRest using a **sidecar container approach** with **online/hot backups** - the database does NOT need to be stopped during backups.

## Architecture

```
+-------------------------------------------------------------+
|                        Docker Network                        |
|                                                              |
|  +------------------+         +--------------------------+   |
|  |   PostgreSQL     |<------->|   pgBackRest Sidecar     |   |
|  |   Container      |  libpq  |   Container              |   |
|  |   ({uuid})       |         |   ({uuid}-pgbackrest)    |   |
|  |                  |         |                          |   |
|  |  Port: 5432      |         |  - Runs online backups   |   |
|  |  WAL archiving   |         |  - Manages stanza        |   |
|  |  enabled         |         |  - Stores backups        |   |
|  +--------+---------+         +------------+-------------+   |
|           |                                |                 |
|           v                                v                 |
|  +----------------------------------------------------------+|
|  |              Shared Docker Volumes                        ||
|  |  - postgres-data-{uuid} (data directory, read-only)       ||
|  |  - wal-archive (WAL files for PITR)                       ||
|  +----------------------------------------------------------+|
|                                                              |
|  +----------------------------------------------------------+|
|  |           pgBackRest Repository Volume                    ||
|  |      ({workdir}/pgbackrest-repo)                          ||
|  +----------------------------------------------------------+|
+-------------------------------------------------------------+
```

## Online Backup Implementation

pgBackRest performs **online (hot) backups** without requiring database downtime:

1. **PostgreSQL Configuration**: When pgBackRest is enabled, PostgreSQL is automatically configured with:
   - `wal_level = replica` - Required for WAL archiving
   - `archive_mode = on` - Enables WAL archiving
   - `archive_command` - Copies WAL files to shared archive directory
   - `archive_timeout = 60` - Forces WAL switch every 60 seconds

2. **pgBackRest Sidecar Configuration**:
   - Connects to PostgreSQL via libpq using `PGPASSWORD`, `PGHOST`, `PGPORT`, `PGUSER`, `PGDATABASE` environment variables
   - Uses `pg1-host` pointing to the PostgreSQL container for backup start/stop commands
   - Mounts PostgreSQL data directory read-only for file copying
   - Mounts shared WAL archive directory for Point-in-Time Recovery (PITR)

3. **Backup Process**:
   - pgBackRest issues `pg_backup_start()` to PostgreSQL (no database stop required)
   - Copies data files while database continues running
   - Issues `pg_backup_stop()` to complete the backup
   - WAL files ensure backup consistency

## Key Components

### Database Model Extensions

The `StandalonePostgresql` model has these pgBackRest-related methods:

| Method | Description |
|--------|-------------|
| `isPgbackrestEnabled()` | Returns true if pgBackRest is enabled |
| `getPgbackrestStanzaName()` | Returns `db-{uuid}` |
| `getPgbackrestContainerName()` | Returns `{uuid}-pgbackrest` |
| `getPgbackrestConfigDir()` | Returns config directory path |
| `getPgbackrestRepoDir()` | Returns repository directory path |

### Jobs

| Job | Purpose |
|-----|---------|
| `PgbackrestBackupJob` | Executes pgBackRest backups (full/diff/incr) |
| `PgbackrestRestoreJob` | Restores database from pgBackRest backup |
| `PgbackrestStanzaJob` | Creates/upgrades/checks pgBackRest stanza |

### Actions

| Action | Purpose |
|--------|---------|
| `GeneratePgbackrestConfig` | Generates pgbackrest.conf and PostgreSQL archive settings |
| `RestoreFromPgbackrest` | Validates and initiates restore operations |

### Helper Functions

Located in `bootstrap/helpers/databases.php`:

| Function | Description |
|----------|-------------|
| `isPgbackrestContainerRunning()` | Check if sidecar container is running |
| `getPgbackrestInfo()` | Get raw pgBackRest info JSON |
| `getPgbackrestBackupList()` | Get formatted Collection of backups |
| `getPgbackrestLatestBackup()` | Get most recent backup |
| `getPgbackrestBackupByLabel()` | Find backup by label |
| `getPgbackrestStanzaStatus()` | Get stanza health status |
| `calculatePgbackrestTotalSize()` | Sum of all backup sizes |
| `formatPgbackrestBackupType()` | Format backup type for display |

## Configuration

### Database Fields

| Field | Type | Description |
|-------|------|-------------|
| `pgbackrest_enabled` | boolean | Enable/disable pgBackRest |

### Container Configuration

The pgBackRest sidecar container is configured with:
- Image: `woblerr/pgbackrest:2.57.0` (configurable in constants)
- Mounts PostgreSQL data volume read-only for backups
- Mounts WAL archive directory for PITR
- Mounts config and repository directories
- Connects to same Docker network as PostgreSQL
- Environment variables for PostgreSQL connection (PGPASSWORD, PGHOST, etc.)

## Backup Types

| Type | Flag | Description |
|------|------|-------------|
| Full | `full` | Complete backup of all data |
| Differential | `diff` | Changes since last full backup |
| Incremental | `incr` | Changes since last backup of any type |

## Stanza Management

A stanza is pgBackRest's configuration for a specific PostgreSQL cluster. The stanza name format is `db-{uuid}`.

Stanza operations:
- **Create**: Initialize stanza for new database
- **Upgrade**: Update stanza after PostgreSQL upgrade
- **Check**: Verify stanza configuration and connectivity

## Restore Operations

Restore options:
- **Latest backup**: Restore to most recent backup
- **Specific backup**: Restore to a specific backup label
- **Point-in-time**: Restore to a specific timestamp

The database must be stopped before restore. After restore, the database can optionally be restarted automatically.

## UI Components

The pgBackRest UI is available in the PostgreSQL database configuration under the "Backups" section:

- Enable/disable pgBackRest toggle
- View available backups with size and timestamp
- Trigger manual backups (full/diff/incr)
- Initiate restore operations
- View stanza status

## API Endpoints

pgBackRest operations are exposed via API:

```
POST /api/v1/databases/{uuid}/pgbackrest/backup
POST /api/v1/databases/{uuid}/pgbackrest/restore
GET  /api/v1/databases/{uuid}/pgbackrest/backups
GET  /api/v1/databases/{uuid}/pgbackrest/status
```

## Notifications

The following notifications are sent for pgBackRest operations:

| Notification | Trigger |
|--------------|---------|
| `PgbackrestStanzaCreated` | Stanza successfully created |
| `PgbackrestStanzaFailed` | Stanza operation failed |
| `PgbackrestRestoreSuccess` | Database restored successfully |
| `PgbackrestRestoreFailed` | Restore operation failed |
| `BackupSuccess` | Backup completed successfully |
| `BackupFailed` | Backup operation failed |

## Testing

Unit tests are located in `tests/Unit/`:

- `PgbackrestHelpersTest.php` - Helper function tests
- `PgbackrestBackupJobTest.php` - Backup job tests
- `PgbackrestRestoreJobTest.php` - Restore job tests
- `PgbackrestStanzaJobTest.php` - Stanza job tests
- `RestoreFromPgbackrestTest.php` - Restore action tests
- `StandalonePostgresqlPgbackrestTest.php` - Model method tests

Run tests with:
```bash
./vendor/bin/pest tests/Unit/Pgbackrest*
./vendor/bin/pest tests/Unit/RestoreFromPgbackrest*
./vendor/bin/pest tests/Unit/StandalonePostgresqlPgbackrest*
```

## Files Reference

### Core Implementation
- `app/Models/StandalonePostgresql.php` - Model with pgBackRest methods
- `app/Actions/Database/StartPostgresql.php` - Starts pgBackRest container with online backup config
- `app/Actions/Database/StopPostgresql.php` - Stops pgBackRest container
- `app/Actions/Database/Pgbackrest/GeneratePgbackrestConfig.php` - Generates configs
- `bootstrap/helpers/databases.php` - Helper functions

### Jobs
- `app/Jobs/PgbackrestBackupJob.php`
- `app/Jobs/PgbackrestRestoreJob.php`
- `app/Jobs/PgbackrestStanzaJob.php`

### Actions
- `app/Actions/Database/Pgbackrest/RestoreFromPgbackrest.php`

### UI Components
- `app/Livewire/Project/Database/Postgresql/Pgbackrest.php`
- `resources/views/livewire/project/database/postgresql/pgbackrest.blade.php`

### Notifications
- `app/Notifications/Database/PgbackrestStanzaCreated.php`
- `app/Notifications/Database/PgbackrestStanzaFailed.php`
- `app/Notifications/Database/PgbackrestRestoreSuccess.php`
- `app/Notifications/Database/PgbackrestRestoreFailed.php`

### Database Migration
- `database/migrations/*_add_pgbackrest_to_standalone_postgresqls_table.php`
