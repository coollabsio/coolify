---
type: fixed
---
Skipped GitHub pull request previews left no trace in the UI or the API, while the webhook reported `queued` ([#12544](https://github.com/coollabsio/coolify/issues/12544))

- A skipped preview now adds a deployment with the status `skipped` and the reason: a skip marker, a fork with public PR deployments off, or no changed file in the watch paths
