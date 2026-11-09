---
type: fixed
commit: e0b94662e9
---
Ignored concurrent build limit of build servers ([#7649](https://github.com/coollabsio/coolify/issues/7649))

- Each build counts on the server that runs it, and a deployment reserves a build server with a free slot when it starts
- A freed build server slot starts the waiting deployments of other servers
