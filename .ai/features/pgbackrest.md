# pgBackRest Integration for PostgreSQL

This document describes the pgBackRest backup and restore implementation for PostgreSQL databases in Coolify.

## Overview

pgBackRest is a reliable backup and restore solution for PostgreSQL that provides features like:
- Full, differential, and incremental backups
- Parallel backup and restore
- Local and S3 backup repositories
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

## Repository Types

pgBackRest supports two repository types:

### Local (posix) Repository
- Backups stored on local filesystem
- Bind-mounted from host: `/data/coolify/databases/{uuid}/pgbackrest-repo`
- Fast backup/restore operations
- Limited by local storage capacity

### S3 Repository
- Backups stored in S3-compatible object storage (AWS S3, MinIO, etc.)
- Credentials passed via environment variables
- Network-dependent performance
- Unlimited scalable storage

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

### Critical Invariants

1. **PGDATA must be empty before restore** - The restore flow automatically clears PGDATA (no user confirmation required)
2. **PostgreSQL must be stopped** - Cannot restore into a running database
3. **Restore uses temporary container** - Cannot restore inside the main container (it must be stopped)

### Restore Flow

The `PgbackrestRestoreJob` implements a safe, idempotent restore flow:

```
┌─────────────────────────────────────────────────────────────┐
│                    RESTORE FLOW                              │
├─────────────────────────────────────────────────────────────┤
│                                                              │
│  1. PRE-FLIGHT VALIDATION (before any destructive action)   │
│     ├── Check pgBackRest enabled                            │
│     ├── Check S3 config complete (if S3 repo)               │
│     ├── Resolve mounts (config, repo, data volume)          │
│     ├── Run pgbackrest info in TEMP CONTAINER               │
│     ├── Verify stanza exists and is healthy                 │
│     ├── Verify requested backup exists in repository        │
│     └── If ANY check fails: ABORT (no data touched)         │
│                                                              │
│  2. STOP POSTGRESQL                                          │
│     └── docker stop -t 30 {container}                       │
│                                                              │
│  3. CLEAR PGDATA (automatic, no confirmation)               │
│     └── docker run alpine rm -rf /data/* /data/.*           │
│                                                              │
│  4. RESTORE VIA TEMP CONTAINER                               │
│     ├── Mount: data volume, config, repo (local) or S3 env  │
│     ├── Install pgBackRest in temp container                │
│     ├── Fix permissions                                      │
│     └── Run pgbackrest restore                              │
│                                                              │
│  5. START POSTGRESQL                                         │
│     └── StartPostgresql::run() recreates container          │
│                                                              │
└─────────────────────────────────────────────────────────────┘
```

### Volume Mounts for Restore

```php
$mounts = [
    'data_volume' => "postgres-data-{$containerName}",
    'pgbackrest_config' => "{$configDir}/pgbackrest",
    'pgbackrest_repo' => "{$configDir}/pgbackrest-repo", // Only for local repos
];
```

The temporary restore container command (local repo):
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

For S3 repos, credentials are passed via environment variables:
```bash
docker run --rm \
  -e PGBACKREST_REPO1_S3_KEY="..." \
  -e PGBACKREST_REPO1_S3_KEY_SECRET="..." \
  -v postgres-data-{uuid}:/var/lib/postgresql/data \
  -v {configDir}/pgbackrest:/etc/pgbackrest \
  postgres:16-alpine sh -c '...'
```

## Key Components

### PgbackrestService

Central service (`app/Services/PgbackrestService.php`) for all pgBackRest operations:

| Method | Description |
|--------|-------------|
| `isEnabled()` | Check if pgBackRest is enabled |
| `getRepoType()` | Get repository type (posix/s3) |
| `isS3Repo()` | Check if using S3 repository |
| `getStanzaName()` | Get stanza name (`db-{uuid}`) |
| `getMounts()` | Resolve volume mount paths |
| `getInfo()` | Get pgBackRest info JSON |
| `getBackupList()` | Get formatted backup list |
| `validateRestore()` | Basic restore validation |
| `validateRestoreDeep()` | **Full pre-flight validation** |
| `stopContainer()` | Stop PostgreSQL container |
| `clearDataDirectory()` | Empty PGDATA volume |
| `restore()` | Execute restore operation |
| `getDiagnostics()` | Get debugging information |

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
| `GeneratePgbackrestConfig` | Generates pgbackrest.conf (local + S3 support) |
| `RestoreFromPgbackrest` | Validates restore operations, gets available backups |

## Configuration

### Database Fields

| Field | Type | Description |
|-------|------|-------------|
| `pgbackrest_enabled` | boolean | Enable/disable pgBackRest |
| `pgbackrest_retention_full` | integer | Number of full backups to retain |
| `pgbackrest_retention_diff` | integer | Number of differential backups to retain |
| `pgbackrest_retention_full_type` | string | Retention type (count/time) |
| `pgbackrest_retention_archive` | integer | Archive retention |
| `pgbackrest_retention_archive_type` | string | Archive retention type |
| `pgbackrest_compress_type` | string | Compression type (lz4/zst/gz) |
| `pgbackrest_compress_level` | integer | Compression level (0-9) |
| `pgbackrest_log_level` | string | Log level |
| `pgbackrest_repo_type` | string | Repository type (posix/s3) |
| `pgbackrest_s3_bucket` | string | S3 bucket name |
| `pgbackrest_s3_endpoint` | string | S3 endpoint URL |
| `pgbackrest_s3_region` | string | S3 region |
| `pgbackrest_s3_key` | encrypted | S3 access key |
| `pgbackrest_s3_secret` | encrypted | S3 secret key |
| `pgbackrest_s3_uri_style` | string | S3 URI style (path/host) |
| `pgbackrest_s3_verify_tls` | boolean | Verify TLS certificates |
| `pgbackrest_restore_status` | string | Current restore status |
| `pgbackrest_restore_message` | text | Restore status message |

### Directory Structure

```
/data/coolify/databases/{uuid}/
├── pgbackrest/
│   ├── pgbackrest.conf          # pgBackRest configuration
│   └── install-pgbackrest.sh    # Entrypoint script
├── pgbackrest-repo/              # Local repository only
│   ├── backup/
│   │   └── db-{uuid}/           # Backup data
│   ├── archive/
│   │   └── db-{uuid}/           # WAL archives
│   └── log/                      # pgBackRest logs
└── docker-compose.yml
```

### S3 Configuration in pgbackrest.conf

```ini
[global]
repo1-type=s3
repo1-path=/coolify/{uuid}
repo1-s3-bucket=my-bucket
repo1-s3-endpoint=s3.amazonaws.com
repo1-s3-region=us-east-1
repo1-s3-uri-style=path
repo1-s3-verify-tls=y
# ... other settings
```

## Troubleshooting

### "Backup not found in pgBackRest repository"

This is the most common error. Debugging checklist:

#### 1. Verify stanza and repo from temp container (restore context)
```bash
# On the Docker host:
docker run --rm \
  -v /data/coolify/databases/{uuid}/pgbackrest:/etc/pgbackrest \
  -v /data/coolify/databases/{uuid}/pgbackrest-repo:/var/lib/pgbackrest \
  postgres:16-alpine sh -c "apk add pgbackrest && su postgres -c 'pgbackrest --stanza=db-{uuid} info --output=json'"
```

#### 2. Compare with main container context
```bash
docker exec {container} sh -c "pgbackrest --stanza=db-{uuid} info --output=json"
```
If main works but temp fails → config/repo mount mismatch.

#### 3. List available backups
```bash
pgbackrest --stanza=db-{uuid} info --output=json | jq '.[0].backup[].label'
```

#### 4. Check physical repository (local repos)
```bash
ls -R /data/coolify/databases/{uuid}/pgbackrest-repo/backup/db-{uuid}
du -sh /data/coolify/databases/{uuid}/pgbackrest-repo/
```

#### 5. For S3 repos - check connectivity
```bash
# With debug logging:
pgbackrest --stanza=db-{uuid} info --output=json --log-level-console=debug

# Check S3 directly:
aws s3 ls s3://{bucket}/coolify/{uuid}/backup/db-{uuid}/
```

#### 6. Check stanza name consistency
```bash
# In main container:
docker exec {container} cat /etc/pgbackrest/pgbackrest.conf

# From host:
cat /data/coolify/databases/{uuid}/pgbackrest/pgbackrest.conf
```
Ensure stanza name, repo1-type, repo1-path match.

#### 7. Check pgBackRest logs
```bash
tail -n 100 /data/coolify/databases/{uuid}/pgbackrest-repo/log/*.log
```

#### 8. Check for retention expiry
```bash
grep -R "expire" /data/coolify/databases/{uuid}/pgbackrest-repo/log/
grep -R "{missing-label}" /data/coolify/databases/{uuid}/pgbackrest-repo/log/
```

### Common Causes

| Cause | Detection | Solution |
|-------|-----------|----------|
| Wrong stanza name | `info` fails with "stanza not found" | Verify stanza in pgbackrest.conf |
| Wrong repo path | `info` shows empty backup list | Check volume mounts |
| Repo not initialized | `info` fails with stanza error | Run `stanza-create` |
| Backup expired | Backup missing from `info` output | Check retention settings |
| S3 credentials wrong | Network/auth errors | Verify S3 key/secret |
| S3 endpoint wrong | Connection refused/timeout | Check endpoint URL |
| Config mismatch | Main works, temp fails | Regenerate config |

### Container in Restart Loop After Failed Restore

1. Stop the container: `docker stop {uuid}`
2. Check data directory state
3. Use temporary container to clear and restore:
```bash
# Clear data
docker run --rm -v postgres-data-{uuid}:/data alpine rm -rf /data/* /data/.*

# Restore
docker run --rm \
  -v postgres-data-{uuid}:/var/lib/postgresql/data \
  -v /data/coolify/databases/{uuid}/pgbackrest:/etc/pgbackrest \
  -v /data/coolify/databases/{uuid}/pgbackrest-repo:/var/lib/pgbackrest \
  postgres:16-alpine sh -c "
    apk add pgbackrest;
    chown -R postgres:postgres /var/lib/postgresql/data /var/lib/pgbackrest /etc/pgbackrest;
    su postgres -c 'pgbackrest --stanza=db-{uuid} --type=immediate --target-action=promote --delta restore'
  "
```

## Safety Considerations

### Pre-flight Validation

The `validateRestoreDeep()` method ensures safety before any destructive action:

1. **Enabled check**: pgBackRest must be enabled
2. **S3 config check**: All S3 fields must be complete (if S3 repo)
3. **Mount resolution**: Config, repo (local), and data volume must resolve
4. **Repository accessibility**: `pgbackrest info` must succeed in temp container
5. **Stanza health**: Stanza status must be OK
6. **Backup existence**: Requested backup must exist

**If any check fails, restore is aborted with no data touched.**

### Idempotency

The restore flow is designed to be safely re-runnable:
- Pre-flight ensures backup is accessible before clearing data
- `stopContainer()` is safe to call on stopped containers
- `clearDataDirectory()` is safe on empty directories
- Failed restore leaves container stopped (won't start with partial data)

### Rollback Considerations

There is no automatic rollback because:
- PGDATA is intentionally cleared before restore
- The source of truth is the pgBackRest repository

To mitigate:
- Pre-flight validation catches most issues before clearing data
- Take a backup before major operations (documented in UI)
- Failed restores can be retried safely

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
- `app/Services/PgbackrestService.php` - Centralized service for all operations
- `app/Models/StandalonePostgresql.php` - Model with pgBackRest fields and methods
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
- `database/migrations/2025_12_02_100000_add_pgbackrest_s3_to_standalone_postgresqls_table.php`
