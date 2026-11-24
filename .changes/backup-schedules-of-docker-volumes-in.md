---
type: added
---
Backup schedules of Docker volumes in `storage_backups` of `coolify.json`, for applications, Compose services, and databases ([#12641](https://github.com/coollabsio/coolify/issues/12641))

- Each schedule is keyed by the volume name. A removed schedule keeps its backup archives
- Without `storage_backups`, the file does not manage the schedules, and they stay editable in the dashboard
