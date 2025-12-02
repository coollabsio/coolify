# pgBackRest Integration for PostgreSQL

This document describes the pgBackRest backup and restore implementation for PostgreSQL databases in Coolify.

## Overview

pgBackRest is a reliable backup and restore solution for PostgreSQL that provides features like:
- Full, differential, and incremental backups
- Parallel backup and restore
- Local backup repositories
- Point-in-time recovery (PITR)
- Backup integrity verification

Coolify integrates pgBackRest **inside the PostgreSQL container itself** - pgBackRest is installed via an entrypoint script when the container starts.

## Architecture

```
+---------------------------------------------------------------+
|                     PostgreSQL Container                       |
|                         ({uuid})                               |
|                                                                |
|  +------------------+    +-----------------------------+       |
|  |   PostgreSQL     |    |      pgBackRest             |       |
|  |   Process        |    |      (installed at startup) |       |
|  |                  |    |                             |       |
|  |  - Port 5432     |    |  - Runs backups             |       |
|  |  - WAL archiving |    |  - Manages stanza           |       |
|  +------------------+    +-----------------------------+       |
|           |                          |                         |
|           v                          v                         |
|  +-----------------------------------------------------+       |
|  |              Container Volumes                       |       |
|  |  - postgres-data-{uuid} (/var/lib/postgresql/data)  |       |
|  |  - /etc/pgbackrest (config, bind mount)             |       |
|  |  - /var/lib/pgbackrest (repository, bind mount)     |       |
|  +-----------------------------------------------------+       |
+---------------------------------------------------------------+
```

## How It Works

1. **Container Startup**: When pgBackRest is enabled, the PostgreSQL container:
   - Runs `/etc/pgbackrest/install-pgbackrest.sh` via custom entrypoint
   - Installs pgBackRest via `apk add` (Alpine) or `apt-get install` (Debian)
   - Creates/upgrades the stanza if data directory exists

2. **PostgreSQL Configuration**: Automatically configured with:
   - `wal_level = replica` - Required for WAL archiving
   - `archive_mode = on` - Enables WAL archiving
   - `archive_command` - Archives WAL files to pgBackRest repository
   - `archive_timeout = 60` - Forces WAL switch every 60 seconds

3. **Backup Process**: Backups run inside the container:
   - `docker exec {container} su postgres -c 'pgbackrest --stanza={stanza} backup --type={type}'`
   - Database continues running during backup (hot/online backup)

## Restore Process

**IMPORTANT**: Restore requires PostgreSQL to be completely stopped and the data directory to be cleared.

The `PgbackrestRestoreJob` uses a **temporary container approach**:

1. **Stop the PostgreSQL container** with `docker stop` - cannot use `docker exec` on a stopped/crashing container
2. **Clear the data directory** using a temporary Alpine container with the data volume mounted
3. **Run pgBackRest restore** using a temporary container with the same PostgreSQL image:
   - Mounts the data volume, pgbackrest config, and pgbackrest repository
   - Installs pgBackRest and runs the restore command
4. **Start the container** via `StartPostgresql::run()` which recreates the container with proper configuration

This approach is necessary because:
- The main container may be in a restart loop after a failed restore
- Cannot `docker exec` into a container that's constantly restarting
- pgBackRest restore needs exclusive access to the data directory

### Volume Mounts for Restore

```php
$mounts = [
    'data_volume' => "postgres-data-{$containerName}",
    'pgbackrest_config' => "{$configDir}/pgbackrest",
    'pgbackrest_repo' => "{$configDir}/pgbackrest-repo",
];
```

The temporary restore container command:
```bash
docker run --rm \
  -v postgres-data-{uuid}:/var/lib/postgresql/data \
  -v {configDir}/pgbackrest:/etc/pgbackrest \
  -v {configDir}/pgbackrest-repo:/var/lib/pgbackrest \
  postgres:16-alpine sh -c '
    apk add --no-cache pgbackrest;
    chown -R postgres:postgres /var/lib/postgresql/data /var/lib/pgbackrest /etc/pgbackrest;
    su postgres -c "pgbackrest --stanza={stanza} --set={label} --type=immediate --target-action=promote --delta restore"
  '
```

## Key Components

### Database Model Extensions

The `StandalonePostgresql` model has these pgBackRest-related methods:

| Method | Description |
|--------|-------------|
| `isPgbackrestEnabled()` | Returns true if pgBackRest is enabled |
| `getPgbackrestStanzaName()` | Returns `db-{uuid}` |

### Jobs

| Job | Purpose |
|-----|---------|
| `PgbackrestBackupJob` | Executes pgBackRest backups (full/diff/incr) |
| `PgbackrestRestoreJob` | Restores database using temporary container |
| `PgbackrestStanzaJob` | Creates/upgrades/checks pgBackRest stanza |

### Actions

| Action | Purpose |
|--------|---------|
| `GeneratePgbackrestConfig` | Generates pgbackrest.conf and install script |
| `RestoreFromPgbackrest` | Validates restore operations, gets available backups |

### Helper Functions

Located in `bootstrap/helpers/databases.php`:

| Function | Description |
|----------|-------------|
| `isPostgresContainerRunning()` | Check if PostgreSQL container is running |
| `getPgbackrestInfo()` | Get raw pgBackRest info JSON |
| `getPgbackrestBackupList()` | Get formatted Collection of backups |
| `getPgbackrestLatestBackup()` | Get most recent backup |
| `getPgbackrestBackupByLabel()` | Find backup by label |
| `getPgbackrestStanzaStatus()` | Get stanza health status |
| `calculatePgbackrestTotalSize()` | Sum of all backup sizes |
| `isPgbackrestBackupDeletable()` | Check if backup can be deleted |
| `deletePgbackrestBackup()` | Delete a backup from repository |
| `execPgbackrest()` | Execute pgBackRest command in container |

## Configuration

### Database Fields

| Field | Type | Description |
|-------|------|-------------|
| `pgbackrest_enabled` | boolean | Enable/disable pgBackRest |
| `pgbackrest_full_retention` | integer | Number of full backups to retain |
| `pgbackrest_diff_retention` | integer | Number of differential backups to retain |
| `pgbackrest_compress_level` | integer | Compression level (0-9) |

### Directory Structure

```
/data/coolify/databases/{uuid}/
├── pgbackrest/
│   ├── pgbackrest.conf          # pgBackRest configuration
│   └── install-pgbackrest.sh    # Entrypoint script
├── pgbackrest-repo/
│   ├── backup/
│   │   └── db-{uuid}/           # Backup data
│   ├── archive/
│   │   └── db-{uuid}/           # WAL archives
│   └── log/                      # pgBackRest logs
└── docker-compose.yml
```

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

## UI Components

pgBackRest UI is available in two places:

1. **Backups Tab** (`/project/{project}/postgresql/{uuid}/backups`):
   - View backup executions list
   - Restore button on each pgBackRest backup
   - Progress modal during restore

2. **pgBackRest Settings** (`/project/{project}/postgresql/{uuid}/pgbackrest`):
   - Enable/disable pgBackRest
   - Configure retention settings
   - View stanza status
   - Trigger manual backups

## API Endpoints

```
POST /api/v1/databases/{uuid}/pgbackrest/backup
POST /api/v1/databases/{uuid}/pgbackrest/restore
GET  /api/v1/databases/{uuid}/pgbackrest/backups
GET  /api/v1/databases/{uuid}/pgbackrest/status
```

## Notifications

| Notification | Trigger |
|--------------|---------|
| `PgbackrestStanzaCreated` | Stanza successfully created |
| `PgbackrestStanzaFailed` | Stanza operation failed |
| `PgbackrestRestoreSuccess` | Database restored successfully |
| `PgbackrestRestoreFailed` | Restore operation failed |
| `BackupSuccess` | Backup completed successfully |
| `BackupFailed` | Backup operation failed |

## Troubleshooting

### Container in Restart Loop After Failed Restore

If the PostgreSQL container is stuck restarting:

1. The data directory may be partially restored or corrupted
2. Use a temporary container to check/fix:
   ```bash
   # Check backup repository
   docker run --rm \
     -v postgres-data-{uuid}:/var/lib/postgresql/data \
     -v /data/coolify/databases/{uuid}/pgbackrest:/etc/pgbackrest \
     -v /data/coolify/databases/{uuid}/pgbackrest-repo:/var/lib/pgbackrest \
     postgres:16-alpine sh -c "apk add pgbackrest && su postgres -c 'pgbackrest --stanza=db-{uuid} info'"
   
   # Clear data and restore
   docker run --rm -v postgres-data-{uuid}:/data alpine rm -rf /data/*
   # Then run restore...
   ```

### Backup Shows "Not Found" But Data Exists

If backups appear in the execution list but pgBackRest says "not found":
1. Check the actual repository: `du -sh /data/coolify/databases/{uuid}/pgbackrest-repo/`
2. The container may be unable to query pgBackRest (restart loop)
3. Use temporary container approach to query and restore

## Testing

Unit tests are located in `tests/Unit/`:

- `PgbackrestHelpersTest.php` - Helper function tests
- `PgbackrestBackupJobTest.php` - Backup job tests
- `PgbackrestRestoreJobTest.php` - Restore job tests
- `PgbackrestStanzaJobTest.php` - Stanza job tests
- `RestoreFromPgbackrestTest.php` - Restore action tests
- `GeneratePgbackrestConfigTest.php` - Config generation tests

Run tests with:
```bash
./vendor/bin/pest tests/Unit/Pgbackrest*
./vendor/bin/pest tests/Unit/RestoreFromPgbackrest*
```

## Files Reference

### Core Implementation
- `app/Models/StandalonePostgresql.php` - Model with pgBackRest methods
- `app/Actions/Database/StartPostgresql.php` - Configures pgBackRest in container
- `app/Actions/Database/Pgbackrest/GeneratePgbackrestConfig.php` - Generates configs
- `bootstrap/helpers/databases.php` - Helper functions

### Jobs
- `app/Jobs/PgbackrestBackupJob.php` - Runs backups
- `app/Jobs/PgbackrestRestoreJob.php` - Restores using temporary container
- `app/Jobs/PgbackrestStanzaJob.php` - Manages stanza

### Actions
- `app/Actions/Database/Pgbackrest/RestoreFromPgbackrest.php` - Validation and backup listing

### UI Components
- `app/Livewire/Project/Database/BackupExecutions.php` - Backup list with restore
- `app/Livewire/Project/Database/Postgresql/Pgbackrest.php` - Settings page
- `resources/views/livewire/project/database/backup-executions.blade.php`
- `resources/views/livewire/project/database/postgresql/pgbackrest.blade.php`

### Notifications
- `app/Notifications/Database/PgbackrestStanzaCreated.php`
- `app/Notifications/Database/PgbackrestStanzaFailed.php`
- `app/Notifications/Database/PgbackrestRestoreSuccess.php`
- `app/Notifications/Database/PgbackrestRestoreFailed.php`

### Database Migrations
- `database/migrations/2025_12_01_100000_add_pgbackrest_to_standalone_postgresqls_table.php`
- `database/migrations/2025_12_01_100001_add_pgbackrest_to_scheduled_database_backups_table.php`
- `database/migrations/2025_12_01_102142_add_pgbackrest_label_to_scheduled_database_backup_executions_table.php`
- `database/migrations/2025_12_01_120000_add_pgbackrest_retention_options_to_standalone_postgresqls_table.php`
