---
type: fixed
commit: f21acdc3e4
---
Missing `Resource status changes` notification when an application, preview, service or database stops unexpectedly, for example after a crash or `docker kill` ([#9493](https://github.com/coollabsio/coolify/issues/9493))

- Coolify waits 2 minutes and notifies only when the resource is still stopped
- A stop, restart or redeploy from Coolify sends no notification
- The same resource sends at most one notification in 15 minutes
