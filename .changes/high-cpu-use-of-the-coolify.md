---
type: fixed
---
High CPU use of the Coolify container every minute, because each scheduler process compiled the full PHP app again ([#6285](https://github.com/coollabsio/coolify/issues/6285))

- Short-lived PHP processes reuse compiled code from an opcache file cache, and each long-running PHP process uses about 10 MB less memory
- The cleanup of upload chunks runs hourly instead of every two minutes, and the dispatcher of scheduled backups and tasks starts one PHP process less each minute
- The health check of the Coolify container runs every 10 seconds instead of every 5 seconds
