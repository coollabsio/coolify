# Multi-destination S3 backup planning

- [x] Inventory database and volume backup paths.
- [x] Confirm shared retention across selected destinations.
- [x] Choose normalized schedule pivots and per-execution replica records.
- [x] Write a test-first implementation plan.
- [x] Review the plan for compatibility, authorization, migration, cleanup, and verification coverage.

## Review

- Plan: `docs/superpowers/plans/2026-09-14-multi-destination-s3-backups.md`
- Scope includes database, volume, and directory backups.
- The first release keeps singular fields for backward compatibility.
- Local deletion requires every selected replica to upload successfully.
