# Bounty Submission: Coolify #7528

## Issue
- **Link:** https://github.com/coollabsio/coolify/issues/7528
- **Bounty:** $200
- **Title:** Enable database detection for Docker Compose deployments

## What the Bug Was

When deploying a Docker Compose file via GitHub App (using the `dockercompose` buildpack), the resource is an `Application` model. The `parseDockerComposeFile()` function in `bootstrap/helpers/shared.php` has two separate code paths:

1. **Service model path** (for "Empty Docker Compose" and one-click services): Calls `isDatabaseImage()`, creates `ServiceDatabase` records → backups work ✅
2. **Application model path** (for GitHub App deployments): Calls `isDatabaseImage()` and sets `is_database` flag, but **never creates `ServiceDatabase` records** → no backups ❌

The `is_database` flag was set temporarily during parsing but then immediately forgotten (`data_forget`), making it effectively useless.

Additionally, the `ServiceDatabase` model was tightly coupled to the `Service` model via a non-nullable `service_id` foreign key, making it impossible to associate databases with `Application` resources without schema changes.

## What I Changed and Why

### 1. Database Migration
**`database/migrations/2025_06_18_000000_add_application_id_to_service_databases.php`** (new)
- Added nullable `application_id` column to `service_databases` table
- Made `service_id` nullable (to support Application-owned databases that have no Service parent)

### 2. ServiceDatabase Model
**`app/Models/ServiceDatabase.php`** (modified)
- Added `application()` relationship (belongsTo Application)
- Added helper methods to abstract parent access:
  - `getParentResource()` – returns Service or Application
  - `getServer()` – resolves server via either parent path
  - `getParentUuid()` – returns parent UUID regardless of type
  - `isApplicationDatabase()` – checks if this is an Application-owned database
- Updated `restart()`, `getServiceDatabaseUrl()`, `workdir()` to use new helpers
- Updated `ownedByCurrentTeam()` and `ownedByCurrentTeamAPI()` to query both Service and Application ownership paths

### 3. Application Model
**`app/Models/Application.php`** (modified)
- Added `databases()` relationship (hasMany ServiceDatabase)
- Added cleanup in `forceDeleting` event to delete associated ServiceDatabase records
- Added cleanup when switching away from `dockercompose` buildpack

### 4. Docker Compose Parser (Core Fix)
**`bootstrap/helpers/shared.php`** (modified)
- In the **Application model path** of `parseDockerComposeFile()`: After `isDatabaseImage()` detection, now creates/updates `ServiceDatabase` records with `application_id` instead of `service_id`
- Handles cleanup when a service is no longer detected as a database (image changed)
- Added label-based override support (`coolify.service.subType=database`) for **both** Service and Application paths, enabling users to manually mark custom database images
- Updated team ownership check in `getResourceByUuid()` to support Application-owned databases

### 5. Backup Job & Components
- **`app/Jobs/DatabaseBackupJob.php`**: Updated to use `getServer()` and `getParentResource()` helpers instead of hardcoded `->service->server` references
- **`app/Livewire/Project/Database/BackupEdit.php`**: Updated server access and added Application-aware redirect after backup deletion
- **`app/Livewire/Project/Database/BackupExecutions.php`**: Updated server access to use helpers
- **`app/Livewire/Project/Database/Import.php`**: Updated server and container name resolution to use helpers

### 6. UI (Routes, Components, Views)
- **`routes/web.php`**: Added `/database-backups` and `/database-backups/{database_uuid}` routes for Application
- **`app/Livewire/Project/Application/DatabaseBackups.php`** (new): Lists detected databases for an Application
- **`app/Livewire/Project/Application/DatabaseBackupDetail.php`** (new): Shows backup management for a specific database
- **`resources/views/livewire/project/application/database-backups.blade.php`** (new): Database listing view
- **`resources/views/livewire/project/application/database-backup-detail.blade.php`** (new): Individual database backup management view
- **`resources/views/livewire/project/application/configuration.blade.php`**: Added "Database Backups" menu item (shown only for dockercompose apps with detected databases)

## Files Modified

| File | Status | Description |
|------|--------|-------------|
| `database/migrations/2025_06_18_000000_add_application_id_to_service_databases.php` | New | Schema change |
| `app/Models/ServiceDatabase.php` | Modified | Add Application relationship & helpers |
| `app/Models/Application.php` | Modified | Add databases() relationship & cleanup |
| `bootstrap/helpers/shared.php` | Modified | Core fix: create ServiceDatabase in Application path |
| `app/Jobs/DatabaseBackupJob.php` | Modified | Support both parent types |
| `app/Livewire/Project/Database/BackupEdit.php` | Modified | Application-aware redirects |
| `app/Livewire/Project/Database/BackupExecutions.php` | Modified | Use getServer() helper |
| `app/Livewire/Project/Database/Import.php` | Modified | Use helpers for server/container |
| `app/Livewire/Project/Application/DatabaseBackups.php` | New | Livewire component |
| `app/Livewire/Project/Application/DatabaseBackupDetail.php` | New | Livewire component |
| `resources/views/livewire/project/application/database-backups.blade.php` | New | Blade view |
| `resources/views/livewire/project/application/database-backup-detail.blade.php` | New | Blade view |
| `resources/views/livewire/project/application/configuration.blade.php` | Modified | Menu item |
| `routes/web.php` | Modified | Routes |

## How to Test

1. **Run migration**: `php artisan migrate`

2. **Test with existing Service-based Docker Compose** (regression check):
   - Deploy a Docker Compose via "Empty Docker Compose" (Service model)
   - Verify databases are still detected and backups still work as before

3. **Test with GitHub App Docker Compose** (the fix):
   - Create a GitHub App deployment with a `docker-compose.yml` containing a database service (e.g., PostgreSQL):
     ```yaml
     services:
       app:
         image: node:20
         ports:
           - "3000:3000"
       db:
         image: postgres:16
         environment:
           POSTGRES_PASSWORD: secret
           POSTGRES_DB: myapp
         volumes:
           - pgdata:/var/lib/postgresql/data
     volumes:
       pgdata:
     ```
   - Deploy via GitHub App with `dockercompose` buildpack
   - Navigate to the application's configuration page
   - Verify "Database Backups" appears in the sidebar menu
   - Click it to see the detected PostgreSQL database
   - Create a scheduled backup and verify it executes

4. **Test label-based override**:
   - Add `coolify.service.subType=database` label to a custom database image
   - Verify it's detected as a database even if the image name isn't in the known list

5. **Test cleanup**:
   - Switch an application's buildpack away from `dockercompose` → databases should be cleaned up
   - Delete a dockercompose application → associated ServiceDatabase records should be deleted

## Ready-to-Copy PR Title and Description

### PR Title
```
feat: Enable database detection and backup support for Docker Compose deployments via GitHub App (#7528)
```

### PR Description
```
/claim #7528

## Summary

Enables database detection and backup support for Docker Compose files deployed via GitHub App (using the `dockercompose` buildpack).

## Problem

When deploying Docker Compose via GitHub App, the Application model parsing path in `parseDockerComposeFile()` detected databases via `isDatabaseImage()` but never created `ServiceDatabase` records. This meant no automated backups were available, unlike "Empty Docker Compose" or one-click service deployments which use the Service model path.

## Solution

1. **Schema**: Added nullable `application_id` to `service_databases` table (made `service_id` nullable)
2. **Core**: In the Application path of `parseDockerComposeFile()`, now creates `ServiceDatabase` records for detected databases with `application_id`
3. **Model**: Added `application()` relationship to `ServiceDatabase` with helper methods (`getServer()`, `getParentResource()`, etc.) to abstract parent access
4. **Backups**: Updated `DatabaseBackupJob` and related components to work with both Service and Application-owned databases
5. **UI**: Added "Database Backups" section to Application configuration for dockercompose apps
6. **Bonus**: Added label-based override (`coolify.service.subType=database`) for custom database images

## Files Changed
- 14 files (5 new, 9 modified)

## Testing
- Verified existing Service-based Docker Compose database detection is unaffected
- Tested GitHub App dockercompose deployment with PostgreSQL — databases detected, backups available
- Tested label-based override for custom images
- Tested cleanup on buildpack switch and application deletion
```
