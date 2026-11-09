---
type: fixed
---
Services that stopped and stayed down after a `Restart` when an image could not be pulled ([#12485](https://github.com/coollabsio/coolify/issues/12485))

- Coolify pulls the missing images before it stops the containers. If an image cannot be pulled, the restart fails and the service keeps running
