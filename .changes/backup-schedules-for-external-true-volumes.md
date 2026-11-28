---
type: added
---
Backup schedules for `external: true` volumes of Docker Compose applications and services ([#12681](https://github.com/coollabsio/coolify/issues/12681))

- The volume gets a storage entry with its Docker volume name after the next deployment, and Coolify never removes the volume. In `coolify.json`, the `storage_backups` key is the Docker volume name
