# compose-zero-downtime: follow-ups

## Done (2026-09-09): preview file storages and service name helper

Design: one `LocalFileVolume` row per mount stays on the application with the production path. The preview path is derived at use time with `LocalFileVolume::fsPathForPullRequest()`. No preview-owned rows, no migration. The parser never writes preview copies to the server (it also runs from the UI and the delete hook); `ApplicationDeploymentJob::write_preview_file_storages()` writes them before the stack starts.

- [x] `Application::composeServiceNamesForPreview()` is the single helper; `ApplicationPreview` and `PreviewDomains` delegate to it.
- [x] `LocalFileVolume::fsPathForPullRequest()`; `saveStorageOnServer()` / `deleteStorageOnServer()` / `ServerStorageSaveJob` accept a pull request id.
- [x] Parser bind branch always writes the production path and derives the preview path for the compose file.
- [x] Deploy: `write_preview_file_storages()` after parse for PR deploys; the two `preserveRepository` loops pass the PR id.
- [x] Preview force delete removes preview copies of bind mounts (never production paths).
- [x] Tests: `tests/Feature/ComposePreviewFileStorageTest.php`, `tests/Feature/ComposeServiceNamesForPreviewTest.php`.
- [x] Dev instance smoke test with `coolify-examples` `docker-compose-test/docker-compose-local-volumes.yaml`: production deploy, new preview deploy, existing preview redeploy from legacy rows (rows healed, copies kept with content, shared mount uses production dir, production not restarted), production redeploy, preview delete via API (copies removed and not recreated).

## Post-commit check (2026-09-09, after ce4e22a64)

- [x] Regression found by `tests/Feature/ApplicationDomainsTest.php` "does not erase preview domains when parse fails": `composeServiceNamesForPreview()` swallowed YAML errors and returned `[]`, so `PreviewDomains::persistDomains()` erased all stored preview domains on an unparsable compose file. Fixed: the helper throws on invalid YAML (blank compose still returns `[]`); `ApplicationPreview` keeps tolerating it for FQDN generation.
- [x] Dev instance smoke test after the commit: production deploy, new preview, existing preview redeploy from legacy rows, production redeploy, preview delete, plus redeploy of the existing "Docker Compose Example" app. All good.
- [ ] Full suite on this host has pre-existing failures unrelated to the branch (host PHP has no phpredis, DNS-dependent assertions, Mockery `setAttribute` on model mocks, `S3StorageFormTest` double `uses()` breaks a whole-Feature run).

## Deferred

- [ ] Legacy `-pr-N` volume rows on the application (previews parsed before the ownership fix) stay after preview delete. Cleanup later.
- [ ] Pre-existing on `main`: 12 failures in `VolumeBackupTest` and `ApplicationConfigAuthorizationTest`, and `ApplicationPreviewVolumeCleanupTest` line 71 fails when run after any parser test file.
