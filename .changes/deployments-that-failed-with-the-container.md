---
type: fixed
commit: d6405d5545
---
Deployments that failed with "The container name ... is already in use" when `Use build secrets` is on ([#9363](https://github.com/coollabsio/coolify/issues/9363))

- Coolify removes the old helper container and waits until Docker frees the name
