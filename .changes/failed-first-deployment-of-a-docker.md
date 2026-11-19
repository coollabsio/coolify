---
type: fixed
---
Failed first deployment of a Docker Compose application with `Preserve Repository During Deployment` when it mounts a file of the repository

- Coolify checks the repository files after it reads the Compose file, so it also sees the mounts of the first deployment
