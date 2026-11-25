---
type: added
---
Backup schedules of Docker volumes in `storage_backups` of `coolify.json`, for applications, Compose services, and databases ([#12641](https://github.com/coollabsio/coolify/issues/12641))

- Each schedule is keyed by the volume name. When the file removes a schedule, its archive files stay on the server and in S3, but Coolify no longer lists them, as when you delete a schedule in the dashboard without its backups
- A Compose application gets the schedule of a volume at the first apply after a deployment creates the volume. Until then, the plan shows a warning
- Without `storage_backups`, the file does not manage the schedules, and they stay editable in the dashboard
