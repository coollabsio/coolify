# compose-zero-downtime: review follow-ups

## Done (2026-09-09)

- [x] Scope storage-sharing modal events to the owning Livewire component (`scope: $this->getId()`), so one change opens one modal only.
- [x] `cancelShareStorage` authorizes `update` in All, Show and FileStorage.
- [x] `Show::instantSave` uses `$this->validate()` again.
- [x] `GetLogs` reads the PR number from the `coolify.pullRequestId` label passed by `logs.blade.php`, not from the container name.
- [x] `ScheduledTaskJob`: `$matchedComposeService` is declared before the branch; behavior unchanged (empty container + many containers still throws).
- [x] `ApplicationPreview` force delete runs `docker compose --project-name {uuid}-pr-N down --remove-orphans` (no `-v`) and only removes volumes that end with `-pr-N`.
- [x] Tests: `tests/Feature/StorageSharingConfirmationTest.php` (new), `GetLogsCommandInjectionTest.php`, `ApplicationPreviewVolumeCleanupTest.php`.
- [x] Compose preview named-volume rows are owned by the `ApplicationPreview` (parser v3+). Existing previews are grandfathered: when the application already has the `-pr-N` row, the parser keeps updating that row. No migration. Test: `tests/Feature/ComposePreviewVolumeOwnershipTest.php`.
- [x] Verified preview delete via API on the Jean dev env (`http://127.0.0.1:8000`): only `-pr-N` volume, preview containers and network removed; production untouched.

## Open

- [ ] `tests/Feature/PreviewDomainPortOverridesTest.php` lines 900 and 945 still read `services.web-pr-N.labels`; change to `services.web.labels` (3 failures).
- [ ] `VolumeBackupTest` and `ApplicationConfigAuthorizationTest` have 12 pre-existing failures on this branch (same count without the follow-up changes).
- [ ] `PreviewDomains.php:632` strips `-pr-N` without a parser version gate; share one helper with `ApplicationPreview`.
- [ ] Bind mount file rows: a preview parse still overwrites the production `LocalFileVolume` row's `fs_path` with the `-pr-N` path. Fixing this needs preview-aware `saveStorageOnServer`, `ServerFilesFromServerJob` and the deploy file loop. Not done.
- [ ] Pre-existing test order dependence: `ApplicationPreviewVolumeCleanupTest` line 71 fails when run after any parser test file (also without these changes).
