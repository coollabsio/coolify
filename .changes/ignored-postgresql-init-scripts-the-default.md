---
type: fixed
---
Ignored PostgreSQL init scripts, the default MongoDB database, and file mounts on servers with a strict umask ([#4519](https://github.com/coollabsio/coolify/issues/4519))

- Coolify makes these files readable for the container user after each write
