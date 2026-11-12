---
type: added
---
Deploys without downtime of cluster applications without a writable volume

- The new container starts next to the old one, gets the traffic when it is healthy, and the old one stops. When the new container does not get healthy, the old one keeps running
