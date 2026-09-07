# Resolve merge conflicts

- [x] Inspect both sides and related tests/usages for all eight paths.
- [x] Resolve conflicts while preserving existing-volume support and `next` branch behavior.
- [x] Stage each resolved path.
- [x] Run targeted tests and formatting.
- [x] Continue the merge and resolve any new conflicts.
- [x] Search GitHub issues and discussions for related reports.
- [x] Record verification and review results.

## Review

- Merged `origin/next` into `existing-volume-mount-selection` in commit `7ea70c41f`.
- Preserved external/name-as-is volume protection, clone collision checks, current preview cleanup, audit logging, storage refresh events, and the redesigned storage UI.
- Ported the removed per-volume `Show` component behavior to the current `Storages\\All` component, then accepted deletion of the obsolete component and view.
- Passed: `ExistingVolumeMountTest` (14 tests), `ApplicationPreviewVolumeCleanupTest` (3), `PersistentStorageVolumesLayoutTest` (18), and `StableNestedLivewireKeysTest` (5).
- Known unrelated failure: `tests/v4/Feature/DangerDeleteResourceTest.php` expects synchronous application deletion, but the current `next` behavior leaves it queued; 3 other tests in that file pass.
- GitHub search found related open issues #10204, #5290, #3303, and #3954. No matching recent discussion was found.
