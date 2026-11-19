---
type: fixed
---
Deployments stuck in progress for up to 24 hours when the worker stopped during the build, which kept the later deployments queued ([#6579](https://github.com/coollabsio/coolify/issues/6579))

- Coolify fails such a deployment when it has no log output for longer than the deployment timeout of the server and 10 minutes, and starts the next queued deployment
