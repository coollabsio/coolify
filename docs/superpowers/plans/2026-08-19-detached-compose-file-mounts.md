# Detached Compose File Mounts Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Keep file mounts removed from Docker Compose visible in the normal storage list as detached records, and let users explicitly delete only the Coolify database record while always preserving the host file.

**Architecture:** Persist a nullable `compose_status` on `local_file_volumes`: `null` means manually managed or legacy/unclaimed, `active` means observed in the latest successful Compose parse, and `detached` means previously Compose-managed but no longer present. Each successful parser run records the file-volume IDs it observed, then changes only previously active, unobserved rows to detached after parsing succeeds. API and Livewire deletion paths treat detached rows specially: confirmation remains mandatory, backup guards remain active, and server-side file deletion is bypassed unconditionally.

**Tech Stack:** PHP 8.5, Laravel 12/Eloquent, Livewire 3, Blade/Alpine, PostgreSQL, Pest 4.

## Global Constraints

- This work depends on PR #9870's source-aware `(fs_path, mount_path, resource)` identity. Rebase this branch onto `next` after #9870 merges, or stack the cleanup PR on #9870 and retarget it afterward.
- Never infer that an unmatched legacy row is Compose-managed. Existing rows remain `compose_status = null` until a successful parser run matches them.
- Never delete, truncate, overwrite, chmod, chown, or otherwise modify the host file when deleting a detached database row.
- Never mark rows detached after invalid YAML or an aborted/failed parse.
- Keep detached rows in the normal storage list with a visible “Not in Compose” warning.
- Keep scheduled-backup deletion guards unchanged.
- Do not add dependencies.
- Follow TDD: write each failing Pest test, confirm the expected failure, then implement the minimum behavior.
- Run `vendor/bin/pint --dirty --format agent` before every implementation commit.

---

### Task 1: Establish the #9870 dependency and add Compose status persistence

**Files:**
- Create: `database/migrations/2026_08_19_150000_add_compose_status_to_local_file_volumes.php`
- Modify: `app/Models/LocalFileVolume.php`
- Test: `tests/Feature/LocalFileVolumeComposeStatusTest.php`

**Interfaces:**
- Produces constants:
  - `LocalFileVolume::COMPOSE_STATUS_ACTIVE = 'active'`
  - `LocalFileVolume::COMPOSE_STATUS_DETACHED = 'detached'`
- Produces methods:
  - `isComposeManaged(): bool`
  - `isDetachedFromCompose(): bool`
- Produces serialized attribute:
  - `is_detached_from_compose: bool`

- [ ] **Step 1: Bring the prerequisite mount-identity fix into this worktree**

After PR #9870 merges:

```bash
git fetch origin next
git merge origin/next
```

If implementation must begin before merge, stack only the functional #9870 commits and record them in the new PR description:

```bash
git cherry-pick 5dbbca5da 4c8393860 2d88830d5 389992d26
```

Do not cherry-pick the unrelated dump-import utility commit.

- [ ] **Step 2: Generate the migration and test**

```bash
php artisan make:migration add_compose_status_to_local_file_volumes --table=local_file_volumes --no-interaction
php artisan make:test --pest LocalFileVolumeComposeStatusTest --no-interaction
```

- [ ] **Step 3: Write the failing model/status test**

Create the test with `RefreshDatabase`, seed `InstanceSettings::create(['id' => 0])`, and assert:

```php
it('distinguishes manual active and detached file mounts', function () {
    $manual = new LocalFileVolume;
    expect($manual->isComposeManaged())->toBeFalse()
        ->and($manual->isDetachedFromCompose())->toBeFalse();

    $active = new LocalFileVolume(['compose_status' => LocalFileVolume::COMPOSE_STATUS_ACTIVE]);
    expect($active->isComposeManaged())->toBeTrue()
        ->and($active->isDetachedFromCompose())->toBeFalse();

    $detached = new LocalFileVolume(['compose_status' => LocalFileVolume::COMPOSE_STATUS_DETACHED]);
    expect($detached->isComposeManaged())->toBeTrue()
        ->and($detached->isDetachedFromCompose())->toBeTrue()
        ->and($detached->toArray()['is_detached_from_compose'])->toBeTrue();
});
```

- [ ] **Step 4: Run the test and confirm the expected failure**

```bash
php artisan test --compact tests/Feature/LocalFileVolumeComposeStatusTest.php
```

Expected: failure because constants/methods and the column do not exist.

- [ ] **Step 5: Implement the migration**

Use a nullable, indexed string rather than a database enum:

```php
Schema::table('local_file_volumes', function (Blueprint $table) {
    $table->string('compose_status', 16)->nullable()->index();
});
```

The `down()` method drops the index and column. Do not backfill existing rows.

- [ ] **Step 6: Implement model status helpers**

Add `compose_status` to `$fillable`, append `is_detached_from_compose`, and implement:

```php
public const COMPOSE_STATUS_ACTIVE = 'active';
public const COMPOSE_STATUS_DETACHED = 'detached';

public function isComposeManaged(): bool
{
    return in_array($this->compose_status, [
        self::COMPOSE_STATUS_ACTIVE,
        self::COMPOSE_STATUS_DETACHED,
    ], true);
}

public function isDetachedFromCompose(): bool
{
    return $this->compose_status === self::COMPOSE_STATUS_DETACHED;
}

public function getIsDetachedFromComposeAttribute(): bool
{
    return $this->isDetachedFromCompose();
}
```

Use the accessor naming convention already used by `is_binary` and `is_too_large` in this model.

- [ ] **Step 7: Run tests and format**

```bash
php artisan test --compact tests/Feature/LocalFileVolumeComposeStatusTest.php
vendor/bin/pint --dirty --format agent
```

Expected: pass.

- [ ] **Step 8: Commit**

```bash
git add app/Models/LocalFileVolume.php database/migrations tests/Feature/LocalFileVolumeComposeStatusTest.php
git commit -m "feat(file-mounts): track compose lifecycle status"
```

---

### Task 2: Synchronize active and detached statuses after successful parsing

**Files:**
- Modify: `bootstrap/helpers/shared.php`
- Modify: `bootstrap/helpers/parsers.php`
- Test: `tests/Feature/FileStorageParserStateTest.php`

**Interfaces:**
- Produces helper:
  - `syncComposeFileVolumeStatuses(Application|ServiceApplication|ServiceDatabase $resource, Collection $activeIds): void`
- Consumes #9870’s source-aware `LocalFileVolume::updateOrCreate()` identities.

- [ ] **Step 1: Add failing application parser lifecycle tests**

Extend `FileStorageParserStateTest.php` with a Docker Compose application containing two sibling file mounts. Parse once, then change the Compose definition to remove one mount and parse again.

Assert:

```php
expect($remainingMount->compose_status)->toBe(LocalFileVolume::COMPOSE_STATUS_ACTIVE)
    ->and($removedMount->fresh()->compose_status)->toBe(LocalFileVolume::COMPOSE_STATUS_DETACHED)
    ->and($removedMount->fresh())->not->toBeNull();
```

Add a second test that changes `./old.conf` to `./new.conf` at the same target and asserts:

- old row is detached;
- new row is active;
- both database rows remain;
- old and new host files are not accessed or deleted.

- [ ] **Step 2: Add failing safety tests**

Add these cases to the same test file:

```php
it('does not detach manual file mounts', function () {
    // Seed compose_status null, parse Compose without this identity,
    // and assert compose_status remains null.
});

it('does not detach mounts when compose parsing fails', function () {
    // Seed an active row, set malformed docker_compose_raw,
    // call applicationParser(), and assert status remains active.
});

it('detaches all active mounts after a successful compose with no file mounts', function () {
    // Seed active rows, parse valid Compose with services but no bind mounts,
    // and assert they become detached.
});
```

Duplicate the remove-one-mount lifecycle scenario for `serviceParser()` so service-backed resources are covered.

- [ ] **Step 3: Run tests and confirm expected failures**

```bash
php artisan test --compact tests/Feature/FileStorageParserStateTest.php
```

Expected: rows remain active or null because synchronization is not implemented.

- [ ] **Step 4: Implement the synchronization helper**

Add to `bootstrap/helpers/shared.php`:

```php
function syncComposeFileVolumeStatuses(
    Application|ServiceApplication|ServiceDatabase $resource,
    Collection $activeIds,
): void {
    $ids = $activeIds->filter()->unique()->values();

    if ($ids->isNotEmpty()) {
        $resource->fileStorages()
            ->whereKey($ids)
            ->update(['compose_status' => LocalFileVolume::COMPOSE_STATUS_ACTIVE]);
    }

    $resource->fileStorages()
        ->where('compose_status', LocalFileVolume::COMPOSE_STATUS_ACTIVE)
        ->when($ids->isNotEmpty(), fn ($query) => $query->whereNotIn('id', $ids))
        ->update(['compose_status' => LocalFileVolume::COMPOSE_STATUS_DETACHED]);
}
```

This helper intentionally updates only rows already claimed as active. Rows with `compose_status = null` remain untouched.

- [ ] **Step 5: Track IDs in `applicationParser()`**

Initialize one collection before walking services:

```php
$activeFileStorageIds = collect();
```

For every Compose bind mount, capture the returned model:

```php
$fileStorage = LocalFileVolume::updateOrCreate(
    $identity,
    [
        ...$attributes,
        'compose_status' => LocalFileVolume::COMPOSE_STATUS_ACTIVE,
    ],
);

$activeFileStorageIds->push($fileStorage->id);
```

Call synchronization exactly once immediately before the successful final return:

```php
syncComposeFileVolumeStatuses($originalResource, $activeFileStorageIds);
```

Do not call it from YAML exception/early-failure paths.

- [ ] **Step 6: Track IDs per resource in `serviceParser()` and `parseDockerComposeFile()`**

Use a collection keyed by model morph class and ID:

```php
$parsedFileStorageResources = collect();
$activeFileStorageIdsByResource = collect();

$key = $savedService->getMorphClass().':'.$savedService->id;
$parsedFileStorageResources->put($key, $savedService);
$ids = $activeFileStorageIdsByResource->get($key, collect());
$ids->push($fileStorage->id);
$activeFileStorageIdsByResource->put($key, $ids);
```

Before the successful return, synchronize every parsed `ServiceApplication` and `ServiceDatabase`, including resources whose valid Compose service currently has zero bind mounts. Do not synchronize resources that parsing did not complete.

Use the same returned-model pattern and set `compose_status = active` in all three `LocalFileVolume::updateOrCreate()` call sites.

- [ ] **Step 7: Run focused parser tests and format**

```bash
php artisan test --compact tests/Feature/FileStorageParserStateTest.php
php artisan test --compact tests/Feature/ApplicationComposeMultipleFileMountsTest.php
vendor/bin/pint --dirty --format agent
```

Expected: all lifecycle, sibling, preview-path, and read-only tests pass.

- [ ] **Step 8: Commit**

```bash
git add bootstrap/helpers/parsers.php bootstrap/helpers/shared.php tests/Feature/FileStorageParserStateTest.php
git commit -m "feat(file-mounts): detach mounts removed from compose"
```

---

### Task 3: Expose detached status and implement safe API deletion

**Files:**
- Modify: `app/Http/Controllers/Api/ApplicationsController.php`
- Modify: `app/Http/Controllers/Api/ServicesController.php`
- Modify: `app/Http/Controllers/Api/DatabasesController.php`
- Test: `tests/Feature/StorageApiTest.php`

**Interfaces:**
- Consumes:
  - `LocalFileVolume::isDetachedFromCompose(): bool`
  - serialized `compose_status`
  - serialized `is_detached_from_compose`
- Behavior:
  - detached deletion deletes only the row;
  - active Compose mounts remain protected;
  - manual file-storage deletion retains existing behavior.

- [ ] **Step 1: Add failing API serialization tests**

For application, service, and database storage-list endpoints, create a detached `LocalFileVolume` and assert the response contains:

```php
[
    'compose_status' => LocalFileVolume::COMPOSE_STATUS_DETACHED,
    'is_detached_from_compose' => true,
]
```

Use existing authenticated API setup and UUID-based storage routes from `StorageApiTest.php`.

- [ ] **Step 2: Add failing API deletion tests**

For each of the three delete endpoints, create a detached file volume and assert:

```php
$response->assertSuccessful();
$this->assertDatabaseMissing('local_file_volumes', ['id' => $storage->id]);
```

Fake or spy on remote process execution using the existing storage API test convention, then assert no server deletion command was dispatched.

Add companion tests:

- active Compose-managed row returns 422;
- detached row with a scheduled backup returns 422;
- manual file volume retains the existing host-file deletion behavior.

- [ ] **Step 3: Run API tests and confirm expected failures**

```bash
php artisan test --compact tests/Feature/StorageApiTest.php
```

Expected: detached rows are rejected as read-only or attempt server deletion.

- [ ] **Step 4: Update application deletion**

Change the guard order:

```php
$isDetachedFileStorage = $storage instanceof LocalFileVolume
    && $storage->isDetachedFromCompose();

if ($storage->shouldBeReadOnlyInUI() && ! $isDetachedFileStorage) {
    return response()->json([
        'message' => 'This storage is read-only (managed by docker-compose or service definition) and cannot be deleted.',
    ], 422);
}

$storage->abortIfScheduledBackupsExist();

if ($storage instanceof LocalFileVolume && ! $isDetachedFileStorage) {
    $storage->deleteStorageOnServer();
}

$storage->delete();
```

Use the same logic in service and database deletion endpoints. Keep existing authorization and audit logging.

- [ ] **Step 5: Run API tests and format**

```bash
php artisan test --compact tests/Feature/StorageApiTest.php
vendor/bin/pint --dirty --format agent
```

Expected: pass with no remote deletion for detached rows.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/ApplicationsController.php app/Http/Controllers/Api/ServicesController.php app/Http/Controllers/Api/DatabasesController.php tests/Feature/StorageApiTest.php
git commit -m "fix(api): safely delete detached compose mounts"
```

---

### Task 4: Show detached mounts in the normal storage list with confirmation

**Files:**
- Modify: `app/Livewire/Project/Service/FileStorage.php`
- Modify: `resources/views/livewire/project/service/file-storage.blade.php`
- Test: `tests/Feature/Livewire/DetachedFileStorageTest.php`

**Interfaces:**
- Consumes `LocalFileVolume::isDetachedFromCompose(): bool`.
- Produces user-visible badge/callout text: `Not in Compose`.
- Produces confirmation statement: `Only the Coolify database record will be deleted. The host file or directory will be preserved.`

- [ ] **Step 1: Generate and write failing Livewire tests**

```bash
php artisan make:test --pest DetachedFileStorageTest --no-interaction
```

Test that rendering a detached file storage shows:

```php
Livewire::test(FileStorage::class, ['fileStorage' => $storage])
    ->assertSee('Not in Compose')
    ->assertSee('The host file or directory will be preserved.');
```

Test confirmed deletion:

```php
Livewire::test(FileStorage::class, ['fileStorage' => $storage])
    ->call('delete', 'password', ['permanentlyDelete'])
    ->assertDispatched('success');

$this->assertDatabaseMissing('local_file_volumes', ['id' => $storage->id]);
Process::assertNothingRan();
```

Use the repository’s existing password-confirmation and process-fake conventions. Add a scheduled-backup test that asserts deletion is rejected.

- [ ] **Step 2: Run the test and confirm expected failure**

```bash
php artisan test --compact tests/Feature/Livewire/DetachedFileStorageTest.php
```

Expected: missing badge and/or detached deletion attempts physical deletion.

- [ ] **Step 3: Update the Livewire component**

Add:

```php
public bool $isDetachedFromCompose = false;
```

Set it during `mount()`:

```php
$this->isDetachedFromCompose = $this->fileStorage->isDetachedFromCompose();
```

In `delete()`, retain authorization, password confirmation, and scheduled-backup checks, then force database-only behavior:

```php
if ($this->isDetachedFromCompose) {
    $this->permanently_delete = false;
    $this->fileStorage->delete();
    $this->dispatch('configurationChanged');
    $this->dispatch('success', 'Detached storage record deleted. The host file was preserved.');

    return true;
}
```

Do not call `deleteStorageOnServer()` in this branch, even if a crafted Livewire request submits a permanent-delete action.

- [ ] **Step 4: Update the normal storage view**

Before the existing read-only callout, render a warning callout for detached rows:

```blade
@if ($isDetachedFromCompose)
    <x-callout type="warning" title="Not in Compose">
        This storage mount is no longer referenced by the current Docker Compose configuration.
        You can remove its Coolify record; the host file or directory will be preserved.
    </x-callout>
@endif
```

Inside the read-only action area, render a dedicated confirmation modal:

```blade
<x-modal-confirmation
    :ignoreWire="false"
    title="Remove detached storage record?"
    buttonTitle="Delete record"
    isErrorButton
    submitAction="delete"
    :actions="[
        'Only the Coolify database record will be deleted.',
        'The host file or directory will be preserved.',
    ]"
    confirmationText="{{ $fs_path }}"
    confirmationLabel="Enter the source path to confirm"
    shortConfirmationLabel="Source path" />
```

Do not expose permanent-delete checkboxes for detached rows.

- [ ] **Step 5: Run Livewire tests and format**

```bash
php artisan test --compact tests/Feature/Livewire/DetachedFileStorageTest.php
vendor/bin/pint --dirty --format agent
```

Expected: pass.

- [ ] **Step 6: Commit**

```bash
git add app/Livewire/Project/Service/FileStorage.php resources/views/livewire/project/service/file-storage.blade.php tests/Feature/Livewire/DetachedFileStorageTest.php
git commit -m "feat(ui): surface detached compose file mounts"
```

---

### Task 5: Regression verification and live smoke test

**Files:**
- Modify only if a failing regression requires a scoped fix.
- Test: existing targeted suites.

**Interfaces:**
- Verifies migration, parser lifecycle, API serialization/deletion, Livewire confirmation, and host-file preservation.

- [ ] **Step 1: Run all focused automated tests**

```bash
php artisan test --compact \
  tests/Feature/LocalFileVolumeComposeStatusTest.php \
  tests/Feature/FileStorageParserStateTest.php \
  tests/Feature/ApplicationComposeMultipleFileMountsTest.php \
  tests/Feature/StorageApiTest.php \
  tests/Feature/Livewire/DetachedFileStorageTest.php \
  tests/Unit/LocalFileVolumeReadOnlyTest.php
```

Expected: zero failures.

- [ ] **Step 2: Run formatting and static checks**

```bash
vendor/bin/pint --dirty --format agent
git diff --check
php -l app/Models/LocalFileVolume.php
php -l bootstrap/helpers/parsers.php
php -l bootstrap/helpers/shared.php
```

Expected: all commands exit 0.

- [ ] **Step 3: Start or locate the Jean run environment**

Call Jean `get_run_environments` for worktree ID `1915f1c7-d20e-43ad-bfdd-8bba3967a67a`. If nothing is running, use the exact returned Compose startup command. Record the URL and port used.

- [ ] **Step 4: Perform a live parser/UI smoke test**

Create a Docker Compose application with:

```yaml
services:
  first:
    image: nginx:alpine
    volumes:
      - ./first.conf:/etc/nginx/conf.d/default.conf:ro
  second:
    image: nginx:alpine
    volumes:
      - ./second.conf:/etc/nginx/conf.d/default.conf
```

Verify both rows show normally. Remove `second` from Compose and save/reparse. Verify:

- `first.conf` remains active;
- `second.conf` remains in the normal list;
- `second.conf` shows “Not in Compose”;
- its confirmation explicitly says the host path is preserved;
- cancelling leaves the row unchanged;
- confirming deletes only the database row;
- the host file still exists;
- active `first.conf` cannot be deleted from the storage UI.

- [ ] **Step 5: Inspect the database after the smoke test**

Using the running worktree PostgreSQL container:

```sql
SELECT fs_path, mount_path, compose_status
FROM local_file_volumes
WHERE resource_id = :application_id
ORDER BY fs_path;
```

Before confirmation, expect one `active` and one `detached`. After confirmation, expect only the active row.

- [ ] **Step 6: Review the final branch diff**

```bash
git status --short
git diff --stat origin/next...HEAD
git log --oneline origin/next..HEAD
```

Confirm the branch contains only Compose file-mount lifecycle work. Do not include dump-import utilities or unrelated #9870 development tooling.

- [ ] **Step 7: Final commit if verification required changes**

Only if Step 1–6 produced a scoped correction:

```bash
git add -u
git commit -m "test(file-mounts): verify detached compose lifecycle"
```

