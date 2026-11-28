---
type: changed
---
Rolling deploys of cluster applications with published ports, which no longer change the other servers of the cluster

- Sentinel on the server switches the port to the new container, also while Coolify is not available. This needs Sentinel with `network.published_ports.local.v1` on every server of the cluster
