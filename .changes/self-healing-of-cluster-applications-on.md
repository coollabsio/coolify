---
type: added
---
Self-healing of cluster applications on servers with Sentinel, also while Coolify is offline

- Sentinel keeps the desired applications of its server on disk, restarts a stopped or crashed container, and starts the containers again after a reboot
- A new revision that crash-loops or fails its health check is marked failed, and the previous version keeps running
- Restarts that Sentinel makes alone show in the `Deployments` history of the application
- Servers with an older Sentinel use the previous deployment path
