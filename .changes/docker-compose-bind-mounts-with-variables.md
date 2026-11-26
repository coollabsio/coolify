---
type: fixed
---
Docker Compose bind mounts with variables in the host path that resolved to the wrong path, and file mounts that became directories when the containers started

- Coolify resolves the host path with the environment and interpolation rules of Docker Compose, and writes file mounts before the containers start
- A short volume entry with several mount options, such as `ro,z`, keeps all of its options
- After the first deployment, a bind mount keeps the host path that Docker Compose resolved. When a variable or the Compose file gives another path later, the storage page shows the new path and `Use the new path` switches to it. Coolify does not move the data
