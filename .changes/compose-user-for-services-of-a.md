---
type: added
---
Compose `user:` for services of a stack on a cluster, for templates such as `Authentik`

- A service with `user:` runs as that user when every server of the cluster has Sentinel with `workload.user.v1`. Before, Coolify ignored the key
