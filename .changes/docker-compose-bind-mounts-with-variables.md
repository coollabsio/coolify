---
type: fixed
---
Docker Compose bind mounts with variables in the host path that resolved to the wrong path, and file mounts that became directories when the containers started

- Coolify resolves the host path with the environment and interpolation rules of Docker Compose, and writes file mounts before the containers start
