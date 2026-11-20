---
type: fixed
---
Unhealthy status of Redis databases with an image that has no shell, because the health check ran in a shell

- The health check runs `redis-cli` directly and fails on error replies such as `NOAUTH` and `LOADING` on Redis 6.2 and later
