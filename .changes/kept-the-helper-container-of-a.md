---
type: fixed
---
Kept the helper container of a failed deployment running on the server ([#12543](https://github.com/coollabsio/coolify/issues/12543))

- A failed deployment queues a cleanup of its helper container on the deployment server and the build server 10 minutes later
